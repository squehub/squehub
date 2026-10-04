<?php

declare(strict_types=1);

namespace App\Cache;

use RuntimeException;

/** Reports cache configuration, storage, and value failures without disclosing keys or values. */
final class CacheException extends RuntimeException
{
}
