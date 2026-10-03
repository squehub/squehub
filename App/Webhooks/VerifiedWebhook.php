<?php

declare(strict_types=1);

namespace App\Webhooks;

use DateTimeImmutable;

/**
 * Authenticated incoming SqueHub event with its source and delivery identity.
 *
 * Construction records protocol results; claiming a receipt and running
 * application business work remain separate, explicit operations. The event
 * data is intentionally omitted from debug dumps.
 */
final class VerifiedWebhook
{
    public function __construct(private string $source, private WebhookEvent $event,
        private string $deliveryId)
    {
        if (strlen($source) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $source) !== 1
            || !WebhookSignature::validDeliveryId($deliveryId)) {
            throw new WebhookException('Verified webhook metadata is invalid.');
        }
    }

    public function source(): string { return $this->source; }
    public function id(): string { return $this->event->id(); }
    public function type(): string { return $this->event->type(); }
    public function createdAt(): DateTimeImmutable { return $this->event->createdAt(); }
    /** @return array<string|int,mixed> */
    public function data(): array { return $this->event->data(); }
    public function deliveryId(): string { return $this->deliveryId; }

    public function __debugInfo(): array
    {
        return ['source' => $this->source, 'id' => $this->id(), 'delivery_id' => $this->deliveryId,
            'data' => '[REDACTED]'];
    }
}
