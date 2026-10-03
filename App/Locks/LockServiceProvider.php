<?php

declare(strict_types=1);

namespace App\Locks;

use App\Container\Container;
use App\Database\DatabaseManager;
use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\Foundation\Application;
use App\Foundation\ServiceProvider;
use App\Locks\Stores\ArrayLockStore;
use App\Locks\Stores\DatabaseLockStore;
use App\Locks\Stores\FileLockStore;
use App\Locks\Stores\RedisLockStore;
use App\Redis\InfrastructureSelection;
use App\Redis\RedisManager;
use Throwable;

/** Validates settings at boot; no file, PDO, or Redis I/O until lock use. */
final class LockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(LockManager::class,
            static function (Container $container) use ($app): LockManager {
                [$driver, $prefix, $path, $redisName, $databaseName, $required] = self::settings($app);
                $namespace = $prefix ?? $app->basePath();
                $clock = $container->has(ModelClock::class)
                    ? $container->make(ModelClock::class)
                    : ($container->has(DatabaseManager::class)
                        ? $container->make(DatabaseManager::class)->clock() : new SystemModelClock());
                $selection = new InfrastructureSelection($driver, 'file',
                    $container->has(RedisManager::class) ? $container->make(RedisManager::class) : null,
                    $redisName);
                try {
                    $selected = $selection->resolve();
                } catch (Throwable) {
                    throw new LockBackendException('Lock backend selection failed.');
                }
                if (($selected === 'redis' || ($selected === 'database'
                    && self::databaseDriver($app, $databaseName) === 'mysql'))
                    && $prefix === null) {
                    // Different deployment paths must not silently partition
                    // what the caller expects to be one distributed namespace.
                    throw new LockConfigurationException('Shared lock backend requires an explicit stable prefix.');
                }
                if ($selected === 'file') {
                    $store = new FileLockStore($path ?? $app->basePath('Storage/Locks'), $namespace);
                } elseif ($selected === 'array') {
                    $store = new ArrayLockStore();
                } elseif ($selected === 'database') {
                    if (!$container->has(DatabaseManager::class)) {
                        throw new LockConfigurationException('Lock database provider is unavailable.');
                    }
                    try {
                        $connection = $container->make(DatabaseManager::class)->connection($databaseName);
                    } catch (Throwable) {
                        throw new LockConfigurationException('Lock database connection is invalid.');
                    }
                    $store = new DatabaseLockStore($connection);
                } elseif ($selected === 'redis') {
                    if (!$container->has(RedisManager::class)) {
                        throw new LockConfigurationException('Lock Redis provider is unavailable.');
                    }
                    try {
                        $connection = $container->make(RedisManager::class)->connection($redisName);
                    } catch (Throwable) {
                        throw new LockConfigurationException('Lock Redis connection is invalid.');
                    }
                    $store = new RedisLockStore($connection);
                } else {
                    throw new LockConfigurationException('Lock backend is unsupported.');
                }
                $distributed = $selected === 'redis' || ($selected === 'database'
                    && self::databaseDriver($app, $databaseName) === 'mysql');
                if ($required && !$distributed) {
                    throw new LockConfigurationException('A distributed lock backend is required.');
                }
                return new LockManager($store, $namespace, $clock, $selected, $distributed, $selection);
            });
    }

    public function boot(): void
    {
        self::settings($this->app);
        $container = $this->app->container();
        Lock::setResolver(static fn (): LockManager => $container->make(LockManager::class));
    }

    /** @return array{string,?string,?string,?string,?string,bool} */
    private static function settings(Application $app): array
    {
        $config = $app->config();
        $driver = $config->get('locks.driver', 'file');
        $prefix = $config->get('locks.prefix');
        $path = $config->get('locks.path');
        $redis = $config->get('locks.redis_connection');
        $database = $config->get('locks.database_connection');
        $required = $config->get('locks.require_distributed', false);
        $drivePath = is_string($path) && strlen($path) >= 3 && ctype_alpha($path[0])
            && $path[1] === ':' && in_array($path[2], ['/', '\\'], true);
        if (!is_string($driver) || !in_array($driver, ['file', 'array', 'database', 'redis', 'auto'], true)
            || ($prefix !== null && (!is_string($prefix)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9:._-]{0,127}\z/D', $prefix) !== 1))
            || ($path !== null && (!is_string($path) || $path === ''
                || !(str_starts_with($path, '/') || $drivePath || str_starts_with($path, '\\\\'))))
            || ($redis !== null && (!is_string($redis)
                || preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/D', $redis) !== 1))
            || ($database !== null && (!is_string($database)
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/D', $database) !== 1))
            || !is_bool($required)) {
            throw new LockConfigurationException('Invalid lock configuration.');
        }
        if ($required && in_array($driver, ['array', 'file', 'auto'], true)) {
            throw new LockConfigurationException('Distributed locks require explicit Redis or MySQL.');
        }
        if ($required && $driver === 'database'
            && self::databaseDriver($app, $database) !== 'mysql') {
            throw new LockConfigurationException('Distributed database locks require MySQL.');
        }
        return [$driver, $prefix, $path, $redis, $database, $required];
    }

    private static function databaseDriver(Application $app, ?string $name): ?string
    {
        $name ??= $app->config()->get('database.default');
        if (!is_string($name) || $name === '') return null;
        $driver = $app->config()->get('database.connections.' . $name . '.driver');
        return is_string($driver) ? $driver : null;
    }
}
