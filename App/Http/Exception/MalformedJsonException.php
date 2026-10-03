<?php

declare(strict_types=1);

namespace App\Http\Exception;

/** Rejects an invalid JSON body without retaining submitted content. */
final class MalformedJsonException extends HttpException
{
    public function __construct()
    {
        parent::__construct(400, 'Malformed JSON request body.');
    }
}
