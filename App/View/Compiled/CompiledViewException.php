<?php

declare(strict_types=1);

namespace App\View\Compiled;

/**
 * Reports a compiled View storage failure without exposing physical paths or
 * generated PHP to an HTTP response or a CLI diagnostic.
 */
final class CompiledViewException extends \RuntimeException
{
}
