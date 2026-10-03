<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Auth\Contracts\Authenticatable;

/** A Container-resolved private-channel rule; only server identity is supplied. */
interface ChannelAuthorizer
{
    /** @param array<string,string> $parameters */
    public function authorize(Authenticatable $identity, array $parameters): bool;
}
