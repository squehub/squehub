<?php

declare(strict_types=1);

namespace App\Support;

/** Shared key policy for old input, debug redaction, and structured logging. */
final class SensitiveKey
{
    /** Treat spelling and case variants of credential-bearing keys as sensitive. */
    public static function matches(string $key): bool
    {
        return (bool) preg_match('/password|secret|token|authorization|bearer|cookie|session|csrf|api[_-]?key|access[_-]?key|app[_-]?(?:previous[_-]?)?key|crypt[_-]?key|encryption[_-]?key|signing[_-]?key|credential|private[_-]?key|redis[_-]?url|tmp_name|code[_-]?verifier|oauth[_-]?state|oidc[_-]?nonce|webhook[_-]?signature|sqh[_-]?signature|(?:totp|otp)[_-]?code|recovery[_-]?codes?|mfa[_-]?(?:proof|code)|provisioning[_-]?uri/i', $key);
    }
}
