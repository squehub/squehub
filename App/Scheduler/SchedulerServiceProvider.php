<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Container\Container;
use App\Database\DatabaseManager;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;
use App\Queue\QueueManager;

/** Registers definitions and lazy persistence without executing scheduled work. */
final class SchedulerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(Scheduler::class, static function (Container $container) use ($app): Scheduler {
            $settings = $app->config()->get('scheduler', []);
            if (!is_array($settings)) throw new SchedulerException('Scheduler configuration must be a map.');
            $database = $container->has(DatabaseManager::class)
                ? static fn (?string $name) => $container->make(DatabaseManager::class)->connection($name) : null;
            $queue = $container->has(QueueManager::class)
                ? static fn () => $container->make(QueueManager::class) : null;
            return new Scheduler($container, $settings, $database, $queue, null,
                $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null,
                $app->contributions());
        });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $container->make(Scheduler::class);
        Schedule::setResolver(static fn (): Scheduler => $container->make(Scheduler::class));
    }
}
