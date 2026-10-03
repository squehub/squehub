<?php

declare(strict_types=1);

namespace App\Mfa;

use App\Auth\Contracts\PrimaryAuthenticationGate;
use App\Container\Container;
use App\Foundation\ServiceProvider;

/** Bind the optional primary-factor gate before Auth creates its first guard. */
final class MfaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();
        $container->singleton(MfaManager::class, static fn (Container $container): MfaManager =>
            new MfaManager($container));
        $container->singleton(PrimaryAuthenticationGate::class,
            static fn (Container $container): MfaManager => $container->make(MfaManager::class));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        // Validate configuration at boot without opening storage or decoding a key.
        $container->make(MfaManager::class);
        Mfa::setResolver(static fn (): MfaManager => $container->make(MfaManager::class));
    }
}
