<?php

declare(strict_types=1);

namespace App\Database\Casts;

use InvalidArgumentException;

/** Reports a model cast failure without including the attribute's submitted value. */
final class CastException extends InvalidArgumentException
{
}
