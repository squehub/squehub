<?php

declare(strict_types=1);

namespace App\Auth\Remember;

use App\Auth\Contracts\Authenticatable;

/** A validated primary factor and its still-unpublished rotated cookie. */
final readonly class RememberedIdentity implements \JsonSerializable
{
    public function __construct(public Authenticatable $identity, public RememberCredential $credential)
    {
    }

    public function __debugInfo(): array { return ['remembered' => '[REDACTED]']; }

    public function jsonSerialize(): array { return []; }
}
