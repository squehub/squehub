<?php

declare(strict_types=1);

namespace App\Observability;

use InvalidArgumentException;

/**
 * One Application's current logical-operation identifier. It is independent
 * of trace/span IDs and never accepts an unvalidated client or queue value.
 * Long-running boundaries must clear it after each request, job, or tick.
 */
final class CorrelationContext
{
    private ?string $id = null;

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function valid(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1;
    }

    public function current(): ?string { return $this->id; }

    /** Replace the active operation; null creates a fresh local identity. */
    public function begin(?string $id = null): string
    {
        $id ??= self::generate();
        if (!self::valid($id)) {
            throw new InvalidArgumentException('Correlation ID is invalid.');
        }
        return $this->id = $id;
    }

    public function clear(): void { $this->id = null; }

    /** Restore the caller's context after a nested scheduler or Sync Queue run. */
    public function with(string $id, callable $callback): mixed
    {
        if (!self::valid($id)) {
            throw new InvalidArgumentException('Correlation ID is invalid.');
        }
        $previous = $this->id;
        $this->id = $id;
        try { return $callback(); }
        finally { $this->id = $previous; }
    }
}
