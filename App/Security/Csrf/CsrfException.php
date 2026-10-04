<?php

declare(strict_types=1);

namespace App\Security\Csrf;

use App\Http\Exception\HttpException;

/** A rejected unsafe request; the response must never reveal token material. */
final class CsrfException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'CSRF verification failed.');
    }
}
