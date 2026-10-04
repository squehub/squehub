<?php

declare(strict_types=1);

namespace App\Validation;

/** Return null on success or a safe, user-facing failure message. */
interface ValidationRule
{
    public function validate(string $field, mixed $value, array $data): ?string;
}
