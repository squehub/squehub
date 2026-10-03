<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Config\Repository;
use App\Container\Container;
use App\Database\DatabaseManager;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;
use App\HttpClient\HttpClient;
use App\Queue\QueueManager;

/** Registers the optional subsystem without opening a database or network connection. */
final class WebhookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(WebhookManager::class,
            static fn (Container $container): WebhookManager => new WebhookManager(
                $container->make(Repository::class),
                $container->make(HttpClient::class),
                $container->has(QueueManager::class)
                    ? $container->make(QueueManager::class) : null,
                $container->make(DatabaseManager::class),
                $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null
            ));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        Webhook::setResolver(static fn (): WebhookManager => $container->make(WebhookManager::class));
    }
}
