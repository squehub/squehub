<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\Repository;
use InvalidArgumentException;

/**
 * Application-owned proxy trust and host policy. Only REMOTE_ADDR can establish
 * the first trusted hop; self-declared forwarding headers never establish trust.
 * @internal
 */
final class TrustedProxyPolicy
{
    private const MAX_HEADER_BYTES = 4096;
    private const MAX_HOPS = 32;

    /** @var list<array{network: string, bits: int}> */
    private array $proxies = [];
    /** @var list<string> */
    private array $allowedHosts = [];
    private string $profile;

    public function __construct(Repository $config)
    {
        $options = $config->get('trustedProxies', []);
        if (!is_array($options) || (array_is_list($options) && $options !== [])) {
            throw new InvalidArgumentException('Trusted proxy configuration must be an object.');
        }
        $proxies = $options['proxies'] ?? [];
        $profile = $options['profile'] ?? 'none';
        $hosts = $options['allowed_hosts'] ?? [];
        if (!is_string($profile) || !in_array($profile, ['none', 'forwarded', 'x-forwarded'], true)) {
            throw new InvalidArgumentException('Trusted proxy header profile is invalid.');
        }
        if (!is_array($proxies) || !array_is_list($proxies) || count($proxies) > 64) {
            throw new InvalidArgumentException('Trusted proxies must be a bounded list of IP addresses or CIDRs.');
        }
        if (!is_array($hosts) || !array_is_list($hosts) || count($hosts) > 64) {
            throw new InvalidArgumentException('Allowed hosts must be a bounded list.');
        }
        $this->profile = $profile;
        foreach ($proxies as $proxy) {
            $this->proxies[] = self::network($proxy);
        }
        foreach ($hosts as $host) {
            if (!is_string($host) || $host === '') {
                throw new InvalidArgumentException('Allowed host pattern is invalid.');
            }
            $wildcard = str_starts_with($host, '*.');
            $literal = $wildcard ? substr($host, 2) : $host;
            $normalized = HostAuthority::normalize($literal, false);
            if ($normalized === null || ($wildcard && (!str_contains($normalized, '.')
                || str_starts_with($normalized, '[')))) {
                throw new InvalidArgumentException('Allowed host pattern is invalid.');
            }
            $this->allowedHosts[] = ($wildcard ? '*.' : '') . $normalized;
        }
    }

    public function profile(): string
    {
        return $this->profile;
    }

    /**
     * An empty allowlist adds no restriction, preserving direct development and
     * shared-host deployment. A wildcard covers subdomains, never the apex.
     */
    public function allowsHost(?string $normalizedHost): bool
    {
        if ($this->allowedHosts === []) {
            return true;
        }
        if ($normalizedHost === null) {
            return false;
        }
        foreach ($this->allowedHosts as $allowed) {
            if ($allowed === $normalizedHost) {
                return true;
            }
            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1);
                if (strlen($normalizedHost) > strlen($suffix)
                    && str_ends_with($normalizedHost, $suffix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Returns only validated forwarding overrides. Any malformed selected
     * header rejects the whole profile, leaving direct server values intact.
     *
     * @return array{ip: ?string, host: ?string, scheme: ?string}
     */
    public function resolve(Request $request): array
    {
        $direct = ['ip' => null, 'host' => null, 'scheme' => null];
        $peer = $request->server('REMOTE_ADDR');
        if ($this->profile === 'none' || !is_string($peer) || !$this->trusts($peer)) {
            return $direct;
        }
        $fields = $this->profile === 'forwarded'
            ? $this->forwarded($request, $peer) : $this->xForwarded($request);
        if ($fields === null) {
            return $direct;
        }
        return [
            'ip' => $this->clientFromChain($peer, $fields['chain']),
            'host' => $fields['host'],
            'scheme' => $fields['scheme'],
        ];
    }

    /** @return array{network: string, bits: int} */
    private static function network(mixed $value): array
    {
        if (!is_string($value) || $value === '' || strlen($value) > 80
            || substr_count($value, '/') > 1) {
            throw new InvalidArgumentException('Trusted proxy entry must be an IP address or CIDR.');
        }
        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        $network = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        if ($network === false) {
            throw new InvalidArgumentException('Trusted proxy entry contains an invalid IP address.');
        }
        $maximum = strlen($network) * 8;
        if ($prefix === null) {
            $bits = $maximum;
        } elseif (preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $prefix) !== 1
            || (int) $prefix > $maximum || (int) $prefix === 0) {
            // A universal CIDR silently makes every direct client a proxy.
            throw new InvalidArgumentException('Trusted proxy CIDR prefix is invalid or universal.');
        } else {
            $bits = (int) $prefix;
        }
        return ['network' => $network, 'bits' => $bits];
    }

    private function trusts(string $ip): bool
    {
        $address = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        if ($address === false) {
            return false;
        }
        foreach ($this->proxies as $proxy) {
            $network = $proxy['network'];
            if (strlen($address) !== strlen($network)) {
                continue;
            }
            $bytes = intdiv($proxy['bits'], 8);
            $remaining = $proxy['bits'] % 8;
            if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            if ($remaining === 0 || ((ord($address[$bytes]) ^ ord($network[$bytes]))
                & (0xff << (8 - $remaining))) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $chain */
    private function clientFromChain(string $peer, array $chain): ?string
    {
        if ($chain === []) {
            return null;
        }
        $candidate = $peer;
        // The nearest hop is authoritative only while each current hop is
        // explicitly trusted. An untrusted intermediary terminates the walk.
        for ($index = count($chain) - 1; $index >= 0; $index--) {
            if (!$this->trusts($candidate)) {
                break;
            }
            $candidate = $chain[$index];
        }
        return $candidate;
    }

    /** @return array{chain: list<string>, host: ?string, scheme: ?string}|null */
    private function xForwarded(Request $request): ?array
    {
        foreach (['X-Forwarded-For', 'X-Forwarded-Proto', 'X-Forwarded-Host'] as $name) {
            if ($request->headerRepeated($name)) {
                return null;
            }
            $value = $request->header($name);
            if ($value !== null && (!is_string($value) || strlen($value) > self::MAX_HEADER_BYTES
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)) {
                return null;
            }
        }
        $chain = [];
        $for = $request->header('X-Forwarded-For');
        if ($for !== null) {
            $parts = explode(',', $for);
            if (count($parts) > self::MAX_HOPS) {
                return null;
            }
            foreach ($parts as $part) {
                $ip = self::ipToken(trim($part, " \t"));
                if ($ip === null) {
                    return null;
                }
                $chain[] = $ip;
            }
        }
        $rawScheme = $request->header('X-Forwarded-Proto');
        $rawHost = $request->header('X-Forwarded-Host');
        $scheme = $rawScheme === null ? null : self::httpScheme(trim($rawScheme, " \t"));
        $host = $rawHost === null ? null : trim($rawHost, " \t");
        if (($rawScheme !== null && $scheme === null)
            || ($host !== null && HostAuthority::normalize($host) === null)) {
            return null;
        }
        return ['chain' => $chain, 'host' => $host, 'scheme' => $scheme];
    }

    /** @return array{chain: list<string>, host: ?string, scheme: ?string}|null */
    private function forwarded(Request $request, string $peer): ?array
    {
        if ($request->headerRepeated('Forwarded')) {
            return null;
        }
        $raw = $request->header('Forwarded');
        if ($raw === null) {
            return ['chain' => [], 'host' => null, 'scheme' => null];
        }
        if (!is_string($raw) || strlen($raw) > self::MAX_HEADER_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            return null;
        }
        $elements = self::splitQuoted($raw, ',', self::MAX_HOPS);
        if ($elements === null) {
            return null;
        }
        $chain = [];
        $elementsByHop = [];
        foreach ($elements as $element) {
            $pieces = self::splitQuoted($element, ';', 16);
            if ($pieces === null) {
                return null;
            }
            $parameters = [];
            foreach ($pieces as $piece) {
                $equal = strpos($piece, '=');
                if ($equal === false) {
                    return null;
                }
                $name = strtolower(trim(substr($piece, 0, $equal), " \t"));
                if (preg_match('/\A[a-z]+\z/D', $name) !== 1 || isset($parameters[$name])) {
                    return null;
                }
                $value = self::parameterValue(trim(substr($piece, $equal + 1), " \t"));
                if ($value === null) {
                    return null;
                }
                $parameters[$name] = $value;
            }
            // Unknown/obfuscated for= identifiers cannot satisfy an IP contract.
            $ip = isset($parameters['for']) ? self::ipToken($parameters['for']) : null;
            if ($ip === null) {
                return null;
            }
            $scheme = isset($parameters['proto']) ? self::httpScheme($parameters['proto']) : null;
            $host = $parameters['host'] ?? null;
            if ((isset($parameters['proto']) && $scheme === null)
                || ($host !== null && HostAuthority::normalize($host) === null)) {
                return null;
            }
            $chain[] = $ip;
            $elementsByHop[] = ['host' => $host, 'scheme' => $scheme];
        }
        $boundary = count($chain) - 1;
        $candidate = $peer;
        for ($index = $boundary; $index >= 0; $index--) {
            if (!$this->trusts($candidate)) {
                break;
            }
            $candidate = $chain[$index];
            $boundary = $index;
        }
        // Each RFC element was written by the proxy at that hop. Use the
        // nearest element at the trusted boundary, never an earlier element
        // supplied through an untrusted intermediary.
        return ['chain' => $chain, 'host' => $elementsByHop[$boundary]['host'],
            'scheme' => $elementsByHop[$boundary]['scheme']];
    }

    private static function httpScheme(string $value): ?string
    {
        $scheme = strtolower($value);
        return in_array($scheme, ['http', 'https'], true) ? $scheme : null;
    }

    private static function ipToken(string $value): ?string
    {
        if ($value === '' || strlen($value) > 80
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            return null;
        }
        if (preg_match('/\A\[([^\]]+)\](?::([0-9]{1,5}))?\z/D', $value, $match) === 1) {
            if (!filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                || (isset($match[2]) && ((int) $match[2] < 1 || (int) $match[2] > 65535))) {
                return null;
            }
            $value = $match[1];
        } elseif (preg_match('/\A([0-9.]+):([0-9]{1,5})\z/D', $value, $match) === 1) {
            if ((int) $match[2] < 1 || (int) $match[2] > 65535) {
                return null;
            }
            $value = $match[1];
        }
        $packed = filter_var($value, FILTER_VALIDATE_IP) === false ? false : inet_pton($value);
        return $packed === false ? null : inet_ntop($packed);
    }

    /** Split only outside RFC quoted strings; malformed quoting rejects all fields. */
    private static function splitQuoted(string $raw, string $separator, int $limit): ?array
    {
        $parts = [];
        $part = '';
        $quoted = false;
        $escaped = false;
        foreach (str_split($raw) as $character) {
            if ($escaped) {
                $part .= $character;
                $escaped = false;
                continue;
            }
            if ($quoted && $character === '\\') {
                $part .= $character;
                $escaped = true;
                continue;
            }
            if ($character === '"') {
                $quoted = !$quoted;
            } elseif (!$quoted && $character === '\\') {
                return null;
            }
            if (!$quoted && $character === $separator) {
                $piece = trim($part, " \t");
                if ($piece === '' || count($parts) >= $limit - 1) {
                    return null;
                }
                $parts[] = $piece;
                $part = '';
            } else {
                $part .= $character;
            }
        }
        $part = trim($part, " \t");
        if ($quoted || $escaped || $part === '') {
            return null;
        }
        $parts[] = $part;
        return $parts;
    }

    private static function parameterValue(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        if ($raw[0] !== '"') {
            return preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z:\[\]-]+\z/D', $raw) === 1
                ? $raw : null;
        }
        if (strlen($raw) < 2 || !str_ends_with($raw, '"')) {
            return null;
        }
        $value = '';
        $body = substr($raw, 1, -1);
        for ($index = 0, $length = strlen($body); $index < $length; $index++) {
            $character = $body[$index];
            if ($character === '\\') {
                if (++$index >= $length || !in_array($body[$index], ['\\', '"'], true)) {
                    return null;
                }
                $character = $body[$index];
            } elseif ($character === '"') {
                return null;
            }
            $value .= $character;
        }
        return $value === '' ? null : $value;
    }
}
