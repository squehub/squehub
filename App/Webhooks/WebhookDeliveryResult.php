<?php

declare(strict_types=1);

namespace App\Webhooks;

/**
 * Safe outcome of one logical delivery, including its bounded HTTP attempts.
 * Response bodies, endpoint URLs, headers and signing keys are never retained.
 */
final class WebhookDeliveryResult
{
    public function __construct(
        private readonly string $eventId,
        private readonly string $deliveryId,
        private readonly ?int $status,
        private readonly bool $successful,
        private readonly int $attempts,
        private readonly ?string $failureCategory,
        private readonly bool $retryable
    ) {
    }

    public function eventId(): string { return $this->eventId; }
    public function deliveryId(): string { return $this->deliveryId; }
    public function status(): ?int { return $this->status; }
    public function successful(): bool { return $this->successful; }
    public function attempts(): int { return $this->attempts; }
    public function failureCategory(): ?string { return $this->failureCategory; }
    public function retryable(): bool { return $this->retryable; }
}
