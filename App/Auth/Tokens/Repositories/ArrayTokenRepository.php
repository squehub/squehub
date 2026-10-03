<?php

declare(strict_types=1);

namespace App\Auth\Tokens\Repositories;

use App\Auth\Tokens\TokenException;
use App\Auth\Tokens\TokenRecord;
use App\Auth\Tokens\TokenRepository;
use DateTimeImmutable;

/** Application-local, deterministic token repository for tests and ephemeral use. */
final class ArrayTokenRepository implements TokenRepository
{
    /** @var array<string, TokenRecord> */
    private array $records = [];

    public function insert(TokenRecord $record): void
    {
        if (isset($this->records[$record->identifier])) {
            throw new TokenException('Token identifier already exists.');
        }
        $this->records[$record->identifier] = $record;
    }

    public function find(string $identifier): ?TokenRecord
    {
        return $this->records[$identifier] ?? null;
    }

    /** @return list<TokenRecord> */
    public function listFor(string $guard, int|string $identityId): array
    {
        $items = array_values(array_filter($this->records,
            static fn (TokenRecord $record): bool => $record->guard === $guard
                && TokenRecord::identityColumns($record->identityId)
                    === TokenRecord::identityColumns($identityId)));
        usort($items, static fn (TokenRecord $a, TokenRecord $b): int =>
            $b->createdAt <=> $a->createdAt ?: strcmp($a->identifier, $b->identifier));
        return $items;
    }

    public function touch(string $guard, string $identifier, DateTimeImmutable $at): void
    {
        $record = $this->records[$identifier] ?? null;
        if ($record !== null && $record->guard === $guard && $record->active($at)) {
            $this->records[$identifier] = $record->withLastUsedAt($at);
        }
    }

    public function revoke(string $guard, string $identifier, DateTimeImmutable $at): bool
    {
        $record = $this->records[$identifier] ?? null;
        if ($record === null || $record->guard !== $guard || $record->revokedAt !== null) return false;
        $this->records[$identifier] = $record->withRevokedAt($at);
        return true;
    }

    public function revokeAll(string $guard, int|string $identityId, DateTimeImmutable $at): int
    {
        $count = 0;
        foreach ($this->records as $identifier => $record) {
            if ($record->guard !== $guard
                || TokenRecord::identityColumns($record->identityId)
                    !== TokenRecord::identityColumns($identityId)
                || $record->revokedAt !== null) continue;
            $this->records[$identifier] = $record->withRevokedAt($at);
            ++$count;
        }
        return $count;
    }

    /** No intermediate state is published when replacement validation fails. */
    public function rotate(string $guard, string $identifier, TokenRecord $replacement, DateTimeImmutable $at): bool
    {
        $record = $this->records[$identifier] ?? null;
        if ($record === null || $record->guard !== $guard || !$record->active($at)) return false;
        if ($replacement->guard !== $guard
            || TokenRecord::identityColumns($replacement->identityId)
                !== TokenRecord::identityColumns($record->identityId)
            || isset($this->records[$replacement->identifier])) {
            throw new TokenException('Token replacement is invalid.');
        }
        $this->records[$identifier] = $record->withRevokedAt($at);
        $this->records[$replacement->identifier] = $replacement;
        return true;
    }

    public function prune(string $guard, DateTimeImmutable $cutoff): int
    {
        $count = 0;
        foreach ($this->records as $identifier => $record) {
            if ($record->guard !== $guard) continue;
            if (($record->expiresAt !== null && $record->expiresAt <= $cutoff)
                || ($record->revokedAt !== null && $record->revokedAt <= $cutoff)) {
                unset($this->records[$identifier]);
                ++$count;
            }
        }
        return $count;
    }
}
