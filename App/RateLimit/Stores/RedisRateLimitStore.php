<?php

declare(strict_types=1);

namespace App\RateLimit\Stores;

use App\RateLimit\RateLimitException;
use App\RateLimit\RateLimitResult;
use App\RateLimit\RateLimitStore;
use App\Redis\RedisConnection;
use DateTimeImmutable;
use Throwable;

/**
 * A Redis server serializes the entire fixed-window decision in one Lua
 * command. No PHP read/increment/write gap exists between application servers.
 * Only the RateLimiter's SHA-256 fingerprint reaches the Redis key.
 */
final class RedisRateLimitStore implements RateLimitStore
{
    private const CONSUME = <<<'LUA'
local limit = tonumber(ARGV[1])
local seconds = tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local raw = redis.call('GET', KEYS[1])
local count = 0
local reset = now + seconds
if raw then
    local ok, record = pcall(cjson.decode, raw)
    if not ok or type(record) ~= 'table' or record.version ~= 1
        or type(record.count) ~= 'number' or type(record.limit) ~= 'number'
        or type(record.window_seconds) ~= 'number' or type(record.resets_at) ~= 'number'
        or record.count < 1 or record.count > record.limit + 1
        or record.limit < 1 or record.window_seconds < 1 or record.resets_at < 1 then
        return redis.error_reply('Invalid rate-limit state')
    end
    if now < record.resets_at and record.limit == limit and record.window_seconds == seconds then
        count = record.count
        reset = record.resets_at
    end
end
count = math.min(limit + 1, count + 1)
redis.call('SET', KEYS[1], cjson.encode({version=1,count=count,limit=limit,
    window_seconds=seconds,resets_at=reset}), 'EX', reset - now)
return {count, reset}
LUA;

    public function __construct(private RedisConnection $redis, private string $namespace)
    {
    }

    public function consume(string $fingerprint, int $maxAttempts, int $windowSeconds,
        DateTimeImmutable $now): RateLimitResult
    {
        // Lua numbers are doubles; preserve exact counter arithmetic below
        // 2^53 instead of allowing a policy that can silently round upward.
        if ($maxAttempts < 1 || $maxAttempts > 9007199254740990 || $windowSeconds < 1
            || $now->getTimestamp() > PHP_INT_MAX - $windowSeconds) {
            throw new RateLimitException('Invalid Redis rate-limit policy.');
        }
        try {
            $reply = $this->redis->script(self::CONSUME, [$this->key($fingerprint)],
                [(string) $maxAttempts, (string) $windowSeconds, (string) $now->getTimestamp()]);
            if (!is_array($reply) || count($reply) !== 2
                || !is_numeric($reply[0]) || !is_numeric($reply[1])) {
                throw new RateLimitException('Invalid Redis rate-limit response.');
            }
            $count = (int) $reply[0];
            $reset = (int) $reply[1];
            if ($count < 1 || $count > $maxAttempts + 1 || $reset <= $now->getTimestamp()) {
                throw new RateLimitException('Invalid Redis rate-limit response.');
            }
            $allowed = $count <= $maxAttempts;
            return new RateLimitResult($allowed, $maxAttempts, max(0, $maxAttempts - $count),
                $allowed ? 0 : max(1, $reset - $now->getTimestamp()), new DateTimeImmutable('@' . $reset));
        } catch (Throwable $failure) {
            throw $failure instanceof RateLimitException ? $failure
                : new RateLimitException('Redis rate-limit operation failed.', 0, $failure);
        }
    }

    public function clear(string $fingerprint): bool
    {
        try {
            return $this->redis->delete($this->key($fingerprint));
        } catch (Throwable $failure) {
            throw new RateLimitException('Redis rate-limit clear failed.', 0, $failure);
        }
    }

    private function key(string $fingerprint): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new RateLimitException('Invalid rate-limit fingerprint.');
        }
        return 'rate_limit:' . hash('sha256', $this->namespace) . ':' . $fingerprint;
    }
}
