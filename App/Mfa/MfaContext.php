<?php

declare(strict_types=1);

namespace App\Mfa;

/** One named session guard without changing Auth's request-selected guard. */
final readonly class MfaContext
{
    public function __construct(private MfaManager $manager, private string $guard)
    {
    }

    public function enabled(): bool { return $this->manager->enabledFor($this->guard); }
    public function beginEnrollment(string $accountLabel): MfaEnrollment
    {
        return $this->manager->beginEnrollmentFor($this->guard, $accountLabel);
    }
    /** @return list<string>|null Raw recovery codes appear only on successful confirmation. */
    public function confirmEnrollment(#[\SensitiveParameter] string $code): ?array
    {
        return $this->manager->confirmEnrollmentFor($this->guard, $code);
    }
    public function pending(): bool { return $this->manager->pendingFor($this->guard); }
    public function completeChallenge(#[\SensitiveParameter] string $proof): bool
    {
        return $this->manager->completeChallengeFor($this->guard, $proof);
    }
    /** @return list<string>|null */
    public function regenerateRecoveryCodes(#[\SensitiveParameter] string $proof): ?array
    {
        return $this->manager->regenerateRecoveryCodesFor($this->guard, $proof);
    }
    public function disable(#[\SensitiveParameter] string $proof): bool
    {
        return $this->manager->disableFor($this->guard, $proof);
    }
}
