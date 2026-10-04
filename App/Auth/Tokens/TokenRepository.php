<?php

declare(strict_types=1);

namespace App\Auth\Tokens;

use DateTimeImmutable;

/** Hash-only persistence contract; rotation must revoke and insert atomically. */
interface TokenRepository
{
    public function insert(TokenRecord $record): void;
    public function find(string $identifier): ?TokenRecord;
    /** @return list<TokenRecord> */
    public function listFor(string $guard, int|string $identityId): array;
    public function touch(string $guard, string $identifier, DateTimeImmutable $at): void;
    public function revoke(string $guard, string $identifier, DateTimeImmutable $at): bool;
    public function revokeAll(string $guard, int|string $identityId, DateTimeImmutable $at): int;
    public function rotate(string $guard, string $identifier, TokenRecord $replacement, DateTimeImmutable $at): bool;
    public function prune(string $guard, DateTimeImmutable $cutoff): int;
}
