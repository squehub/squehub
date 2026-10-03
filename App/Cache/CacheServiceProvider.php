<?php

declare(strict_types=1);

namespace App\Cache;

use App\Cache\Drivers\ArrayCacheDriver;
use App\Cache\Drivers\FileCacheDriver;
use App\Cache\Drivers\MemcachedCacheDriver;
use App\Cache\Drivers\PhpMemcachedClient;
use App\Cache\Drivers\RedisCacheDriver;
use App\Container\Container;
use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;
use App\Foundation\Application;
use App\Redis\InfrastructureSelection;
use App\Redis\RedisManager;
use App\Redis\RedisException;
use InvalidArgumentException;

/** Validates configuration at boot; file storage opens only on an actual operation. */
final class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $container = $app->container();
        $container->singleton(CacheStore::class, static function (Container $container) use ($app): CacheStore {
            [$driverName, $prefix, $path, $redisName] = self::settings($app);
            // By default the real Application path separates projects sharing
            // one root. An explicit prefix deliberately allows cache sharing.
            $namespace = $prefix ?? $app->basePath();
            $clock = $container->has(ModelClock::class)
                ? $container->make(ModelClock::class) : new SystemModelClock();
            $selection = new InfrastructureSelection($driverName, 'file',
                $container->has(RedisManager::class) ? $container->make(RedisManager::class) : null,
                $redisName);
            try {
                $selected = $selection->resolve();
            } catch (RedisException $failure) {
                throw new CacheException('Cache Redis selection failed.', 0, $failure);
            }
            if ($selected === 'file') {
                $path ??= $app->basePath('Storage/Cache');
                $drivePath = strlen($path) >= 3 && ctype_alpha($path[0])
                    && $path[1] === ':' && in_array($path[2], ['/', '\\'], true);
                if ($path === '' || !(str_starts_with($path, '/') || $drivePath || str_starts_with($path, '\\\\'))) {
                    throw new InvalidArgumentException('Cache path must be absolute.');
                }
                $driver = new FileCacheDriver($path, $namespace);
            } elseif ($selected === 'redis') {
                if (!$container->has(RedisManager::class)) {
                    throw new CacheException('Redis Cache requires the Redis provider.');
                }
                try {
                    $connection = $container->make(RedisManager::class)->connection($redisName);
                } catch (RedisException $failure) {
                    throw new CacheException('Cache Redis connection is invalid.', 0, $failure);
                }
                $driver = new RedisCacheDriver($connection, $namespace, $clock);
            } elseif ($selected === 'memcached') {
                [$host, $port, $timeoutMs] = self::memcachedSettings($app);
                $driver = new MemcachedCacheDriver(
                    new PhpMemcachedClient($host, $port, $timeoutMs), $namespace, $clock
                );
            } else {
                $driver = new ArrayCacheDriver();
            }
            $diagnostics = $container->has(Diagnostics::class)
                ? $container->make(Diagnostics::class) : null;
            return new CacheStore($driver, $namespace, $clock, $diagnostics, $selection);
        });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        [$driver] = self::settings($this->app);
        // Memcached configuration is validated at boot, but client creation
        // waits until Cache is used. Doctor can then report a missing optional
        // extension without preventing the Application from booting.
        if ($driver === 'memcached') self::memcachedSettings($this->app);
        if ($driver !== 'auto' && $driver !== 'memcached') $container->make(CacheStore::class);
        Cache::setResolver(static fn (): CacheStore => $container->make(CacheStore::class));
    }

    /** @return array{0:string,1:?string,2:?string,3:?string} */
    private static function settings(Application $app): array
    {
        $config = $app->config();
        $driver = $config->get('cache.driver', 'file');
        $prefix = $config->get('cache.prefix');
        $path = $config->get('cache.path');
        $redis = $config->get('cache.redis_connection');
        // A prefix controls logical sharing, never a relative filesystem path.
        if (!is_string($driver) || !in_array($driver, ['file', 'array', 'redis', 'memcached', 'auto'], true)
            || ($prefix !== null && (!is_string($prefix) || $prefix === ''
                || strlen($prefix) > 128 || str_contains($prefix, '..')
                || str_contains($prefix, '/') || str_contains($prefix, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $prefix)))
            || ($path !== null && !is_string($path))
            || ($redis !== null && (!is_string($redis)
                || !preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,63}$/D', $redis)))) {
            throw new InvalidArgumentException('Invalid cache configuration.');
        }
        return [$driver, $prefix, $path, $redis];
    }

    /** @return array{0:string,1:int,2:int} */
    private static function memcachedSettings(Application $app): array
    {
        $settings = $app->config()->get('cache.memcached', []);
        if (!is_array($settings)) throw new InvalidArgumentException('Invalid Memcached Cache configuration.');
        $host = $settings['host'] ?? '127.0.0.1';
        $port = $settings['port'] ?? 11211;
        $timeout = $settings['timeout_ms'] ?? 1000;
        $validHost = is_string($host) && $host !== '' && strlen($host) <= 253
            && (filter_var($host, FILTER_VALIDATE_IP) !== false
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);
        $validPort = (is_int($port) || is_string($port) && preg_match('/\A[1-9][0-9]{0,4}\z/D', $port) === 1)
            && (int) $port >= 1 && (int) $port <= 65535;
        $validTimeout = (is_int($timeout) || is_string($timeout)
                && preg_match('/\A[1-9][0-9]{0,4}\z/D', $timeout) === 1)
            && (int) $timeout >= 100 && (int) $timeout <= 10000;
        if (!$validHost || !$validPort || !$validTimeout) {
            throw new InvalidArgumentException('Invalid Memcached Cache configuration.');
        }
        return [$host, (int) $port, (int) $timeout];
    }
}
