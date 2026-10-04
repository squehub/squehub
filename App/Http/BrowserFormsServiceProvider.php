<?php

declare(strict_types=1);

namespace App\Http;

use App\Foundation\ServiceProvider;

/**
 * Connects session-backed browser navigation to the Kernel and error handler.
 *
 * Applications without this provider retain direct HTML 422 validation;
 * adding it changes only browser form handling, not JSON or CSRF ordering.
 */
final class BrowserFormsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(BrowserNavigation::class);
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $navigation = $container->make(BrowserNavigation::class);
        $container->make(Kernel::class)->setBrowserNavigation($navigation);
        $container->make(ExceptionHandler::class)->setBrowserNavigation($navigation);
    }
}
