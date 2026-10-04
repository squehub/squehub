<?php

declare(strict_types=1);

namespace App\Http\Exception;

use App\Http\Response;
use RuntimeException;
use Throwable;

/** Carries a validated HTTP status and safe response headers through the Kernel. */
class HttpException extends RuntimeException
{
    public function __construct(
        private int $status,
        string $message = '',
        private array $headers = [],
        ?Throwable $previous = null
    ) {
        new Response('', $status, $headers); // Validate status and header safety at creation.
        parent::__construct($message, $status, $previous);
    }

    public function status(): int { return $this->status; }
    public function headers(): array { return $this->headers; }
}
