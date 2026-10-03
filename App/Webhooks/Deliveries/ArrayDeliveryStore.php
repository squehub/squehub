<?php

declare(strict_types=1);

namespace App\Webhooks\Deliveries;

use App\Webhooks\WebhookDeliveryResult;
use App\Webhooks\WebhookEvent;
use App\Webhooks\WebhookException;
use App\Webhooks\WebhookSignature;

/**
 * In-memory delivery ledger for deterministic tests and single-process use.
 * It offers no persistence across PHP requests or process boundaries.
 */
final class ArrayDeliveryStore implements DeliveryStore
{
    /** @var array<string, array<string, int|string|null>> */
    private array $deliveries = [];

    public function begin(string $eventId, string $deliveryId, string $endpoint, int $now): bool
    {
        self::validateIdentity($eventId, $deliveryId, $endpoint);
        $key = self::key($deliveryId);
        if (isset($this->deliveries[$key])) {
            $existing = $this->deliveries[$key];
            if ($existing['event_id'] === $eventId && $existing['delivery_id'] === $deliveryId
                && $existing['endpoint_name'] === $endpoint) {
                return in_array($existing['state'], ['pending', 'retrying'], true);
            }
            throw new WebhookException('Webhook delivery already exists.');
        }
        $this->deliveries[$key] = [
            'event_id' => $eventId, 'delivery_id' => $deliveryId,
            'endpoint_name' => $endpoint, 'state' => 'pending', 'attempt_count' => 0,
            'next_attempt_at' => null, 'completed_at' => null,
            'last_http_status' => null, 'last_failure_category' => null,
            'created_at' => $now, 'updated_at' => $now,
        ];
        return true;
    }

    public function record(string $deliveryId, WebhookDeliveryResult $result,
        int $now, bool $mayRetry = false, ?int $nextAttemptAt = null): void
    {
        self::validateResult($deliveryId, $result, $now, $mayRetry, $nextAttemptAt);
        $key = self::key($deliveryId);
        $row = $this->deliveries[$key] ?? null;
        if ($row === null || $row['event_id'] !== $result->eventId()
            || !in_array($row['state'], ['pending', 'retrying'], true)
            || $row['attempt_count'] > 2147483647 - $result->attempts()) {
            throw new WebhookException('Webhook delivery cannot be recorded.');
        }
        $retrying = !$result->successful() && $mayRetry && $result->retryable();
        $row['state'] = $result->successful() ? 'succeeded' : ($retrying ? 'retrying' : 'failed');
        $row['attempt_count'] += $result->attempts();
        $row['next_attempt_at'] = $retrying ? $nextAttemptAt : null;
        $row['completed_at'] = $retrying ? null : $now;
        $row['last_http_status'] = $result->status();
        $row['last_failure_category'] = $result->failureCategory();
        $row['updated_at'] = $now;
        $this->deliveries[$key] = $row;
    }

    public function prune(int $before): int
    {
        $removed = 0;
        foreach ($this->deliveries as $key => $row) {
            if (in_array($row['state'], ['succeeded', 'failed'], true)
                && $row['completed_at'] < $before) {
                unset($this->deliveries[$key]);
                ++$removed;
            }
        }
        return $removed;
    }

    private static function key(string $deliveryId): string
    {
        return hash('sha256', $deliveryId);
    }

    private static function validateIdentity(string $eventId, string $deliveryId, string $endpoint): void
    {
        if (!WebhookEvent::validId($eventId) || !WebhookSignature::validDeliveryId($deliveryId)
            || strlen($endpoint) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $endpoint) !== 1) {
            throw new WebhookException('Webhook delivery identity is invalid.');
        }
    }

    private static function validateResult(string $deliveryId, WebhookDeliveryResult $result,
        int $now, bool $mayRetry, ?int $nextAttemptAt): void
    {
        $status = $result->status();
        $category = $result->failureCategory();
        if (!WebhookSignature::validDeliveryId($deliveryId) || $deliveryId !== $result->deliveryId()
            || !WebhookEvent::validId($result->eventId())
            || $result->attempts() < 1 || $result->attempts() > 1000
            || ($status !== null && ($status < 100 || $status > 599))
            || ($result->successful() && ($status === null || $status < 200 || $status > 299
                || $category !== null || $result->retryable()))
            || (!$result->successful() && (!in_array($category,
                ['http_status', 'timeout', 'connection', 'transport'], true)
                || ($status !== null && $status >= 200 && $status <= 299)))
            || ($nextAttemptAt !== null && (!$mayRetry || !$result->retryable()
                || $result->successful() || $nextAttemptAt < $now
                || $nextAttemptAt > $now + 31536000))) {
            throw new WebhookException('Webhook delivery result is invalid.');
        }
    }
}
