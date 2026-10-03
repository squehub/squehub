<?php

declare(strict_types=1);

namespace App\Foundation;

/** Register bindings first; boot after all providers have registered. */
abstract class ServiceProvider
{
    public function __construct(protected Application $app)
    {
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
