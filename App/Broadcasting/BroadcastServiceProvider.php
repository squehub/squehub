<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;

/** Registers a lazy broadcaster without connecting to any external provider. */
final class BroadcastServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(BroadcastManager::class,
            static function (Container $container) use ($app): BroadcastManager {
                $settings = $app->config()->get('broadcasting', []);
                if (!is_array($settings)) throw new BroadcastException('Broadcast configuration must be a map.');
                return new BroadcastManager($container, $settings,
                    $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null);
            });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $container->make(BroadcastManager::class);
        Broadcast::setResolver(static fn (): BroadcastManager => $container->make(BroadcastManager::class));
    }
}
