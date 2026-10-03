<?php

declare(strict_types=1);

namespace App\AccountSecurity;

/** A one-way digest of stored credential state, never the password or its hash. */
final class CredentialFingerprint
{
    public static function fromHash(#[\SensitiveParameter] string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }
}
