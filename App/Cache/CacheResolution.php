<?php

declare(strict_types=1);

namespace App\Cache;

/** Tells the store whether remember resolved an existing entry or created one. */
final class CacheResolution
{
    public function __construct(public readonly CacheEntry $entry, public readonly bool $created)
    {
    }
}
