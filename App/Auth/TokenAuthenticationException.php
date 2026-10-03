<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Exception\HttpException;

/** An expected Bearer denial with no token-validity detail or secret material. */
final class TokenAuthenticationException extends HttpException
{
    public function __construct()
    {
        parent::__construct(401, 'Authentication is required.', ['WWW-Authenticate' => 'Bearer']);
    }
}
