<?php

declare(strict_types=1);

namespace App\Locks;

use App\Database\ModelClock;
use DateTimeImmutable;

/** A bounded lease result; acquired() records the initial outcome, not fencing. */
final class LockHandle
{
    private bool $released = false;

    /** @internal Constructed by LockManager. */
    public function __construct(
        private LockStore $store,
        private ModelClock $clock,
        private string $hash,
        #[\SensitiveParameter] private ?string $token,
        private ?int $expiry
    ) {
    }

    public function acquired(): bool
    {
        return $this->token !== null;
    }

    /** Explicitly available to callers that need to identify their lease. */
    public function ownerToken(): ?string
    {
        return $this->token;
    }

    /** Estimated UTC expiry; Redis uses its own server TTL as authority. */
    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiry === null ? null : new DateTimeImmutable('@' . $this->expiry);
    }

    public function released(): bool
    {
        return $this->released;
    }

    /** A missing or expired own lease returns false; a different live owner throws. */
    public function release(): bool
    {
        if ($this->token === null || $this->released) return false;
        $removed = $this->store->release($this->hash, $this->token,
            $this->clock->now()->getTimestamp());
        $this->released = true;
        return $removed;
    }

    /** Never leak the key digest or ownership token through a dump. */
    public function __debugInfo(): array
    {
        return ['acquired' => $this->acquired(), 'released' => $this->released,
            'expires_at' => $this->expiresAt()?->format(DATE_ATOM)];
    }
}
