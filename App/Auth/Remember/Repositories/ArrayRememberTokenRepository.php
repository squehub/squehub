<?php

declare(strict_types=1);

namespace App\Auth\Remember\Repositories;

use App\Auth\Remember\RememberException;
use App\Auth\Remember\RememberTokenRecord;
use App\Auth\Remember\RememberTokenRepository;

/** Application-local deterministic repository; never shared across Applications. */
final class ArrayRememberTokenRepository implements RememberTokenRepository
{
    /** @var array<string, RememberTokenRecord> */
    private array $records = [];

    public function insert(RememberTokenRecord $record): void
    {
        if (isset($this->records[$record->selector])) throw new RememberException('Remember selector already exists.');
        $this->records[$record->selector] = $record;
    }

    public function find(string $selector): ?RememberTokenRecord
    {
        return $this->records[$selector] ?? null;
    }

    public function rotate(string $selector, string $validatorHash, RememberTokenRecord $replacement): bool
    {
        $old = $this->find($selector);
        if ($old === null || !hash_equals($old->validatorHash, $validatorHash)) return false;
        if ($replacement->guard !== $old->guard || $replacement->identityKey !== $old->identityKey
            || isset($this->records[$replacement->selector])) {
            throw new RememberException('Remember replacement is invalid.');
        }
        unset($this->records[$selector]);
        $this->records[$replacement->selector] = $replacement;
        return true;
    }

    public function revokeSelector(string $selector): void { unset($this->records[$selector]); }

    public function revokeIdentity(string $guard, string $identityKey): void
    {
        foreach ($this->records as $selector => $record) {
            if ($record->guard === $guard && $record->identityKey === $identityKey) {
                unset($this->records[$selector]);
            }
        }
    }
}
