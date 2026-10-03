<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Validation\Validator as BaseValidator;

/** Starts the existing standalone validator; Request::validate remains available. */
final class Validator
{
    public static function for(array $data): BaseValidator { return new BaseValidator($data); }
}
