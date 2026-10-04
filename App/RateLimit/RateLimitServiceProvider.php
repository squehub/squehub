<?php

declare(strict_types=1);

namespace App\RateLimit;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;
use App\RateLimit\Stores\ArrayRateLimitStore;
use App\RateLimit\Stores\FileRateLimitStore;
use App\RateLimit\Stores\RedisRateLimitStore;
use App\Redis\InfrastructureSelection;
use App\Redis\RedisManager;
use App\Redis\RedisException;

/** Registers a lazy store and manager; boot never opens limiter runtime files. */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(RateLimiter::class, static function (Container $container) use ($app): RateLimiter {
            $settings = $app->config()->get('rateLimit', []);
            if (!is_array($settings) || array_diff(array_keys($settings),
                ['store', 'driver', 'prefix', 'path', 'redis_connection']) !== []) {
                throw new RateLimitException('Invalid rate-limit configuration.');
            }
            $storeName = $settings['driver'] ?? $settings['store'] ?? 'file';
            $prefix = $settings['prefix'] ?? 'squehub';
            $path = $settings['path'] ?? null;
            $redisName = $settings['redis_connection'] ?? null;
            if (!is_string($prefix) || !is_string($storeName)
                || !in_array($storeName, ['file', 'array', 'redis', 'auto'], true)
                || ($path !== null && !is_string($path))
                || ($redisName !== null && (!is_string($redisName)
                    || !preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,63}$/D', $redisName)))) {
                throw new RateLimitException('Invalid rate-limit configuration.');
            }
            RateLimitKey::prefix($prefix);
            $selection = new InfrastructureSelection($storeName, 'file',
                $container->has(RedisManager::class) ? $container->make(RedisManager::class) : null,
                $redisName);
            try {
                $selected = $selection->resolve();
            } catch (RedisException $failure) {
                throw new RateLimitException('Rate-limit Redis selection failed.', 0, $failure);
            }
            if ($selected === 'redis') {
                if (!$container->has(RedisManager::class)) {
                    throw new RateLimitException('Redis Rate Limit requires the Redis provider.');
                }
                try {
                    $connection = $container->make(RedisManager::class)->connection($redisName);
                } catch (RedisException $failure) {
                    throw new RateLimitException('Rate-limit Redis connection is invalid.', 0, $failure);
                }
                $store = new RedisRateLimitStore($connection, $prefix);
            } else {
                $store = $selected === 'array' ? new ArrayRateLimitStore()
                    : new FileRateLimitStore($path ?? $app->basePath('Storage/RateLimits'), $prefix);
            }
            $diagnostics = $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null;
            return new RateLimiter($store, $prefix, $container, $diagnostics, selection: $selection);
        });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        RateLimit::setResolver(static fn (): RateLimiter => $container->make(RateLimiter::class));
    }
}
