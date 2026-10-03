<?php

declare(strict_types=1);

namespace App\Idempotency;

use RuntimeException;

/** Storage, configuration, and expired-ownership failures never include request data. */
final class IdempotencyException extends RuntimeException
{
}
