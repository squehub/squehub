<?php

declare(strict_types=1);

namespace App\RateLimit;

use DateTimeImmutable;

/**
 * Atomic fixed-window storage contract. Decision and counter update are one
 * operation; a Cache read/modify/write sequence would permit concurrent bypass.
 * Only SHA-256 fingerprints cross this boundary.
 */
interface RateLimitStore
{
    public function consume(string $fingerprint, int $maxAttempts, int $windowSeconds, DateTimeImmutable $now): RateLimitResult;
    public function clear(string $fingerprint): bool;
}
