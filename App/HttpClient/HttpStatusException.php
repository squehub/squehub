<?php

declare(strict_types=1);

namespace App\HttpClient;

/** Explicit response throw() reports only the status, never server body text. */
final class HttpStatusException extends HttpClientException
{
    public function __construct(private int $status)
    {
        parent::__construct('External HTTP service returned status ' . $status . '.');
    }

    public function status(): int { return $this->status; }
}
