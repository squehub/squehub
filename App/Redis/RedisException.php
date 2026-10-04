<?php

declare(strict_types=1);

namespace App\Redis;

use RuntimeException;

/** A safe public boundary for Redis client and server failures. */
class RedisException extends RuntimeException
{
}
