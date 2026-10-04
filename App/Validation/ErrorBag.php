<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Read-only view of ValidationException's field-indexed messages.
 *
 * Session flash generation controls expiry; reading a field never consumes or
 * mutates its messages, so a layout and its partials can inspect the same bag.
 */
final class ErrorBag
{
    /** @param array<string, list<string>> $messages */
    public function __construct(private array $messages = [])
    {
    }

    public function has(string $field): bool { return $this->get($field) !== []; }
    public function any(): bool { return $this->messages !== []; }
    public function first(string $field): ?string { return $this->get($field)[0] ?? null; }
    /** @return list<string> */
    public function get(string $field): array { return $this->messages[$field] ?? []; }
    /** @return array<string, list<string>> */
    public function all(): array { return $this->messages; }
}
