<?php

declare(strict_types=1);

namespace App\Auth\Contracts;

/** Read-only identity access also fits future guards without mutable sessions. */
interface AuthGuard
{
    public function user(): ?Authenticatable;

    public function id(): int|string|null;

    public function check(): bool;

    public function guest(): bool;

    public function resetRequestState(): void;
}
