<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Validation\Rule as RuleGateway;
use App\Validation\UniqueRule;

/** Structured validation rule builders from the canonical validation API. */
final class Rule
{
    public static function unique(string $table, string $column): UniqueRule
    {
        return RuleGateway::unique($table, $column);
    }
}
