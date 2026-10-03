<?php

declare(strict_types=1);

namespace App\Logging;

use RuntimeException;

/** Raised when a configured logging operation cannot be completed. */
final class LoggingException extends RuntimeException
{
}
