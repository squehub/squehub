<?php

declare(strict_types=1);

namespace App\Locks;

use RuntimeException;

/** Safe public boundary for lock coordination failures. */
class LockException extends RuntimeException
{
}
