<?php

declare(strict_types=1);

namespace App\AccountSecurity;

use App\Foundation\ServiceProvider;

/** Registers the lazy account-security service without opening a token store. */
final class AccountSecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(AccountSecurityManager::class);
    }

    public function boot(): void
    {
        $manager = $this->app->container()->make(AccountSecurityManager::class);
        AccountSecurity::setResolver(static fn (): AccountSecurityManager => $manager);
    }
}
