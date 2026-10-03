<?php

declare(strict_types=1);

namespace App\Reliability;

use App\HttpClient\HttpResponse;
use App\HttpClient\HttpStatusException;
use Throwable;

/**
 * Explicit consecutive-failure policy for one logical external operation.
 * No arbitrary application exception or non-selected HTTP status is counted.
 */
final readonly class CircuitPolicy
{
    /**
     * @param list<class-string<Throwable>> $failureExceptions
     * @param list<int> $failureHttpStatuses
     */
    public function __construct(
        public int $failureThreshold,
        public int $cooldownSeconds,
        public int $probeLeaseSeconds,
        public array $failureExceptions = [],
        public array $failureHttpStatuses = []
    ) {
        if ($failureThreshold < 1 || $failureThreshold > 1000
            || $cooldownSeconds < 1 || $cooldownSeconds > 86400
            || $probeLeaseSeconds < 1 || $probeLeaseSeconds > 3600) {
            throw new CircuitException('Circuit threshold or timing policy is invalid.');
        }
        if ($failureExceptions === [] && $failureHttpStatuses === []) {
            throw new CircuitException('Circuit policy needs an explicit failure classifier.');
        }
        foreach ($failureExceptions as $class) {
            if (!is_string($class) || !is_a($class, Throwable::class, true)) {
                throw new CircuitException('Circuit failure exception class is invalid.');
            }
        }
        foreach ($failureHttpStatuses as $status) {
            if (!is_int($status) || $status < 400 || $status > 599) {
                throw new CircuitException('Circuit failure HTTP status is invalid.');
            }
        }
    }

    public function failsResponse(mixed $value): bool
    {
        return $value instanceof HttpResponse
            && in_array($value->status(), $this->failureHttpStatuses, true);
    }

    public function failsException(Throwable $failure): bool
    {
        if ($failure instanceof HttpStatusException
            && in_array($failure->status(), $this->failureHttpStatuses, true)) {
            return true;
        }
        foreach ($this->failureExceptions as $class) {
            if ($failure instanceof $class) return true;
        }
        return false;
    }
}
