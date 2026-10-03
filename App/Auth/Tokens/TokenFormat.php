<?php

declare(strict_types=1);

namespace App\Auth\Tokens;

use App\Support\SecureRandom;

/** Opaque indexed identifier plus an independent 256-bit bearer secret. */
final class TokenFormat
{
    private const PREFIX = 'sqh_pat_';

    /** @return array{identifier:string, token:string, hash:string} */
    public static function generate(): array
    {
        // The shared generator enforces a 16-byte minimum. Its first 16
        // base64url characters still carry 96 independent random bits.
        $identifier = substr(SecureRandom::token(16), 0, 16);
        $secret = SecureRandom::token(32);
        return [
            'identifier' => $identifier,
            'token' => self::PREFIX . $identifier . '_' . $secret,
            'hash' => hash('sha256', $secret),
        ];
    }

    /** @return ?array{identifier:string, hash:string} */
    public static function parse(#[\SensitiveParameter] string $token): ?array
    {
        if (strlen($token) !== 68
            || preg_match('/\Asqh_pat_([A-Za-z0-9_-]{16})_([A-Za-z0-9_-]{43})\z/D', $token, $parts) !== 1) {
            return null;
        }
        return ['identifier' => $parts[1], 'hash' => hash('sha256', $parts[2])];
    }

    public static function validIdentifier(string $identifier): bool
    {
        return strlen($identifier) === 16
            && preg_match('/\A[A-Za-z0-9_-]{16}\z/D', $identifier) === 1;
    }
}
