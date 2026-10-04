<?php

declare(strict_types=1);

namespace App\Auth\Contracts;

/** Persistence boundary; password verification and session state belong to the guard. */
interface IdentityProvider
{
    public function retrieveById(int|string $identifier): ?Authenticatable;

    /** @param array<string, int|string> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable;

    public function updatePassword(Authenticatable $identity, string $passwordHash): void;

    public function supports(Authenticatable $identity): bool;
}
