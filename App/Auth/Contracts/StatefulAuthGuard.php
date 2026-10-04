<?php

declare(strict_types=1);

namespace App\Auth\Contracts;

/** A guard that can establish and invalidate authentication state. */
interface StatefulAuthGuard extends AuthGuard
{
    /** @param array<string, mixed> $credentials */
    public function attempt(array $credentials, bool $remember = false): bool;

    public function login(Authenticatable $identity, bool $remember = false): void;

    public function logout(): void;
}
