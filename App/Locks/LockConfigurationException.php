<?php

declare(strict_types=1);

namespace App\Locks;

use InvalidArgumentException;

/** Invalid lock name, lease duration, wait bound, or backend configuration. */
final class LockConfigurationException extends InvalidArgumentException
{
}
