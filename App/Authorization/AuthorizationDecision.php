<?php

declare(strict_types=1);

namespace App\Authorization;

/** Immutable result of one policy or global ability evaluation. */
final readonly class AuthorizationDecision
{
    private function __construct(private bool $allowed, private ?string $message)
    {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(string $message = 'This action is not authorized.'): self
    {
        return new self(false, $message);
    }

    public function allowed(): bool { return $this->allowed; }
    public function denied(): bool { return !$this->allowed; }
    public function message(): ?string { return $this->message; }
}
