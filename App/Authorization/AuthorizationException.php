<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Http\Exception\HttpException;

/** A legitimate denial that the HTTP boundary renders as 403. */
final class AuthorizationException extends HttpException
{
    public function __construct(string $message = 'This action is not authorized.')
    {
        parent::__construct(403, $message);
    }
}
