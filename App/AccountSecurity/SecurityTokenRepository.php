<?php

declare(strict_types=1);

namespace App\AccountSecurity;

use DateTimeImmutable;

/** Replacement and claim must be atomic enough that one token has one winner. */
interface SecurityTokenRepository
{
    public function replace(SecurityTokenRecord $record): void;
    public function claim(string $tokenHash, string $purpose, string $guard, DateTimeImmutable $now): ?SecurityTokenRecord;
    public function revoke(string $purpose, string $guard, string $identityKey): void;
}
