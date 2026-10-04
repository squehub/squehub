<?php

declare(strict_types=1);

namespace App\Webhooks\Deliveries;

use App\Webhooks\WebhookDeliveryResult;

/**
 * Retains safe outgoing delivery metadata, not the event body or credentials.
 * Queue and the caller own retry timing; a retrying row is informational and
 * does not itself schedule work.
 */
interface DeliveryStore
{
    /** False means this same delivery already reached a terminal outcome. */
    public function begin(string $eventId, string $deliveryId, string $endpoint, int $now): bool;

    /**
     * Add this send's attempts and outcome. Set mayRetry only when another
     * system actually owns a future retry; nextAttemptAt may be unknown.
     */
    public function record(string $deliveryId, WebhookDeliveryResult $result,
        int $now, bool $mayRetry = false, ?int $nextAttemptAt = null): void;

    /** Remove only terminal rows completed before the cutoff. */
    public function prune(int $before): int;
}
