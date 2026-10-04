<?php

declare(strict_types=1);

namespace App\Health;

use App\Container\Container;
use App\Foundation\ServiceProvider;
use App\Http\JsonResponse;
use App\Routing\RouteRegistry;

/** Register the health service lazily and expose only explicitly enabled routes. */
final class HealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(HealthManager::class,
            static fn (Container $container): HealthManager => new HealthManager($app, $container));
        // All providers register before any provider boots. Publishing the
        // gateway here lets earlier package providers add checks in boot().
        Health::setResolver(static fn (): HealthManager => $app->container()->make(HealthManager::class));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $enabled = $this->app->config()->get('health.endpoints_enabled', false);
        $middleware = $this->app->config()->get('health.middleware', []);
        if (!is_bool($enabled) || !is_array($middleware)) {
            throw new HealthException('Health endpoint configuration is invalid.');
        }
        foreach (['require_database', 'require_storage', 'require_queue', 'require_scheduler',
            'require_crypt', 'require_http_client'] as $setting) {
            if (!is_bool($this->app->config()->get('health.' . $setting, false))) {
                throw new HealthException('Health readiness configuration is invalid.');
            }
        }
        if (!$enabled) return;
        $registry = $container->make(RouteRegistry::class);
        $manager = $container->make(HealthManager::class);
        $headers = ['Cache-Control' => 'no-store, max-age=0'];
        $registry->get('/health/live', static fn (): JsonResponse => new JsonResponse(
            ['status' => 'ok'], 200, $headers))->named('health.live')->through($middleware)->protect();
        $registry->get('/health/ready', static function () use ($manager, $headers): JsonResponse {
            $ready = $manager->ready()->healthy();
            return new JsonResponse(['status' => $ready ? 'ready' : 'unavailable'],
                $ready ? 200 : 503, $headers);
        })->named('health.ready')->through($middleware)->protect();
    }
}
