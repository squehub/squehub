<?php

declare(strict_types=1);

namespace App\Observability;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;
use InvalidArgumentException;

/** Registers an inert-by-default recorder without opening a transport. */
final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(ObservabilityManager::class,
            static function (Container $container) use ($app): ObservabilityManager {
                $settings = $app->config()->get('observability', []);
                if (!is_array($settings)) {
                    throw new InvalidArgumentException('Observability configuration must be a map.');
                }
                return new ObservabilityManager($settings);
            });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $manager = $container->make(ObservabilityManager::class);
        if ($container->has(Diagnostics::class)) {
            $container->make(Diagnostics::class)->setObservability($manager);
        }
    }
}
