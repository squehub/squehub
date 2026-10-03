<?php

declare(strict_types=1);

namespace App\Security\SignedUrl;

use App\Container\Container;
use App\Cryptography\CryptManager;
use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\Foundation\ServiceProvider;
use App\Foundation\UrlBasePath;
use App\Routing\RouteRegistry;

/** Wire the signer lazily; booting alone never resolves Crypt keys. */
final class SignedUrlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(SignedUrlManager::class,
            static fn (Container $container): SignedUrlManager => new SignedUrlManager(
                $container->make(RouteRegistry::class),
                $container->make(UrlBasePath::class),
                $container->make(CryptManager::class),
                $container->has(ModelClock::class)
                    ? $container->make(ModelClock::class) : new SystemModelClock()
            ));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        SignedUrl::setResolver(static fn (): SignedUrlManager => $container->make(SignedUrlManager::class));
    }
}
