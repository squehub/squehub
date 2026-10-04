<?php

declare(strict_types=1);

namespace App\Http\Exception;

/** Carries a 405 response and its safe Allow header from route matching. */
final class MethodNotAllowedHttpException extends HttpException
{
    public function __construct(string $message = 'Method Not Allowed', array $headers = [])
    {
        parent::__construct(405, $message, $headers);
    }
}
