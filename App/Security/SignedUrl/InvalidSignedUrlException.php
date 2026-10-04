<?php

declare(strict_types=1);

namespace App\Security\SignedUrl;

use App\Http\Exception\HttpException;

/** One public failure for absent, malformed, expired, or incorrect links. */
final class InvalidSignedUrlException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'Invalid signed URL.');
    }
}
