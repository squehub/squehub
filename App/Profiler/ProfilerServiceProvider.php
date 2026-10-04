<?php

declare(strict_types=1);

namespace App\Profiler;

use App\Container\Container;
use App\Foundation\ServiceProvider;
use App\Observability\ObservabilityManager;

/**
 * Registers one Application-owned profiler. Only explicit development opt-in
 * activates a local consumer on Observability; APP_DEBUG never enables it.
 * Merely booting does not touch local profile storage.
 */
final class ProfilerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(ProfilerManager::class,
            static function (Container $container) use ($app): ProfilerManager {
                $config = $app->config()->get('profiler', []);
                if (!is_array($config)) {
                    throw new ProfilerException('Profiler configuration must be a map.');
                }
                $settings = new ProfilerSettings($config, $app->environment());
                $store = $settings->store === 'array'
                    ? new ArrayProfilerStore($settings)
                    : new FileProfilerStore($app->basePath('Storage/Logs/Profiler'),
                        $app->basePath(), $settings);
                return new ProfilerManager($settings, $store);
            });
    }

    public function boot(): void
    {
        // The default-disabled path does not construct a manager, store, or
        // profile graph. Explicit resolution remains available for inspection.
        if ($this->app->environment() !== 'development'
            || $this->app->config()->get('profiler.enabled', false) !== true) {
            return;
        }
        $profiler = $this->app->container()->make(ProfilerManager::class);
        $this->app->container()->make(ObservabilityManager::class)
            ->activateLocalConsumer($profiler);
    }
}
