<?php

declare(strict_types=1);

namespace App\Auth\Contracts;

/** An application identity supplies its stable key and stored password hash. */
interface Authenticatable
{
    public function authIdentifier(): int|string;

    public function authPasswordHash(): string;
}
