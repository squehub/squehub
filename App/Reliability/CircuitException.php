<?php

declare(strict_types=1);

namespace App\Reliability;

use RuntimeException;

/** Invalid policy or unavailable/corrupt circuit state; no key or backend path is exposed. */
class CircuitException extends RuntimeException
{
}
