<?php

declare(strict_types=1);

namespace App\Database;

use App\Foundation\ServiceProvider;
use App\Config\Repository;
use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Database\Lifecycle\ModelObserverRegistry;
use App\Database\Seeding\SeederRunner;
use App\Events\EventDispatcher;

/** Registers data services without opening PDO during Application bootstrap. */
final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $container->singleton(ConnectionFactory::class);
        $container->singleton(ModelObserverRegistry::class, static fn (Container $container): ModelObserverRegistry =>
            new ModelObserverRegistry($container, $container->has(EventDispatcher::class)
                ? $container->make(EventDispatcher::class) : null));
        $container->singleton(DatabaseManager::class, static fn (Container $container): DatabaseManager =>
            new DatabaseManager($container->make(Repository::class), $container->make(ConnectionFactory::class),
                null, $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null,
                $container->make(ModelObserverRegistry::class)));
        $container->singleton(SeederRunner::class);
    }

    public function boot(): void
    {
        $container = $this->app->container();
        Database::setResolver(static fn (): DatabaseManager => $container->make(DatabaseManager::class));
    }
}
