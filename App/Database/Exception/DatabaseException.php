<?php

declare(strict_types=1);

namespace App\Database\Exception;

use RuntimeException;

/** Base failure for the modern database manager and connection layer. */
class DatabaseException extends RuntimeException
{
}
