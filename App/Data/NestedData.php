<?php

declare(strict_types=1);

namespace App\Data;

use Attribute;

/**
 * Opt a typed constructor parameter into recursive data mapping.
 *
 * The declared parameter type, rather than an input value, selects the nested
 * class. Other object-typed parameters are rejected, so a request cannot ask
 * the mapper to resolve arbitrary application services or object graphs.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class NestedData
{
}
