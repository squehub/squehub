<?php

declare(strict_types=1);

namespace App\Packages;

use App\Core\View;
use App\Foundation\ServiceProvider;
use App\Routing\MiddlewareRegistry;
use App\Routing\RouteRegistry;
use App\Support\RuntimeContext;

/** Applies the validated enabled Package set through the Application lifecycle. */
final class PackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $container->singleton(PackageManager::class);
        $manager = $container->make(PackageManager::class);
        View::setPackageManager($manager, $this->app->basePath());
        // A second Application may be booting in this PHP process. Package
        // register hooks must resolve this Application's static helpers.
        RuntimeContext::select($this->app);
        $middleware = $container->has(MiddlewareRegistry::class)
            ? $container->make(MiddlewareRegistry::class) : null;
        $routes = $container->has(RouteRegistry::class)
            ? $container->make(RouteRegistry::class) : null;
        $middleware?->beginPackageContext('enabled Packages');
        try {
            $routes?->beginPackageContext('enabled Packages');
            try {
                $manager->registerEnabled();
            } finally {
                $routes?->endPackageContext();
            }
        } finally {
            $middleware?->endPackageContext();
        }
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $middleware = $container->has(MiddlewareRegistry::class)
            ? $container->make(MiddlewareRegistry::class) : null;
        $routes = $container->has(RouteRegistry::class)
            ? $container->make(RouteRegistry::class) : null;
        $middleware?->beginPackageContext('enabled Packages');
        try {
            $routes?->beginPackageContext('enabled Packages');
            try {
                $manager = $container->make(PackageManager::class);
                $manager->loadEnabledUtilities();
                $manager->bootEnabled();
            } finally {
                $routes?->endPackageContext();
            }
        } finally {
            $middleware?->endPackageContext();
        }
    }
}
