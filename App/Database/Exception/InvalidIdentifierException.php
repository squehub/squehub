<?php

declare(strict_types=1);

namespace App\Database\Exception;

use InvalidArgumentException;

/** Rejects unsafe table or column identifiers before compiling SQL. */
final class InvalidIdentifierException extends InvalidArgumentException
{
}
