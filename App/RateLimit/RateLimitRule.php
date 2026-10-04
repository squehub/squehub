<?php

declare(strict_types=1);

namespace App\RateLimit;

/** Immutable named-request policy; only a digest of the chosen identity is kept. */
final readonly class RateLimitRule
{
    private function __construct(
        private string $keyDigest,
        private int $maxAttempts,
        private int $windowSeconds
    ) {
    }

    public static function fixed(
        #[\SensitiveParameter] string $key,
        int $maxAttempts,
        int $windowSeconds
    ): self {
        if ($maxAttempts < 1 || $maxAttempts >= PHP_INT_MAX || $windowSeconds < 1) {
            throw new RateLimitException('Rate-limit attempts and window seconds must be positive.');
        }
        return new self(RateLimitKey::digest($key), $maxAttempts, $windowSeconds);
    }

    /** @internal A digest, never the resolver's original identity. */
    public function keyDigest(): string { return $this->keyDigest; }
    public function maxAttempts(): int { return $this->maxAttempts; }
    public function windowSeconds(): int { return $this->windowSeconds; }
}
