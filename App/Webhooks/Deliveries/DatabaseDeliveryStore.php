<?php

declare(strict_types=1);

namespace App\Webhooks\Deliveries;

use App\Database\Connection;
use App\Database\Exception\QueryException;
use App\Webhooks\WebhookDeliveryResult;
use App\Webhooks\WebhookEvent;
use App\Webhooks\WebhookException;
use App\Webhooks\WebhookSignature;
use PDOException;

/**
 * Durable metadata for outgoing deliveries. Queue owns pending work and
 * retries; this table never stores a payload, URL, signature, or secret.
 */
final class DatabaseDeliveryStore implements DeliveryStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function begin(string $eventId, string $deliveryId, string $endpoint, int $now): bool
    {
        self::validateIdentity($eventId, $deliveryId, $endpoint);
        $current = self::datetime($now);
        $fingerprint = self::fingerprint($deliveryId);
        try {
            $this->connection->raw('INSERT INTO `webhook_deliveries` '
                . '(`event_id`, `event_fingerprint`, `delivery_id`, `delivery_fingerprint`, '
                . '`endpoint_name`, `state`, `attempt_count`, `created_at`, `updated_at`) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$eventId, self::fingerprint($eventId), $deliveryId, $fingerprint,
                    $endpoint, 'pending', 0, $current, $current]);
            return true;
        } catch (QueryException $exception) {
            if (!$this->isDeliveryDuplicate($exception)) throw $exception;
        }

        // A Queue retry reuses its delivery ID. Pending means a prior worker
        // may have stopped before recording an outcome; terminal rows let an
        // at-least-once redelivery acknowledge without sending a second time.
        // Exact PHP comparisons avoid database collation changing identity.
        $row = $this->connection->raw('SELECT `event_id`, `delivery_id`, `endpoint_name`, `state` '
            . 'FROM `webhook_deliveries` WHERE `delivery_fingerprint` = ?', [$fingerprint])->fetch();
        if (is_array($row) && $row['event_id'] === $eventId && $row['delivery_id'] === $deliveryId
            && $row['endpoint_name'] === $endpoint) {
            return in_array($row['state'], ['pending', 'retrying'], true);
        }
        throw new WebhookException('Webhook delivery already exists.');
    }

    public function record(string $deliveryId, WebhookDeliveryResult $result,
        int $now, bool $mayRetry = false, ?int $nextAttemptAt = null): void
    {
        self::validateResult($deliveryId, $result, $now, $mayRetry, $nextAttemptAt);
        $retrying = !$result->successful() && $mayRetry && $result->retryable();
        $state = $result->successful() ? 'succeeded' : ($retrying ? 'retrying' : 'failed');
        $current = self::datetime($now);
        $next = $retrying && $nextAttemptAt !== null ? self::datetime($nextAttemptAt) : null;
        $updated = $this->connection->raw('UPDATE `webhook_deliveries` '
            . 'SET `state` = ?, `attempt_count` = `attempt_count` + ?, '
            . '`next_attempt_at` = ?, `completed_at` = ?, `last_http_status` = ?, '
            . '`last_failure_category` = ?, `updated_at` = ? '
            . 'WHERE `delivery_fingerprint` = ? AND `event_fingerprint` = ? '
            . 'AND `state` IN (?, ?) AND `attempt_count` <= ?',
            [$state, $result->attempts(), $next, $retrying ? null : $current,
                $result->status(), $result->failureCategory(), $current,
                self::fingerprint($deliveryId), self::fingerprint($result->eventId()),
                'pending', 'retrying', 2147483647 - $result->attempts()]);
        if ($updated->rowCount() !== 1) {
            throw new WebhookException('Webhook delivery cannot be recorded.');
        }
    }

    public function prune(int $before): int
    {
        return $this->connection->raw('DELETE FROM `webhook_deliveries` '
            . 'WHERE `state` IN (?, ?) AND `completed_at` < ?',
            ['succeeded', 'failed', self::datetime($before)])->rowCount();
    }

    private function isDeliveryDuplicate(QueryException $exception): bool
    {
        $failure = $exception->getPrevious();
        if (!$failure instanceof PDOException) return false;
        $info = $failure->errorInfo;
        if (($info[0] ?? '') !== '23000') return false;
        $code = (int) ($info[1] ?? 0);
        $detail = strtolower((string) ($info[2] ?? ''));
        return match ($this->connection->driver()) {
            'mysql' => $code === 1062 && str_contains($detail, 'webhook_deliveries_identity'),
            'sqlite' => in_array($code, [19, 2067], true)
                && str_contains($detail, 'unique constraint failed: webhook_deliveries.delivery_fingerprint'),
            default => false,
        };
    }

    private static function fingerprint(string $value): string
    {
        return hash('sha256', $value);
    }

    private static function datetime(int $timestamp): string
    {
        // The portable schema uses UTC DATETIME rather than a signed SQL INT.
        if ($timestamp < -30610224000 || $timestamp > 253402300799) {
            throw new WebhookException('Webhook delivery time is invalid.');
        }
        return gmdate('Y-m-d H:i:s', $timestamp);
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
