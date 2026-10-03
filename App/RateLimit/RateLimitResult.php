<?php

declare(strict_types=1);

namespace App\RateLimit;

use DateTimeImmutable;
use DateTimeZone;

/** Immutable post-consumption decision; reset time is always expressed in UTC. */
final readonly class RateLimitResult
{
    private DateTimeImmutable $resetsAt;

    public function __construct(
        private bool $allowed,
        private int $limit,
        private int $remaining,
        private int $retryAfter,
        DateTimeImmutable $resetsAt
    ) {
        if ($limit < 1 || $remaining < 0 || $retryAfter < 0) {
            throw new RateLimitException('Invalid rate-limit result.');
        }
        $this->resetsAt = $resetsAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function allowed(): bool { return $this->allowed; }
    public function denied(): bool { return !$this->allowed; }
    public function limit(): int { return $this->limit; }
    public function remaining(): int { return $this->remaining; }
    public function retryAfter(): int { return $this->retryAfter; }
    public function resetsAt(): DateTimeImmutable { return $this->resetsAt; }
}
