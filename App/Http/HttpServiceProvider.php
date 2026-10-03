<?php

declare(strict_types=1);

namespace App\Http;

use App\Api\ApiVersionPolicy;
use App\Api\CorsPolicy;
use App\Diagnostics\Diagnostics;
use App\Frontend\PackageAssetSource;
use App\Frontend\ServePackageAsset;
use App\Foundation\ServiceProvider;
use App\Http\Exception\MethodNotAllowedHttpException;
use App\Http\Exception\NotFoundHttpException;
use App\Logging\Logger;
use App\Routing\RouteMatcher;
use App\Routing\RouteRegistry;

/** Registers the Kernel and response services; request handling remains lazy. */
final class HttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $container->singleton(ExceptionHandler::class);
        $container->singleton(ResponseNormalizer::class);
        $container->singleton(ResponseFactory::class);
        $container->singleton(Kernel::class);
        $container->singleton(PackageAssetSource::class);
        $container->singleton(ServePackageAsset::class);
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $config = $this->app->config();
        // Reject invalid policies while the Application boots. The Kernel
        // re-reads them per request so a long-lived Application has no stale
        // cross-origin or version state after an explicit config change.
        new ApiVersionPolicy($config);
        new CorsPolicy($config);
        new TrustedProxyPolicy($config);
        new BrowserSecurityPolicy($config);
        if ($container->has(Dispatcher::class)) {
            $kernel = $container->make(Kernel::class);
            $kernel->addGlobalMiddleware($container->make(ServePackageAsset::class));
            $kernel->setTrustedProxyConfiguration($config);
            $kernel->setBrowserSecurityConfiguration($config);
            $kernel->setApiPolicies($config,
                static function (Request $request, string $method) use ($container): bool {
                    if (!$container->has(RouteRegistry::class) || !$container->has(RouteMatcher::class)) {
                        return false;
                    }
                    try {
                        // Probe the requested method against the authority
                        // already resolved from this Application's proxy policy.
                        $matched = $container->make(RouteMatcher::class)->match(
                            $container->make(RouteRegistry::class),
                            $request->forRouteProbe($method)
                        );
                        // An unmatched-path fallback is not an API endpoint
                        // and must not grant CORS preflight for arbitrary paths.
                        return !$matched->route->isFallback();
                    } catch (NotFoundHttpException | MethodNotAllowedHttpException) {
                        return false;
                    }
                });
        }
        if ($container->has(Diagnostics::class)) {
            $diagnostics = $container->make(Diagnostics::class);
            $container->make(Kernel::class)->setDiagnostics($diagnostics);
            $container->make(ExceptionHandler::class)->setDiagnostics($diagnostics);
        }
        if ($container->has(Logger::class)) {
            $container->make(ExceptionHandler::class)->setLogger($container->make(Logger::class));
        }
    }
}
