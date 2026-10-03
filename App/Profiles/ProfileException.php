<?php

declare(strict_types=1);

namespace App\Profiles;

use RuntimeException;

/** A bounded profile planning or publication failure. Source contents stay private. */
final class ProfileException extends RuntimeException
{
}
