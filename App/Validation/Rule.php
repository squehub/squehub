<?php

declare(strict_types=1);

namespace App\Validation;

/** Fluent builders for database rules that need structured options. */
final class Rule
{
    public static function unique(string $table, string $column): UniqueRule
    {
        return new UniqueRule($table, $column);
    }
}
