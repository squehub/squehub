<?php

declare(strict_types=1);

namespace App\RateLimit;

use RuntimeException;

/** Signals invalid limiter configuration or an unavailable atomic backend. */
class RateLimitException extends RuntimeException
{
}
