<?php

declare(strict_types=1);

namespace App\Diagnostics;

use App\Container\Container;
use App\Foundation\ServiceProvider;
use App\Observability\CorrelationContext;

/** Registers one collector per Application without starting an HTTP request. */
final class DiagnosticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $container->singleton(CorrelationContext::class);
        $container->singleton(Diagnostics::class, static fn (Container $container): Diagnostics =>
            new Diagnostics($container->make(\App\Config\Repository::class),
                $container->make(CorrelationContext::class)));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        Diagnostic::setResolver(static fn (): Diagnostics => $container->make(Diagnostics::class));
    }
}
