<?php

declare(strict_types=1);

namespace App\Authorization\Rbac;

use App\Config\Repository;
use App\Container\Container;
use App\Database\DatabaseManager;
use App\Foundation\ServiceProvider;

/** Register and validate optional RBAC without querying its tables at boot. */
final class RbacServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(RbacManager::class, static function (Container $container): RbacManager {
            $settings = $container->make(Repository::class)->get('rbac', []);
            if (!is_array($settings) || array_diff(array_keys($settings), ['enabled', 'driver', 'connection']) !== []) {
                throw new RbacException('RBAC configuration must contain only enabled, driver, and connection.');
            }
            $enabled = $settings['enabled'] ?? false;
            $driver = $settings['driver'] ?? 'database';
            $connection = $settings['connection'] ?? null;
            if (!is_bool($enabled) || !is_string($driver) || !in_array($driver, ['array', 'database'], true)
                || ($connection !== null && (!is_string($connection)
                    || preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/D', $connection) !== 1))) {
                throw new RbacException('RBAC configuration is invalid.');
            }
            if ($enabled && $driver === 'database' && !$container->has(DatabaseManager::class)) {
                throw new RbacException('Database-backed RBAC requires the Database provider.');
            }
            $repository = !$enabled || $driver === 'array'
                ? new Repositories\ArrayRbacRepository()
                : new Repositories\DatabaseRbacRepository($container->make(DatabaseManager::class), $connection);
            return new RbacManager($repository, $enabled);
        });
    }

    public function boot(): void
    {
        $manager = $this->app->container()->make(RbacManager::class);
        Rbac::setResolver(static fn (): RbacManager => $manager);
    }
}
