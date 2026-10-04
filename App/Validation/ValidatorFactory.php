<?php

declare(strict_types=1);

namespace App\Validation;

use App\Container\Container;

/** Creates independent validators using the Application's container. */
final class ValidatorFactory
{
    public function __construct(private Container $container)
    {
    }

    public function for(array $data): Validator
    {
        return new Validator($data, $this->container);
    }
}
