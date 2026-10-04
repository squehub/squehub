<?php

declare(strict_types=1);

namespace App\Validation;

use App\Foundation\ServiceProvider;

/** Registers validation construction without opening a database connection. */
final class ValidationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(ValidatorFactory::class);
    }
}
