<?php

declare(strict_types=1);

namespace App\OAuth;

use RuntimeException;
use Throwable;

/** A safe configuration or protocol failure; credential values never enter its message. */
class OAuthException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
