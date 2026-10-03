<?php

declare(strict_types=1);

namespace App\Reliability;

/** The external operation was not invoked because the circuit denied this call. */
final class CircuitOpenException extends CircuitException
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct('Circuit is open; the external operation was not called.');
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
