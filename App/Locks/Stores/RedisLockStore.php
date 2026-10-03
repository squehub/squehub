<?php

declare(strict_types=1);

namespace App\Locks\Stores;

use App\Locks\LockBackendException;
use App\Locks\LockOwnershipException;
use App\Locks\LockStorageException;
use App\Locks\LockStore;
use App\Redis\RedisConnection;
use Throwable;

/** Redis owns the lease TTL; token comparison and deletion are one Lua call. */
final class RedisLockStore implements LockStore
{
    private const RELEASE = <<<'LUA'
local owner = redis.call('GET', KEYS[1])
if not owner then return 0 end
if owner ~= ARGV[1] then return -1 end
return redis.call('DEL', KEYS[1])
LUA;

    public function __construct(private RedisConnection $connection)
    {
    }

    public function acquire(string $hash, string $token, int $now, int $expiresAt): bool
    {
        try {
            return $this->connection->set('locks:' . $hash, $token,
                $expiresAt - $now, true);
        } catch (Throwable) {
            throw new LockBackendException('Lock Redis operation failed.');
        }
    }

    public function release(string $hash, string $token, int $now): bool
    {
        try {
            $reply = $this->connection->script(self::RELEASE, ['locks:' . $hash], [$token]);
        } catch (Throwable) {
            throw new LockBackendException('Lock Redis operation failed.');
        }
        if ($reply === 1 || $reply === '1') return true;
        if ($reply === 0 || $reply === '0') return false;
        if ($reply === -1 || $reply === '-1') {
            throw new LockOwnershipException('Lock belongs to another owner.');
        }
        throw new LockStorageException('Lock Redis reply is invalid.');
    }
}
