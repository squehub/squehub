<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use RuntimeException;
use Throwable;

/** Keeps a safe public migration failure and its original cause together. */
final class MigrationException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        private ?string $phase = null,
        private bool $stateUncertain = false
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function phase(): ?string
    {
        return $this->phase;
    }

    public function stateUncertain(): bool
    {
        return $this->stateUncertain;
    }
}
