<?php

declare(strict_types=1);

namespace App\Reliability;

use App\Container\Container;
use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\Foundation\ServiceProvider;

/** Optional single-server breaker; registration and resolution do no file I/O. */
final class CircuitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $container = $app->container();
        $container->singleton(CircuitBreaker::class,
            static function (Container $container) use ($app): CircuitBreaker {
                $clock = $container->has(ModelClock::class)
                    ? $container->make(ModelClock::class) : new SystemModelClock();
                return new CircuitBreaker(
                    new FileCircuitStore($app->basePath('Storage/Circuits'), $app->basePath()),
                    $clock
                );
            });
        $container->alias(CircuitBreaker::class, \App\Plugins\CircuitBreaker::class);
    }
}
