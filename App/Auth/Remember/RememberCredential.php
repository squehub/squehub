<?php

declare(strict_types=1);

namespace App\Auth\Remember;

use App\Http\Cookie;

/** Issued browser credential kept only until the response is decorated. */
final readonly class RememberCredential implements \JsonSerializable
{
    public function __construct(public string $selector, public Cookie $cookie)
    {
    }

    public function __debugInfo(): array { return ['credential' => '[REDACTED]']; }

    public function jsonSerialize(): array { return []; }

    public function __serialize(): array
    {
        throw new RememberException('Remember credentials cannot be serialized.');
    }
}
