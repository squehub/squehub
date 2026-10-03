<?php

declare(strict_types=1);

namespace App\Profiler;

use RuntimeException;

/** A safe configuration or local profile-storage failure. */
final class ProfilerException extends RuntimeException
{
}
