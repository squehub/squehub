<?php

declare(strict_types=1);

namespace App\Idempotency;

/** One atomic store decision; only claimed requests may execute the handler. */
final readonly class ClaimResult
{
    public function __construct(public string $state, public ?ResponseSnapshot $snapshot = null)
    {
        if (!in_array($state, ['claimed', 'replay', 'conflict', 'in_progress', 'unreplayable'], true)
            || ($state === 'replay') !== ($snapshot !== null)) {
            throw new IdempotencyException('Invalid idempotency claim result.');
        }
    }

    public static function fromRecord(array $record, string $fingerprint): self
    {
        if (!hash_equals($record['fingerprint'], $fingerprint)) return new self('conflict');
        if ($record['state'] === 'processing') return new self('in_progress');
        return $record['snapshot'] === null ? new self('unreplayable')
            : new self('replay', ResponseSnapshot::fromArray($record['snapshot']));
    }
}
