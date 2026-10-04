<?php

declare(strict_types=1);

namespace App\RateLimit;

use App\Http\Exception\HttpException;

/** Legitimate policy denial, carrying safe numeric headers but no identity. */
final class RateLimitExceededException extends HttpException
{
    public function __construct(private RateLimitResult $result)
    {
        parent::__construct(429, 'Too many requests.', [
            'Retry-After' => (string) $result->retryAfter(),
            'X-RateLimit-Limit' => (string) $result->limit(),
            'X-RateLimit-Remaining' => (string) $result->remaining(),
            'X-RateLimit-Reset' => (string) $result->resetsAt()->getTimestamp(),
        ]);
    }

    public function result(): RateLimitResult { return $this->result; }
}
