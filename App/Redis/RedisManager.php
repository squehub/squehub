<?php

declare(strict_types=1);

namespace App\Redis;

use App\Diagnostics\Diagnostics;
use App\Redis\Clients\PhpRedisClient;
use App\Redis\Clients\PredisClient;
use Closure;
use Throwable;

/**
 * Owns one application's named logical connections. Syntax is checked without
 * opening sockets; client selection and reachability are separate questions.
 */
final class RedisManager
{
    private string $default;
    /** @var array<string,array<string,mixed>> */
    private array $settings = [];
    /** @var array<string,RedisConnection> */
    private array $resolved = [];
    /** @var array<string,string|null> Client choice is fixed for this Application lifetime. */
    private array $selectedClients = [];
    /** @var Closure(string,array<string,mixed>):RedisClient|null */
    private ?Closure $clientFactory;
    /** @var Closure(string):bool|null */
    private ?Closure $clientAvailability;

    /** @param array<string,mixed> $configuration */
    public function __construct(#[\SensitiveParameter] array $configuration,
        private ?Diagnostics $diagnostics = null, ?Closure $clientFactory = null,
        ?Closure $clientAvailability = null)
    {
        $default = $configuration['default'] ?? null;
        $client = self::optional($configuration['client'] ?? null) ?? 'auto';
        $connections = $configuration['connections'] ?? null;
        if (!is_string($default) || !is_string($client) || !is_array($connections)
            || !in_array($client, ['auto', 'phpredis', 'predis'], true)) {
            throw new RedisConfigurationException('Invalid Redis manager configuration.');
        }
        self::validateName($default);
        foreach ($connections as $name => $values) {
            if (!is_string($name) || !is_array($values)) {
                throw new RedisConfigurationException('Invalid named Redis connection.');
            }
            self::validateName($name);
            $this->settings[$name] = self::normalize($values, $client);
        }
        if (!isset($this->settings[$default])) {
            throw new RedisConfigurationException('Default Redis connection is undefined.');
        }
        $this->default = $default;
        $this->clientFactory = $clientFactory;
        $this->clientAvailability = $clientAvailability;
    }

    public function connection(?string $name = null): RedisConnection
    {
        $name ??= $this->default;
        if (!isset($this->settings[$name])) {
            throw new RedisConfigurationException('Unknown Redis connection.');
        }
        return $this->resolved[$name] ??= new RedisConnection(
            $this->settings[$name],
            fn (): RedisClient => $this->createClient($this->settings[$name]),
            $this->diagnostics
        );
    }

    /**
     * A non-probed result never claims server availability. Probe is intentional
     * network I/O, useful for health tooling but never part of Application boot.
     *
     * @return array{status:string,client:?string}
     */
    public function capability(?string $name = null, bool $probe = false): array
    {
        $name ??= $this->default;
        if (!isset($this->settings[$name])) {
            throw new RedisConfigurationException('Unknown Redis connection.');
        }
        $settings = $this->settings[$name];
        if (!$settings['configured']) return ['status' => 'not_configured', 'client' => null];
        $client = $this->selectClient($settings['client']);
        if ($client === null) return ['status' => 'client_unavailable', 'client' => null];
        if (!$probe) return ['status' => 'not_probed', 'client' => $client];
        try {
            $this->connection($name)->ping();
            return ['status' => 'available', 'client' => $client];
        } catch (RedisException) {
            return ['status' => 'unreachable', 'client' => $client];
        }
    }

    /** Explicitly probes the server; callers should use capability() for details. */
    public function available(?string $name = null): bool
    {
        return $this->capability($name, true)['status'] === 'available';
    }

    /** Manager debug output never publishes its credential-bearing config map. */
    public function __debugInfo(): array
    {
        return ['default' => $this->default, 'connection_count' => count($this->settings)];
    }

    /** @param array<string,mixed> $settings */
    private function createClient(#[\SensitiveParameter] array $settings): RedisClient
    {
        if (!$settings['configured']) {
            throw new RedisConfigurationException('Redis connection is not configured.');
        }
        $client = $this->selectClient($settings['client']);
        if ($client === null) throw new RedisConfigurationException('Requested Redis client is unavailable.');
        try {
            if ($this->clientFactory !== null) return ($this->clientFactory)($client, $settings);
            return $client === 'phpredis' ? new PhpRedisClient($settings) : new PredisClient($settings);
        } catch (Throwable) {
            // Vendor exceptions can include credential-bearing URLs or AUTH text.
            throw new RedisException('Redis connection failed.');
        }
    }

    private function selectClient(string $requested): ?string
    {
        if (array_key_exists($requested, $this->selectedClients)) {
            return $this->selectedClients[$requested];
        }
        $available = $this->clientAvailability ?? static fn (string $name): bool => class_exists($name);
        if ($requested === 'phpredis') {
            return $this->selectedClients[$requested] = $available(\Redis::class) ? 'phpredis' : null;
        }
        if ($requested === 'predis') {
            return $this->selectedClients[$requested] = $available('Predis\\Client') ? 'predis' : null;
        }
        return $this->selectedClients[$requested] = $available(\Redis::class)
            ? 'phpredis' : ($available('Predis\\Client') ? 'predis' : null);
    }

    private static function validateName(string $name): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,63}$/D', $name)) {
            throw new RedisConfigurationException('Invalid Redis connection name.');
        }
    }

    /**
     * A URL owns all connection coordinates. Mixing URL and host/auth/database
     * fields is rejected so credentials cannot be silently overridden.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function normalize(#[\SensitiveParameter] array $values, string $defaultClient): array
    {
        $allowed = ['client', 'url', 'host', 'port', 'username', 'password', 'database',
            'connect_timeout', 'read_timeout', 'tls', 'prefix'];
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw new RedisConfigurationException('Unknown Redis connection setting.');
        }
        $client = self::optional($values['client'] ?? null) ?? $defaultClient;
        if (!is_string($client) || !in_array($client, ['auto', 'phpredis', 'predis'], true)) {
            throw new RedisConfigurationException('Invalid Redis client selection.');
        }
        $url = self::optional($values['url'] ?? null);
        $host = self::optional($values['host'] ?? null);
        $username = self::optional($values['username'] ?? null);
        $password = self::optional($values['password'] ?? null);
        $database = self::optional($values['database'] ?? null) ?? 0;
        $port = self::optional($values['port'] ?? null) ?? 6379;
        $tls = self::optional($values['tls'] ?? null);
        if ($url !== null) {
            if (!is_string($url) || $host !== null || $username !== null || $password !== null
                || array_key_exists('database', $values) || array_key_exists('port', $values)
                || $tls !== null) {
                throw new RedisConfigurationException('Redis URL conflicts with individual connection settings.');
            }
            try {
                $parts = parse_url($url);
            } catch (\ValueError) {
                throw new RedisConfigurationException('Invalid Redis connection URL.');
            }
            if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['redis', 'rediss'], true)
                || !isset($parts['host']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new RedisConfigurationException('Invalid Redis connection URL.');
            }
            $host = $parts['host'];
            $port = $parts['port'] ?? 6379;
            $username = isset($parts['user']) ? self::optional(rawurldecode($parts['user'])) : null;
            $password = isset($parts['pass']) ? self::optional(rawurldecode($parts['pass'])) : null;
            $path = $parts['path'] ?? '';
            if ($path !== '' && !preg_match('~^/[0-9]+$~D', $path)) {
                throw new RedisConfigurationException('Invalid Redis URL database.');
            }
            $database = $path === '' ? 0 : substr($path, 1);
            $tls = $parts['scheme'] === 'rediss';
        }
        if ($host !== null && (!is_string($host) || strlen($host) > 255
            || !preg_match('/^[A-Za-z0-9.:-]+$/D', $host))) {
            throw new RedisConfigurationException('Invalid Redis host.');
        }
        if ($host === null && ($username !== null || $password !== null)) {
            throw new RedisConfigurationException('Redis credentials require a host.');
        }
        if ($username !== null && (!is_string($username) || $password === null)) {
            throw new RedisConfigurationException('Redis ACL username requires a password.');
        }
        if ($password !== null && !is_string($password)) {
            throw new RedisConfigurationException('Invalid Redis password.');
        }
        $port = self::integer($port, 1, 65535, 'port');
        $database = self::integer($database, 0, 2147483647, 'database');
        $connectTimeout = self::timeout(self::optional($values['connect_timeout'] ?? null) ?? 5,
            'connect timeout');
        $readTimeout = self::timeout(self::optional($values['read_timeout'] ?? null) ?? 5,
            'read timeout');
        if ($tls !== null && !is_bool($tls)) {
            if (is_string($tls) && in_array(strtolower($tls),
                ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true)) {
                $tls = in_array(strtolower($tls), ['true', '1', 'yes', 'on'], true);
            } else throw new RedisConfigurationException('Invalid Redis TLS setting.');
        }
        $prefix = $values['prefix'] ?? '';
        if (!is_string($prefix) || strlen($prefix) > 128
            || ($prefix !== '' && !preg_match('/^[A-Za-z0-9:._-]+$/D', $prefix))) {
            throw new RedisConfigurationException('Invalid Redis key prefix.');
        }
        return ['client' => $client, 'configured' => $host !== null,
            'host' => $host, 'port' => $port, 'username' => $username,
            'password' => $password, 'database' => $database,
            'connect_timeout' => $connectTimeout, 'read_timeout' => $readTimeout,
            'tls' => $tls ?? false, 'prefix' => $prefix];
    }

    private static function optional(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    private static function integer(mixed $value, int $min, int $max, string $field): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || (int) $value < $min || (int) $value > $max) {
            throw new RedisConfigurationException('Invalid Redis ' . $field . '.');
        }
        return (int) $value;
    }

    private static function timeout(mixed $value, string $field): float
    {
        if (!is_numeric($value) || !is_finite((float) $value)
            || (float) $value <= 0 || (float) $value > 60) {
            throw new RedisConfigurationException('Invalid Redis ' . $field . '.');
        }
        return (float) $value;
    }
}
