<?php

declare(strict_types=1);

namespace App\Auth\Tokens;

/** The sole return path for a newly issued raw token; never persist or log this value. */
final readonly class IssuedToken
{
    public function __construct(private string $token, private TokenMetadata $metadata)
    {
    }

    public function token(): string { return $this->token; }
    public function metadata(): TokenMetadata { return $this->metadata; }

    public function __debugInfo(): array
    {
        return ['token' => '[REDACTED]', 'metadata' => $this->metadata];
    }

    public function __serialize(): array
    {
        throw new TokenException('Issued tokens cannot be serialized.');
    }
}
