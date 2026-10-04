<?php

declare(strict_types=1);

namespace App\AccountSecurity;

use DateTimeImmutable;

/** Plaintext token is returned once to trusted application code, never persisted. */
final readonly class SecurityToken
{
    public function __construct(private string $token, private DateTimeImmutable $expiresAt)
    {
    }

    public function token(): string { return $this->token; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }

    public function __debugInfo(): array { return ['token' => '[REDACTED]', 'expires_at' => $this->expiresAt]; }
}
