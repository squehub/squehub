<?php

declare(strict_types=1);

namespace App\Queue;

use App\Redis\RedisConnection;
use App\Redis\RedisException;

/**
 * A shared generation marker lets workers on different hosts finish their
 * current attempt and exit. The value is opaque and contains no job data.
 */
final class RedisRestartSignal
{
    public function __construct(private RedisConnection $redis, private string $key)
    {
    }

    public function current(): string
    {
        try {
            $value = $this->redis->get($this->key);
        } catch (RedisException $exception) {
            throw new QueueException('Redis Queue restart marker is unavailable.', 0, $exception);
        }
        if ($value === null) return '';
        if (preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
            throw new QueueException('Redis Queue restart marker is invalid.');
        }
        return $value;
    }

    public function mark(): void
    {
        try {
            $this->redis->set($this->key, bin2hex(random_bytes(16)));
        } catch (RedisException $exception) {
            throw new QueueException('Redis Queue restart marker could not be written.', 0, $exception);
        }
    }
}
