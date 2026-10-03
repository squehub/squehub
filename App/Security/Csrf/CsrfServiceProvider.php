<?php

declare(strict_types=1);

namespace App\Security\Csrf;

use App\Foundation\ServiceProvider;
use App\Http\Kernel;

/** Connects the token service to the Application and its global HTTP stage. */
final class CsrfServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $container->singleton(CsrfTokenManager::class);
        $container->singleton(CsrfMiddleware::class);
    }

    public function boot(): void
    {
        $container = $this->app->container();
        Csrf::setResolver(static fn (): CsrfTokenManager => $container->make(CsrfTokenManager::class));
        // Resolving middleware during boot validates exclusions before any
        // request can run; disabled mode still validates configuration shape.
        $container->make(Kernel::class)->addGlobalMiddleware($container->make(CsrfMiddleware::class));
    }
}
