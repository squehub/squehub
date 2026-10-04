<?php

declare(strict_types=1);

namespace App\AccountSecurity;

use App\Auth\Contracts\Authenticatable;

/** One named guard choice without changing the default Auth or Session state. */
final readonly class AccountSecurityContext
{
    public function __construct(private AccountSecurityManager $security, private string $guard)
    {
    }

    public function changePassword(#[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword): bool
    {
        return $this->security->changePasswordFor($this->guard, $currentPassword, $newPassword);
    }

    public function issuePasswordReset(array $credentials): ?SecurityToken
    {
        return $this->security->issuePasswordResetFor($this->guard, $credentials);
    }

    public function resetPassword(#[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $newPassword): bool
    {
        return $this->security->resetPasswordFor($this->guard, $token, $newPassword);
    }

    public function issueEmailVerification(Authenticatable $identity): ?SecurityToken
    {
        return $this->security->issueEmailVerificationFor($this->guard, $identity);
    }

    public function verifyEmail(#[\SensitiveParameter] string $token): bool
    {
        return $this->security->verifyEmailFor($this->guard, $token);
    }
}
