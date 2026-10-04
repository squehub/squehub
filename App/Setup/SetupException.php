<?php

declare(strict_types=1);

namespace App\Setup;

use RuntimeException;

/** Safe, content-free configuration or filesystem failure during SqueHub Setup. */
final class SetupException extends RuntimeException
{
}
