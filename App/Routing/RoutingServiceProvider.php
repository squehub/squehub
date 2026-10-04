<?php

declare(strict_types=1);

namespace App\Routing;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;
use App\Http\Dispatcher;
use App\Support\GlobalAliases;
use InvalidArgumentException;

/** Owns the shared route registry, dispatcher, and configured middleware aliases. */
final class RoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $container->singleton(RouteRegistry::class);
        $container->singleton(RouteMatcher::class);
        $container->singleton(MiddlewareRegistry::class);
        $container->singleton(MiddlewarePipeline::class);
        $container->singleton(ControllerDispatcher::class);
        $container->singleton(RouteDispatcher::class);
        $container->singleton(Dispatcher::class,
            static fn (Container $container): RouteDispatcher => $container->make(RouteDispatcher::class));
        // Package register hooks run before providers boot. Give their static
        // Route API this Application's registry during the register phase.
        Route::setResolver(static fn (): RouteRegistry => $container->make(RouteRegistry::class));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $aliases = $this->app->config()->get('routing.middleware', []);
        if (!is_array($aliases)) {
            throw new InvalidArgumentException('routing.middleware configuration must be an array.');
        }
        $middleware = $container->make(MiddlewareRegistry::class);
        foreach ($aliases as $name => $class) {
            if (!is_string($name) || !is_string($class)) {
                throw new InvalidArgumentException('Middleware configuration must map aliases to class names.');
            }
            $middleware->alias($name, $class);
        }
        Route::setResolver(static fn (): RouteRegistry => $container->make(RouteRegistry::class));
        GlobalAliases::register();
        if ($container->has(Diagnostics::class)) {
            $diagnostics = $container->make(Diagnostics::class);
            $container->make(RouteDispatcher::class)->setDiagnostics($diagnostics);
            $container->make(MiddlewarePipeline::class)->setDiagnostics($diagnostics);
            $container->make(ControllerDispatcher::class)->setDiagnostics($diagnostics);
        }
    }
}
