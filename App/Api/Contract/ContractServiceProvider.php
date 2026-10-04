<?php

declare(strict_types=1);

namespace App\Api\Contract;

use App\Config\Repository;
use App\Container\Container;
use App\Foundation\ServiceProvider;
use App\Routing\RouteRegistry;

/** Register a per-Application registry without loading routes or contacting services. */
final class ContractServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(ContractManager::class,
            static fn (Container $container): ContractManager => new ContractManager(
                $container->make(RouteRegistry::class),
                $container->make(Repository::class),
                $container->make(OpenApiCompiler::class)
            ));
        $this->app->container()->singleton(ContractVerifier::class);
    }

    public function boot(): void
    {
        $container = $this->app->container();
        Contract::setResolver(static fn (): ContractManager => $container->make(ContractManager::class));
    }
}
