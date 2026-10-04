<?php

declare(strict_types=1);

namespace App\Routing;

use App\Http\Request;
use App\Http\HostAuthority;
use InvalidArgumentException;

/**
 * Compiles route structure at registration and matches bounded path/host values.
 * Request values are decoded once and never interpolated into regex source.
 */
final class RoutePattern
{
    /** @var list<array{name: ?string, literal: string, optional: bool}> */
    private array $segments = [];

    /** @var list<array{name: ?string, literal: string}> */
    private array $hostLabels = [];

    /** @var array<string, string> Precompiled, anchored PCRE fragments. */
    private array $constraints = [];

    /** @var array<string, string> Original validated declarations for safe cache replay. */
    private array $rawConstraints = [];

    /** @var list<int> */
    private array $priority = [];

    private int $hostPriority = 0;

    public function __construct(private string $uri, private ?string $host = null, private bool $fallback = false)
    {
        $optional = false;
        $names = [];
        foreach ($uri === '/' ? [] : explode('/', trim($uri, '/')) as $segment) {
            if (preg_match('/\A\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}\z/D', $segment, $match) === 1) {
                $name = $match[1];
                $isOptional = isset($match[2]) && $match[2] === '?';
                if ($fallback || isset($names[$name])) {
                    throw new InvalidArgumentException('Fallback paths must be static and route parameter names must be unique.');
                }
                $names[$name] = true;
                $optional = $optional || $isOptional;
                if ($optional && !$isOptional) {
                    throw new InvalidArgumentException('Optional route parameters must occupy trailing path segments.');
                }
                $this->segments[] = ['name' => $name, 'literal' => '', 'optional' => $isOptional];
                $this->priority[] = $isOptional ? 0 : 1;
            } else {
                if ($optional || str_contains($segment, '{') || str_contains($segment, '}')) {
                    throw new InvalidArgumentException('Route parameters must occupy a whole segment; optional parameters must be trailing.');
                }
                $this->segments[] = ['name' => null, 'literal' => $segment, 'optional' => false];
                $this->priority[] = 3;
            }
        }

        if ($host !== null) {
            $this->parseHostPattern($host, $names);
        }
    }

    public function host(): ?string { return $this->host; }

    /** @return array<string,string> */
    public function rawConstraints(): array { return $this->rawConstraints; }

    public function hasOptionalPath(): bool
    {
        foreach ($this->segments as $segment) {
            if ($segment['optional']) {
                return true;
            }
        }
        return false;
    }

    public function hasParameter(string $name): bool
    {
        foreach ($this->segments as $segment) {
            if ($segment['name'] === $name) {
                return true;
            }
        }
        foreach ($this->hostLabels as $label) {
            if ($label['name'] === $name) {
                return true;
            }
        }
        return false;
    }

    /** @return list<int> */
    public function priority(): array
    {
        $rank = $this->priority;
        foreach ($rank as $index => $value) {
            if ($value === 1 && isset($this->constraints[$this->segments[$index]['name'] ?? ''])) {
                $rank[$index] = 2;
            }
        }
        return $rank;
    }

    public function hostPriority(): int
    {
        if ($this->hostLabels === []) {
            return $this->hostPriority;
        }
        $constrained = 0;
        foreach ($this->hostLabels as $label) {
            if ($label['name'] !== null && isset($this->constraints[$label['name']])) {
                $constrained++;
            }
        }
        return $this->hostPriority + $constrained;
    }

    /** A constraint is a named ASCII preset or a bounded PCRE fragment. */
    public function constrain(string $name, string $pattern): void
    {
        if (!$this->hasParameter($name)) {
            throw new InvalidArgumentException("Constraint must name a parameter in this route or host: '{$name}'.");
        }
        if (isset($this->constraints[$name])) {
            throw new InvalidArgumentException("Route parameter '{$name}' already has a constraint.");
        }
        $preset = [
            'integer' => '[0-9]+',
            'numeric' => '[0-9]+',
            'alpha' => '[A-Za-z]+',
            'alphanumeric' => '[A-Za-z0-9]+',
            'slug' => '[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*',
            'uuid' => '[0-9A-Fa-f]{8}-(?:[0-9A-Fa-f]{4}-){3}[0-9A-Fa-f]{12}',
        ];
        $fragment = $preset[$pattern] ?? $pattern;
        if ($fragment === '' || strlen($fragment) > 256 || str_contains($fragment, '~')
            || preg_match('/[\x00-\x1F\x7F]/', $fragment) === 1) {
            throw new InvalidArgumentException('Route constraint is empty, too long, or contains an unsupported character.');
        }
        // The application owns the fragment. Compile now, before request traffic.
        $compiled = '~(*LIMIT_MATCH=100000)\A(?:' . $fragment . ')\z~Du';
        if (@preg_match($compiled, '') === false) {
            throw new InvalidArgumentException("Route constraint for '{$name}' is not a valid regular expression.");
        }
        $this->constraints[$name] = $compiled;
        $this->rawConstraints[$name] = $pattern;
    }

    /** @return array<string, string>|null */
    public function match(string $path, ?string $requestHost): ?array
    {
        $hostParameters = $this->matchHost($requestHost);
        if ($hostParameters === null) {
            return null;
        }
        if ($this->fallback) {
            if ($this->uri !== '/' && $path !== $this->uri && !str_starts_with($path, $this->uri . '/')) {
                return null;
            }
            return $hostParameters;
        }
        $parts = $path === '/' ? [] : explode('/', trim($path, '/'));
        $minimum = count(array_filter($this->segments, static fn (array $segment): bool => !$segment['optional']));
        if (count($parts) < $minimum || count($parts) > count($this->segments)) {
            return null;
        }
        $parameters = $hostParameters;
        foreach ($parts as $index => $raw) {
            $segment = $this->segments[$index];
            if ($segment['name'] === null) {
                if ($raw !== $segment['literal']) {
                    return null;
                }
                continue;
            }
            $value = self::decodePathValue($raw);
            if ($value === null || !$this->satisfies($segment['name'], $value)) {
                return null;
            }
            $parameters[$segment['name']] = $value;
        }
        return $parameters;
    }

    /** Malformed Host values never become localhost or satisfy a host route. */
    public static function requestHost(Request $request): ?string
    {
        $raw = $request->effectiveAuthorityHeader();
        return $raw === null ? null : HostAuthority::normalize($raw);
    }

    public static function safePathValue(mixed $value): ?string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }
        $text = (string) $value;
        return self::validPathValue($text) ? $text : null;
    }

    /**
     * Apply the parameter decoder's safety boundary to every mounted path
     * segment, including paths a fallback would otherwise accept verbatim.
     * Validation never replaces the raw bytes later consumed by the matcher.
     */
    public static function safeRequestPath(string $path): bool
    {
        if ($path === '/') return true;
        if ($path === '' || strlen($path) > 24576 || $path[0] !== '/'
            || str_contains($path, '//')) return false;
        foreach (explode('/', trim($path, '/')) as $segment) {
            if (self::decodePathValue($segment) === null) return false;
        }
        return true;
    }

    public function satisfies(string $name, string $value): bool
    {
        return !isset($this->constraints[$name]) || @preg_match($this->constraints[$name], $value) === 1;
    }

    private static function decodePathValue(string $raw): ?string
    {
        if (strlen($raw) > 24576 || preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
            return null;
        }
        $value = rawurldecode($raw);
        return self::validPathValue($value) ? $value : null;
    }

    private static function validPathValue(string $value): bool
    {
        return $value !== '' && strlen($value) <= 8192 && !in_array($value, ['.', '..'], true)
            && !str_contains($value, '/') && !str_contains($value, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && preg_match('//u', $value) === 1;
    }

    /** @param array<string, bool> $pathNames */
    private function parseHostPattern(string $host, array $pathNames): void
    {
        $normalized = self::normalizeHost($host, false);
        if ($normalized !== null) {
            $this->host = $normalized;
            $this->hostPriority = 1000;
            return;
        }
        if (strlen($host) > 253 || !str_contains($host, '{') || str_contains($host, ':')) {
            throw new InvalidArgumentException('Route host pattern is invalid.');
        }
        $names = $pathNames;
        foreach (explode('.', $host) as $rawLabel) {
            $label = strtolower($rawLabel);
            if (preg_match('/\A\{([a-z_][a-z0-9_]*)\}\z/D', $rawLabel, $match) === 1) {
                if (isset($names[$match[1]])) {
                    throw new InvalidArgumentException("Duplicate route parameter '{$match[1]}' across host and path.");
                }
                $names[$match[1]] = true;
                $this->hostLabels[] = ['name' => $match[1], 'literal' => ''];
            } elseif (self::validDnsLabel($label)) {
                $this->hostLabels[] = ['name' => null, 'literal' => $label];
            } else {
                throw new InvalidArgumentException('Route host pattern contains an invalid label.');
            }
        }
        if (!array_filter($this->hostLabels, static fn (array $label): bool => $label['name'] !== null)) {
            throw new InvalidArgumentException('Route host pattern is invalid.');
        }
        $this->host = strtolower($host);
        $this->hostPriority = 100;
    }

    /** @return array<string, string>|null */
    private function matchHost(?string $host): ?array
    {
        if ($this->host === null) {
            return [];
        }
        if ($host === null) {
            return null;
        }
        if ($this->hostLabels === []) {
            return $host === $this->host ? [] : null;
        }
        $parts = explode('.', $host);
        if (count($parts) !== count($this->hostLabels)) {
            return null;
        }
        $parameters = [];
        foreach ($this->hostLabels as $index => $label) {
            $value = $parts[$index];
            if ($label['name'] === null) {
                if ($value !== $label['literal']) {
                    return null;
                }
            } elseif (!self::validDnsLabel($value) || !$this->satisfies($label['name'], $value)) {
                return null;
            } else {
                $parameters[$label['name']] = $value;
            }
        }
        return $parameters;
    }

    private static function normalizeHost(string $host, bool $allowPort): ?string
    {
        return HostAuthority::normalize($host, $allowPort);
    }

    private static function validDnsLabel(string $label): bool
    {
        return strlen($label) <= 63 && preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label) === 1;
    }
}
