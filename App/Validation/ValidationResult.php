<?php

declare(strict_types=1);

namespace App\Validation;

/** Immutable outcome of a non-throwing validation check. */
final class ValidationResult
{
    public function __construct(private array $errors, private array $validated)
    {
    }

    public function passes(): bool { return $this->errors === []; }
    public function fails(): bool { return !$this->passes(); }
    public function errors(): array { return $this->errors; }
    public function validated(): array { return $this->validated; }
    public function first(string $field): ?string { return $this->errors[$field][0] ?? null; }
}
