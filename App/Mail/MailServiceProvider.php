<?php

declare(strict_types=1);

namespace App\Mail;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;
use App\HttpClient\HttpClient;
use App\Queue\QueueManager;

/** Validates the default mail map at boot without constructing an SMTP socket. */
final class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(Mailer::class, static function (Container $container) use ($app): Mailer {
            $settings = $app->config()->get('mail', []);
            if (!is_array($settings)) throw new MailConfigurationException('Mail configuration must be a map.');
            $diagnostics = $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null;
            return new Mailer($settings, $diagnostics,
                $container->has(QueueManager::class) ? $container->make(QueueManager::class) : null,
                $container->has(HttpClient::class) ? $container->make(HttpClient::class) : null);
        });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $container->make(Mailer::class);
        Mail::setResolver(static fn (): Mailer => $container->make(Mailer::class));
    }
}
