<?php

declare(strict_types=1);

namespace App\Redis;

/** Invalid Redis settings are reported without echoing credentials or URLs. */
final class RedisConfigurationException extends RedisException
{
}
