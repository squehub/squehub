<?php

declare(strict_types=1);

namespace App\Validation;

use RuntimeException;
use Throwable;

/** Contains safe field errors only; never retain raw request data. */
final class ValidationException extends RuntimeException
{
    public function __construct(private array $errors, ?Throwable $previous = null)
    {
        parent::__construct('Validation failed.', 422, $previous);
    }

    public function errors(): array { return $this->errors; }
    public function status(): int { return 422; }
}
