<?php

declare(strict_types=1);

namespace App\Auth;

use App\Foundation\ServiceProvider;
use App\Http\Kernel;
use App\Routing\MiddlewareRegistry;
use App\Auth\Middleware\RequireAuthentication;
use App\Auth\Middleware\RequireGuest;
use App\Container\Container;
use App\Config\Repository;
use App\Diagnostics\Diagnostics;
use App\Session\SessionManager;

/** Binds authentication without opening a session, database, or dummy hash at boot. */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $container->singleton(PasswordHasher::class);
        $container->singleton(AuthManager::class, static fn (Container $container): AuthManager =>
            new AuthManager($container->make(Repository::class), $container->make(SessionManager::class),
                $container->make(PasswordHasher::class),
                $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null,
                $container));
        $container->singleton(RequireAuthentication::class);
        $container->singleton(RequireGuest::class);
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $manager = $container->make(AuthManager::class);
        Auth::setResolver(static fn (): AuthManager => $manager);
        // Console-only Applications can use guards without installing HTTP or routing.
        if ($container->has(Kernel::class)) $container->make(Kernel::class)->setAuthManager($manager);
        if ($container->has(MiddlewareRegistry::class)) {
            $aliases = $container->make(MiddlewareRegistry::class);
            $aliases->alias('auth', RequireAuthentication::class);
            $aliases->alias('guest', RequireGuest::class);
        }
    }
}
