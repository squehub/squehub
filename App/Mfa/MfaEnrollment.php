<?php

declare(strict_types=1);

namespace App\Mfa;

use LogicException;

/** Give the setup secret and URI to the authenticated user only at generation. */
final readonly class MfaEnrollment
{
    public function __construct(private string $secret, private string $uri, public int $expiresAt)
    {
    }

    public function secret(): string { return $this->secret; }
    public function provisioningUri(): string { return $this->uri; }

    public function __debugInfo(): array { return ['secret' => '[REDACTED]', 'uri' => '[REDACTED]']; }

    public function __serialize(): array
    {
        throw new LogicException('MFA provisioning information cannot be serialized.');
    }
}
