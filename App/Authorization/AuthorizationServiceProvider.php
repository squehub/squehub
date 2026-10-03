<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Auth\AuthManager;
use App\Authorization\Rbac\RbacManager;
use App\Config\Repository;
use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;

/** Registers one Application-owned manager and validates configured rules at boot. */
final class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(AuthorizationManager::class,
            static fn (Container $container): AuthorizationManager => new AuthorizationManager(
                $container->make(AuthManager::class), $container,
                $container->has(Diagnostics::class) ? $container->make(Diagnostics::class) : null,
                $container->has(RbacManager::class) ? $container->make(RbacManager::class) : null
            ));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $authorization = $container->make(AuthorizationManager::class);
        $config = $container->make(Repository::class)->get('authorization', []);
        if (!is_array($config) || array_diff(array_keys($config), ['abilities', 'policies']) !== []) {
            throw new AuthorizationConfigurationException('Authorization configuration must contain only abilities and policies.');
        }
        $abilities = $config['abilities'] ?? [];
        $policies = $config['policies'] ?? [];
        if (!is_array($abilities) || !is_array($policies)) {
            throw new AuthorizationConfigurationException('Authorization abilities and policies must be maps.');
        }
        foreach ($abilities as $name => $rule) {
            // Cacheable configuration uses class names. Runtime callables may
            // be registered deliberately in a later application provider.
            if (!is_string($name) || !is_string($rule)) {
                throw new AuthorizationConfigurationException('Configured abilities must map names to rule classes.');
            }
            $authorization->define($name, $rule);
        }
        foreach ($policies as $subject => $policy) {
            if (!is_string($subject) || !is_string($policy)) {
                throw new AuthorizationConfigurationException('Configured policies must map classes to policy classes.');
            }
            $authorization->policy($subject, $policy);
        }
        Authorization::setResolver(static fn (): AuthorizationManager => $authorization);
    }
}
