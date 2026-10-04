<?php

declare(strict_types=1);

namespace App\Storage;

use App\Container\Container;
use App\Database\ModelClock;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;

/** Registers Storage without touching any configured physical root at boot. */
final class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $this->app->container()->singleton(StorageManager::class, static function (Container $container) use ($app): StorageManager {
            return new StorageManager($app,
                $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null,
                $container->has(ModelClock::class) ? $container->make(ModelClock::class) : null);
        });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        Storage::setResolver(static fn (): StorageManager => $container->make(StorageManager::class));
    }
}
