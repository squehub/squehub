<?php

declare(strict_types=1);

namespace App\Redis\Clients;

use App\Redis\RedisClient;

/** A non-persistent PhpRedis socket with explicit TLS verification and ACL auth. */
final class PhpRedisClient implements RedisClient
{
    private object $client;

    /** @param array<string,mixed> $settings Normalized, validated connection settings. */
    public function __construct(#[\SensitiveParameter] array $settings)
    {
        $client = new \Redis();
        $host = ($settings['tls'] ? 'tls://' : '') . $settings['host'];
        $context = $settings['tls'] ? ['stream' => [
            'verify_peer' => true, 'verify_peer_name' => true,
        ]] : [];
        if (!$client->connect($host, $settings['port'], $settings['connect_timeout'],
            null, 0, $settings['read_timeout'], $context)) {
            throw new \RuntimeException('Redis socket connection failed.');
        }
        if ($settings['password'] !== null) {
            $auth = $settings['username'] === null ? $settings['password']
                : [$settings['username'], $settings['password']];
            if (!$client->auth($auth)) throw new \RuntimeException('Redis authentication failed.');
        }
        if ($settings['database'] !== 0 && !$client->select($settings['database'])) {
            throw new \RuntimeException('Redis database selection failed.');
        }
        $this->client = $client;
    }

    public function execute(array $arguments): mixed
    {
        // A NIL reply can also be represented as false. Clear and inspect the
        // extension's last error so a command failure is not mistaken for a
        // missing key or a legitimate failed SET NX condition.
        $this->client->clearLastError();
        $reply = $this->client->rawCommand(...$arguments);
        if ($reply === false && $this->client->getLastError() !== null) {
            throw new \RuntimeException('Redis command failed.');
        }
        return $reply;
    }

    public function close(): void
    {
        $this->client->close();
    }

    public function __debugInfo(): array
    {
        return ['client' => 'phpredis'];
    }
}
