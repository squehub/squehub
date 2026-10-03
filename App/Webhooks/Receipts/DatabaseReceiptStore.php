<?php

declare(strict_types=1);

namespace App\Webhooks\Receipts;

use App\Database\Connection;
use App\Database\Exception\QueryException;
use App\Webhooks\WebhookException;
use PDOException;

/**
 * Durable receipt claims on the configured database connection. A unique
 * source/event index and conditional updates arbitrate concurrent deliveries;
 * no body, signature, or signing secret is stored with the receipt.
 */
final class DatabaseReceiptStore implements ReceiptStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function claim(string $source, string $eventId, int $now, int $leaseSeconds): ?string
    {
        self::validateIdentity($source, $eventId);
        self::validateLease($now, $leaseSeconds);
        $identity = self::fingerprint($source, $eventId);
        $token = bin2hex(random_bytes(32));
        $current = self::datetime($now);
        $expiry = self::datetime($now + $leaseSeconds);

        try {
            $this->connection->raw('INSERT INTO `webhook_receipts` '
                . '(`source`, `event_id`, `identity_fingerprint`, `state`, `claim_token`, '
                . '`lease_expires_at`, `created_at`, `updated_at`) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$source, $eventId, $identity, 'processing', $token, $expiry, $current, $current]);
            return $token;
        } catch (QueryException $exception) {
            if (!$this->isIdentityDuplicate($exception)) throw $exception;
        }

        // Only a failed delivery or an expired processing lease can be
        // reclaimed. A racing claimant sees the fresh lease and changes zero
        // rows, so exactly one process receives the new ownership token.
        $updated = $this->connection->raw('UPDATE `webhook_receipts` '
            . 'SET `state` = ?, `claim_token` = ?, `lease_expires_at` = ?, `updated_at` = ? '
            . 'WHERE `identity_fingerprint` = ? '
            . 'AND (`state` = ? OR (`state` = ? AND `lease_expires_at` <= ?))',
            ['processing', $token, $expiry, $current, $identity,
                'failed', 'processing', $current]);
        return $updated->rowCount() === 1 ? $token : null;
    }

    public function processed(string $source, string $eventId, string $claimToken, int $now): void
    {
        $this->finish($source, $eventId, $claimToken, $now, 'processed');
    }

    public function failed(string $source, string $eventId, string $claimToken, int $now): void
    {
        $this->finish($source, $eventId, $claimToken, $now, 'failed');
    }

    public function prune(int $before): int
    {
        $deleted = $this->connection->raw('DELETE FROM `webhook_receipts` '
            . 'WHERE `state` IN (?, ?) AND `updated_at` < ?',
            ['processed', 'failed', self::datetime($before)]);
        return $deleted->rowCount();
    }

    private function finish(string $source, string $eventId, string $claimToken, int $now, string $state): void
    {
        self::validateIdentity($source, $eventId);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $claimToken) !== 1) {
            throw new WebhookException('Webhook receipt claim is no longer active.');
        }
        $current = self::datetime($now);
        $updated = $this->connection->raw('UPDATE `webhook_receipts` '
            . 'SET `state` = ?, `claim_token` = NULL, `lease_expires_at` = NULL, `updated_at` = ? '
            . 'WHERE `identity_fingerprint` = ? AND `state` = ? '
            . 'AND `claim_token` = ? AND `lease_expires_at` > ?',
            [$state, $current, self::fingerprint($source, $eventId), 'processing', $claimToken, $current]);
        if ($updated->rowCount() !== 1) {
            throw new WebhookException('Webhook receipt claim is no longer active.');
        }
    }

    /** Only the unique receipt identity may trigger a duplicate-claim path. */
    private function isIdentityDuplicate(QueryException $exception): bool
    {
        $failure = $exception->getPrevious();
        if (!$failure instanceof PDOException) return false;
        $info = $failure->errorInfo;
        $code = (int) ($info[1] ?? 0);
        $detail = strtolower((string) ($info[2] ?? ''));
        if (($info[0] ?? '') !== '23000') return false;
        return match ($this->connection->driver()) {
            'mysql' => $code === 1062 && str_contains($detail, 'webhook_receipts_identity'),
            'sqlite' => in_array($code, [19, 2067], true)
                && str_contains($detail, 'unique constraint failed: webhook_receipts.identity_fingerprint'),
            default => false,
        };
    }

    private static function datetime(int $timestamp): string
    {
        // DATETIME remains portable beyond the 2038 range of a signed SQL INT.
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /** A digest keeps uniqueness exact even under case-insensitive MySQL collations. */
    private static function fingerprint(string $source, string $eventId): string
    {
        return hash('sha256', $source . "\0" . $eventId);
    }

    private static function validateIdentity(string $source, string $eventId): void
    {
        if (strlen($source) > 64 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $source) !== 1
            || $eventId === '' || strlen($eventId) > 128
            || preg_match('/\A[\x21-\x7E]+\z/D', $eventId) !== 1) {
            throw new WebhookException('Webhook receipt identity is invalid.');
        }
    }

    private static function validateLease(int $now, int $leaseSeconds): void
    {
        if ($leaseSeconds < 1 || $leaseSeconds > 31536000 || $now > PHP_INT_MAX - $leaseSeconds) {
            throw new WebhookException('Webhook receipt lease is invalid.');
        }
    }
}
