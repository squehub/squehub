<?php

declare(strict_types=1);

namespace App\Cache;

/** A found entry, including a cached null, with an optional Unix expiry second. */
final class CacheEntry
{
    public function __construct(public readonly mixed $value, public readonly ?int $expiresAt)
    {
    }

    public function expired(int $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }
}
