<?php

declare(strict_types=1);

namespace App\Kits;

use RuntimeException;

/** A safe Kit discovery, planning, or lifecycle failure. */
class KitException extends RuntimeException
{
}
