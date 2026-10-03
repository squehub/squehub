<?php

declare(strict_types=1);

namespace App\AccountSecurity\Repositories;

use App\AccountSecurity\AccountSecurityConfigurationException;
use App\AccountSecurity\SecurityTokenRecord;
use App\AccountSecurity\SecurityTokenRepository;
use DateTimeImmutable;

/** Deterministic, Application-local token storage for tests and ephemeral use. */
final class ArraySecurityTokenRepository implements SecurityTokenRepository
{
    /** @var array<string, SecurityTokenRecord> */
    private array $records = [];

    public function replace(SecurityTokenRecord $record): void
    {
        if (isset($this->records[$record->tokenHash])) {
            throw new AccountSecurityConfigurationException('Security token hash already exists.');
        }
        $this->revoke($record->purpose, $record->guard, $record->identityKey);
        $this->records[$record->tokenHash] = $record;
    }

    public function claim(string $tokenHash, string $purpose, string $guard, DateTimeImmutable $now): ?SecurityTokenRecord
    {
        $record = $this->records[$tokenHash] ?? null;
        if ($record === null || $record->purpose !== $purpose || $record->guard !== $guard) return null;
        unset($this->records[$tokenHash]); // Claim precedes any protected account mutation.
        return $record->expired($now) ? null : $record;
    }

    public function revoke(string $purpose, string $guard, string $identityKey): void
    {
        foreach ($this->records as $hash => $record) {
            if ($record->purpose === $purpose && $record->guard === $guard
                && $record->identityKey === $identityKey) unset($this->records[$hash]);
        }
    }
}
