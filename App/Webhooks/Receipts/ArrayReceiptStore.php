<?php

declare(strict_types=1);

namespace App\Webhooks\Receipts;

use App\Webhooks\WebhookException;

/**
 * One-process receipt store for tests and short-lived applications.
 * Persistent replay protection requires DatabaseReceiptStore instead.
 */
final class ArrayReceiptStore implements ReceiptStore
{
    /** @var array<string, array{state:string,token:?string,lease:int,updated:int}> */
    private array $receipts = [];

    public function claim(string $source, string $eventId, int $now, int $leaseSeconds): ?string
    {
        self::validateIdentity($source, $eventId);
        self::validateLease($now, $leaseSeconds);
        $key = self::key($source, $eventId);
        $existing = $this->receipts[$key] ?? null;
        if ($existing !== null && ($existing['state'] === 'processed'
            || ($existing['state'] === 'processing' && $existing['lease'] > $now))) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $this->receipts[$key] = ['state' => 'processing', 'token' => $token,
            'lease' => $now + $leaseSeconds, 'updated' => $now];
        return $token;
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
        $removed = 0;
        foreach ($this->receipts as $key => $receipt) {
            if ($receipt['state'] !== 'processing' && $receipt['updated'] < $before) {
                unset($this->receipts[$key]);
                ++$removed;
            }
        }
        return $removed;
    }

    private function finish(string $source, string $eventId, string $claimToken, int $now, string $state): void
    {
        self::validateIdentity($source, $eventId);
        $key = self::key($source, $eventId);
        $receipt = $this->receipts[$key] ?? null;
        // A lease may have been reclaimed. Its former owner must not complete
        // or fail the new owner's work, even when both hold the same event ID.
        if ($receipt === null || $receipt['state'] !== 'processing'
            || $receipt['token'] !== $claimToken || $receipt['lease'] <= $now) {
            throw new WebhookException('Webhook receipt claim is no longer active.');
        }
        $this->receipts[$key] = ['state' => $state, 'token' => null,
            'lease' => 0, 'updated' => $now];
    }

    private static function key(string $source, string $eventId): string
    {
        return hash('sha256', strlen($source) . ':' . $source . $eventId);
    }

    /** Reject identities that cannot fit the portable receipt schema. */
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
