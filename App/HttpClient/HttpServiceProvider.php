<?php

declare(strict_types=1);

namespace App\HttpClient;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;

/** Register outbound HTTP lazily; boot never opens a socket. */
final class HttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(HttpClient::class,
            static function (Container $container) use ($app): HttpClient {
                $settings = $app->config()->get('http_client', []);
                if (!is_array($settings)) throw new HttpConfigurationException('HTTP Client configuration must be a map.');
                return new HttpClient($settings, null,
                    $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null);
            });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        Http::setResolver(static fn (): HttpClient => $container->make(HttpClient::class));
    }
}
