<?php

declare(strict_types=1);

namespace App\Cryptography;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;

/** Register Crypt lazily; boot does not decode keys or use an extension. */
final class CryptServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;
        $app->container()->singleton(CryptManager::class,
            static function (Container $container) use ($app): CryptManager {
                $settings = $app->config()->get('crypt', []);
                if (!is_array($settings)) {
                    throw new CryptConfigurationException('Cryptographic configuration must be a map.');
                }
                return new CryptManager($settings,
                    $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null);
            });
    }

    public function boot(): void
    {
        $container = $this->app->container();
        Crypt::setResolver(static fn (): CryptManager => $container->make(CryptManager::class));
    }
}
