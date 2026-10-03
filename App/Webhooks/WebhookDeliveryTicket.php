<?php

declare(strict_types=1);

namespace App\Webhooks;

/**
 * Identifies scheduled work without promising that a deferred transaction
 * committed or that the remote peer accepted the event.
 */
final class WebhookDeliveryTicket
{
    public function __construct(private readonly string $eventId,
        private readonly string $deliveryId)
    {
    }

    public function eventId(): string { return $this->eventId; }
    public function deliveryId(): string { return $this->deliveryId; }
}
