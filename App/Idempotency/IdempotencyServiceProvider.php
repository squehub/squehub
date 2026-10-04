<?php

declare(strict_types=1);

namespace App\Idempotency;

use App\Auth\AuthManager;
use App\Container\Container;
use App\Database\DatabaseManager;
use App\Foundation\ServiceProvider;
use App\Idempotency\Stores\ArrayIdempotencyStore;
use App\Idempotency\Stores\DatabaseIdempotencyStore;
use App\Idempotency\Stores\FileIdempotencyStore;
use App\Idempotency\Stores\RedisIdempotencyStore;
use App\Redis\InfrastructureSelection;
use App\Redis\RedisException;
use App\Redis\RedisManager;

/** Lazily selects one backend; explicit shared infrastructure never falls back. */
final class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(IdempotencyManager::class,
            static function (Container $container) use ($app): IdempotencyManager {
                $settings = $app->config()->get('idempotency', []);
                if (!is_array($settings) || array_diff(array_keys($settings), [
                    'driver', 'namespace', 'path', 'database_connection', 'redis_connection',
                    'require_shared', 'lease_seconds', 'retention_seconds',
                    'max_request_bytes', 'max_response_bytes']) !== []) {
                    throw new IdempotencyException('Invalid idempotency configuration.');
                }
                $driver = $settings['driver'] ?? 'file';
                $namespace = $settings['namespace'] ?? null;
                $path = $settings['path'] ?? null;
                $databaseName = $settings['database_connection'] ?? null;
                $redisName = $settings['redis_connection'] ?? null;
                $requireShared = $settings['require_shared'] ?? false;
                if (!is_string($driver) || !in_array($driver, ['array', 'file', 'database', 'redis', 'auto'], true)
                    || ($namespace !== null && (!is_string($namespace)
                        || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $namespace) !== 1))
                    || ($path !== null && (!is_string($path) || $path === ''))
                    || ($databaseName !== null && (!is_string($databaseName)
                        || preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/D', $databaseName) !== 1))
                    || ($redisName !== null && (!is_string($redisName)
                        || preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/D', $redisName) !== 1))
                    || !is_bool($requireShared)) {
                    throw new IdempotencyException('Invalid idempotency configuration.');
                }
                $lease = self::integer($settings['lease_seconds'] ?? 120, 1, 300);
                $retention = self::integer($settings['retention_seconds'] ?? 86400, 60, 604800);
                $maxRequest = self::integer($settings['max_request_bytes'] ?? 1048576, 1, 1048576);
                $maxResponse = self::integer($settings['max_response_bytes'] ?? 32768, 1, 32768);
                if ($retention <= $lease) throw new IdempotencyException('Idempotency retention must exceed the lease.');
                // Container::make() returns object, while class bindings are
                // checked against their requested type by the container.
                /** @var RedisManager|null $redis */
                $redis = $container->has(RedisManager::class)
                    ? $container->make(RedisManager::class) : null;
                $selection = new InfrastructureSelection($driver, 'file', $redis, $redisName);
                try {
                    $selected = $selection->resolve();
                } catch (RedisException $failure) {
                    throw new IdempotencyException('Idempotency backend selection failed.', 0, $failure);
                }
                if ($requireShared && !in_array($selected, ['redis', 'database'], true)) {
                    throw new IdempotencyException('Shared idempotency requires Redis or MySQL.');
                }
                $namespace ??= hash('sha256', $app->basePath());
                if (in_array($selected, ['redis', 'database'], true)
                    && ($settings['namespace'] ?? null) === null) {
                    // Paths can differ among servers. A durable/shared backend
                    // needs an explicit stable namespace across all nodes.
                    throw new IdempotencyException('Idempotency shared backend requires an explicit namespace.');
                }
                /** @var DatabaseManager|null $database */
                $database = $container->has(DatabaseManager::class)
                    ? $container->make(DatabaseManager::class) : null;
                // Keep backend failures beside their constructors. A missing
                // provider must never become an implicit local-store fallback.
                if ($selected === 'array') {
                    $store = new ArrayIdempotencyStore();
                } elseif ($selected === 'file') {
                    $store = new FileIdempotencyStore(
                        $path ?? $app->basePath('Storage/Idempotency'), $namespace);
                } elseif ($selected === 'database') {
                    if ($database === null) {
                        throw new IdempotencyException('Database idempotency requires the Database provider.');
                    }
                    $store = new DatabaseIdempotencyStore($database->connection($databaseName));
                } elseif ($selected === 'redis') {
                    if ($redis === null) {
                        throw new IdempotencyException('Redis idempotency requires the Redis provider.');
                    }
                    $store = new RedisIdempotencyStore($redis->connection($redisName), $namespace);
                } else {
                    throw new IdempotencyException('Unsupported idempotency backend.');
                }
                if ($requireShared && $selected === 'database'
                    && ($database === null || $database->connection($databaseName)->driver() !== 'mysql')) {
                    throw new IdempotencyException('Shared idempotency requires a MySQL database connection.');
                }
                if (!$container->has(AuthManager::class)) {
                    throw new IdempotencyException('Authenticated idempotency requires the Auth provider.');
                }
                /** @var AuthManager $auth */
                $auth = $container->make(AuthManager::class);
                return new IdempotencyManager($store, $auth,
                    $database?->clock() ?? new \App\Database\SystemModelClock(),
                    $namespace, $lease, $retention, $maxRequest, $maxResponse, $selected);
            });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        Idempotency::setResolver(static function () use ($container): IdempotencyManager {
            /** @var IdempotencyManager $manager */
            $manager = $container->make(IdempotencyManager::class);
            return $manager;
        });
    }

    private static function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || (int) $value < $min || (int) $value > $max) {
            throw new IdempotencyException('Invalid idempotency numeric configuration.');
        }
        return (int) $value;
    }
}
