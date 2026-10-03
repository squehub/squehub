<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;
use App\Mail\Mailer;
use App\Notifications\Channels\ArrayNotificationChannel;
use App\Notifications\Channels\MailNotificationChannel;
use App\Queue\QueueManager;

/** Registers channel factories without requiring Mail until Mail is selected. */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $container->singleton(NotificationManager::class, static function (Container $container): NotificationManager {
            $diagnostics = $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null;
            $manager = new NotificationManager($diagnostics,
                $container->has(QueueManager::class) ? $container->make(QueueManager::class) : null);
            $manager->registerChannel('array', static fn (): ArrayNotificationChannel => new ArrayNotificationChannel());
            $manager->registerChannel('mail', static function () use ($container): MailNotificationChannel {
                if (!$container->has(Mailer::class)) {
                    throw NotificationException::framework('Mail service is unavailable for notifications.');
                }
                return new MailNotificationChannel($container->make(Mailer::class));
            });
            return $manager;
        });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        Notifications::setResolver(static fn (): NotificationManager => $container->make(NotificationManager::class));
    }
}
