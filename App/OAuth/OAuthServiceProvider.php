<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Config\Repository;
use App\Container\Container;
use App\Database\ModelClock;
use App\Foundation\ServiceProvider;
use App\HttpClient\HttpClient;
use App\Session\SessionManager;

/** Binds OIDC clients lazily; bootstrap does not start Session or contact providers. */
final class OAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(OAuthManager::class,
            static fn (Container $container): OAuthManager => new OAuthManager(
                $container->make(Repository::class),
                $container->make(HttpClient::class),
                $container->make(SessionManager::class),
                $container->has(ModelClock::class) ? $container->make(ModelClock::class) : null
            ));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        OAuth::setResolver(static fn (): OAuthManager => $container->make(OAuthManager::class));
    }
}
