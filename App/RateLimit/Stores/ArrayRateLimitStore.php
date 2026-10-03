<?php

declare(strict_types=1);

namespace App\RateLimit\Stores;

use App\RateLimit\FixedWindow;
use App\RateLimit\RateLimitResult;
use App\RateLimit\RateLimitStore;
use DateTimeImmutable;

/** Process-local deterministic store; each instance owns its counters. */
final class ArrayRateLimitStore implements RateLimitStore
{
    private array $records = [];

    public function consume(string $fingerprint, int $maxAttempts, int $windowSeconds, DateTimeImmutable $now): RateLimitResult
    {
        [$this->records[$fingerprint], $result] = FixedWindow::consume(
            $this->records[$fingerprint] ?? null, $maxAttempts, $windowSeconds, $now);
        return $result;
    }

    public function clear(string $fingerprint): bool
    {
        $found = isset($this->records[$fingerprint]);
        unset($this->records[$fingerprint]);
        return $found;
    }
}
