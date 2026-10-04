<?php

declare(strict_types=1);

namespace App\AccountSecurity;

use App\Support\SecureRandom;

/** Uses 256 random bits; a failed CSPRNG never falls back to weaker material. */
final class SecurityTokenGenerator
{
    public function generate(): string
    {
        return SecureRandom::token(32);
    }

    public static function valid(#[\SensitiveParameter] string $token): bool
    {
        return strlen($token) === 43 && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) === 1;
    }
}
