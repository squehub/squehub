<?php

declare(strict_types=1);

namespace App\Queue;

use App\Container\Container;
use App\Database\DatabaseManager;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;
use App\Redis\RedisManager;

/** Registers Queue without opening a database or requiring Queue tables. */
final class QueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(QueueManager::class, static function (Container $container) use ($app): QueueManager {
            $settings = $app->config()->get('queue', []);
            if (!is_array($settings)) throw new QueueException('Queue configuration must be a map.');
            if (is_array($settings['connections']['redis'] ?? null)
                && empty($settings['connections']['redis']['namespace'])) {
                $settings['connections']['redis']['namespace'] = hash('sha256', $app->basePath());
            }
            $database = $container->has(DatabaseManager::class)
                ? static fn (?string $name) => $container->make(DatabaseManager::class)->connection($name)
                : null;
            return new QueueManager($settings, $database, null,
                $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null,
                $container->has(RedisManager::class) ? $container->make(RedisManager::class) : null);
        });
        $app->container()->singleton(RestartSignal::class,
            static fn (): RestartSignal => new RestartSignal($app->basePath('Storage/Queue')));
        $app->container()->singleton(Worker::class, static fn (Container $container): Worker =>
            new Worker($container->make(QueueManager::class),
                $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null,
                $container, $container->make(RestartSignal::class)));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $container->make(QueueManager::class);
        Queue::setResolver(static fn (): QueueManager => $container->make(QueueManager::class));
    }
}
