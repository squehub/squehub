<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/** Reports an unavailable local response source without exposing its physical path. */
final class FileResponseException extends RuntimeException
{
}
