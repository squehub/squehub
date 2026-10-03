<?php

declare(strict_types=1);

namespace App\Cache\Drivers;

/** A value and its compare-and-swap token from one Memcached read. */
final class MemcachedRecord
{
    public function __construct(
        public readonly string|int $value,
        public readonly string|int|float $token
    ) {
    }
}
