<?php

declare(strict_types=1);

namespace App\Redis\Clients;

use App\Redis\RedisClient;

/** Optional Predis transport; construction is lazy until the first command. */
final class PredisClient implements RedisClient
{
    private object $client;

    /** @param array<string,mixed> $settings Normalized, validated connection settings. */
    public function __construct(#[\SensitiveParameter] array $settings)
    {
        $parameters = [
            'scheme' => $settings['tls'] ? 'tls' : 'tcp',
            'host' => $settings['host'], 'port' => $settings['port'],
            'database' => $settings['database'], 'timeout' => $settings['connect_timeout'],
            'read_write_timeout' => $settings['read_timeout'],
        ];
        if ($settings['username'] !== null) $parameters['username'] = $settings['username'];
        if ($settings['password'] !== null) $parameters['password'] = $settings['password'];
        if ($settings['tls']) $parameters['ssl'] = [
            'verify_peer' => true, 'verify_peer_name' => true,
        ];
        // Optional Predis is checked by RedisManager before this adapter is built.
        $this->client = (new \ReflectionClass('Predis\\Client'))->newInstance($parameters);
    }

    public function execute(array $arguments): mixed
    {
        $reply = $this->client->executeRaw($arguments);
        // Predis represents simple-string replies such as OK and PONG with a
        // status object. Normalize only that shape; binary bulk strings stay
        // untouched.
        return $reply instanceof \Stringable ? (string) $reply : $reply;
    }

    public function close(): void
    {
        $this->client->disconnect();
    }

    public function __debugInfo(): array
    {
        return ['client' => 'predis'];
    }
}
