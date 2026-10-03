<?php

declare(strict_types=1);

namespace App\Recovery;

use RuntimeException;

/** A safe, bounded failure while inspecting an operator-supplied recovery artifact. */
final class RecoveryException extends RuntimeException
{
}
