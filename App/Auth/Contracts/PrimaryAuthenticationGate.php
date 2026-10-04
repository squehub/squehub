<?php

declare(strict_types=1);

namespace App\Auth\Contracts;

/**
 * Internal transition hook. Returning false means a bounded second-factor
 * challenge was created and no fully authenticated Session may be written.
 */
interface PrimaryAuthenticationGate
{
    public function begin(Authenticatable $identity, string $guard, string $source,
        bool $rememberRequested): bool;
}
