<?php

declare(strict_types=1);

namespace App\Auth\Tokens;

use App\Auth\Contracts\Authenticatable;

/** Request-scoped authenticated identity and the token's safe ability boundary. */
final readonly class TokenAuthentication
{
    public function __construct(private Authenticatable $identity, private TokenMetadata $metadata)
    {
    }

    public function identity(): Authenticatable { return $this->identity; }
    public function metadata(): TokenMetadata { return $this->metadata; }
}
