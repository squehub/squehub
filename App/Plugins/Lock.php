<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Locks\Lock as LockGateway;
use App\Locks\LockHandle;
use App\Locks\LockManager;

/** One public Lock API, backed by the current Application's manager. */
final class Lock
{
    public static function manager(): LockManager { return LockGateway::manager(); }

    public static function acquire(string $name, int $ttl = 30, float $wait = 0.0): LockHandle
    {
        return self::manager()->acquire($name, $ttl, $wait);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function run(string $name, callable $callback, int $ttl = 30, float $wait = 0.0): mixed
    {
        return self::manager()->run($name, $callback, $ttl, $wait);
    }
}
