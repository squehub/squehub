<?php

declare(strict_types=1);

namespace App\Http\Exception;

/** Marks an unmatched route as an HTTP 404 without exposing request data. */
final class NotFoundHttpException extends HttpException
{
    public function __construct(string $message = 'Not Found')
    {
        parent::__construct(404, $message);
    }
}
