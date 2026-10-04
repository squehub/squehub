<?php

declare(strict_types=1);

namespace App\Session;

use App\Foundation\ServiceProvider;
use App\Container\Container;
use App\Redis\RedisManager;

/** Registers the manager and connects the lightweight helper to this Application. */
final class SessionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(SessionManager::class,
            static fn (Container $container): SessionManager => new SessionManager($app->config(),
                $container->has(RedisManager::class) ? $container->make(RedisManager::class) : null,
                $app->basePath()));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        Session::setResolver(static fn (): SessionManager => $container->make(SessionManager::class));
    }
}
