<?php

declare(strict_types=1);

namespace App\Dev;

use RuntimeException;

/** A safe, actionable failure while preparing or running a local development session. */
final class DevException extends RuntimeException
{
}
