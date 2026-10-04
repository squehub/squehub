<?php

declare(strict_types=1);

namespace App\Packages;

use RuntimeException;

/** A safe Package validation or lifecycle failure without source credentials. */
final class PackageException extends RuntimeException
{
}
