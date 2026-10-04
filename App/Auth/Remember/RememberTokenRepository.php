<?php

declare(strict_types=1);

namespace App\Auth\Remember;

/** Rotation has one winner even when two requests present the same credential. */
interface RememberTokenRepository
{
    public function insert(RememberTokenRecord $record): void;
    public function find(string $selector): ?RememberTokenRecord;
    public function rotate(string $selector, string $validatorHash, RememberTokenRecord $replacement): bool;
    public function revokeSelector(string $selector): void;
    public function revokeIdentity(string $guard, string $identityKey): void;
}
