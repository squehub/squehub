<?php

declare(strict_types=1);

namespace App\Database\Seeding;

use RuntimeException;

/** Failure to resolve or execute an application Seeder. */
final class SeederException extends RuntimeException
{
}
