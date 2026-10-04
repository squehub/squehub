<?php

declare(strict_types=1);

namespace App\AccountSecurity\Contracts;

use App\Auth\Contracts\Authenticatable;
use DateTimeImmutable;

/** Optional identity-source capability; password-only providers need no email API. */
interface EmailVerificationIdentityProvider
{
    public function verificationAddress(Authenticatable $identity): string;
    public function emailVerified(Authenticatable $identity): bool;
    public function markEmailVerified(Authenticatable $identity, DateTimeImmutable $verifiedAt): void;
}
