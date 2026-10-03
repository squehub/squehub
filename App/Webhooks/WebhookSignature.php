<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Support\SecureRandom;

/**
 * SqueHub v1 webhook HMAC profile over the bytes actually sent on the wire.
 *
 * The signing base is `v1\n<timestamp>\n<event-id>\n<delivery-id>\n<body>`.
 * Both IDs have a restricted alphabet, so their separators are unambiguous.
 * Timestamp freshness and receipt deduplication belong to the caller; a valid
 * MAC alone cannot establish that a delivery is recent or unprocessed.
 */
final class WebhookSignature
{
    private const MAX_SECRETS = 3;

    public static function generateDeliveryId(): string
    {
        return 'whd_' . SecureRandom::token(24);
    }

    /**
     * Outgoing deliveries sign the active configured secret only. The secret
     * is never returned in a header or retained by this stateless utility.
     *
     * @return array<string,string>
     */
    public static function headers(WebhookEvent $event, string $deliveryId, int $timestamp,
        #[\SensitiveParameter] string $secret): array
    {
        if (!self::validDeliveryId($deliveryId) || $timestamp < 1) {
            throw new WebhookException('Webhook delivery metadata is invalid.');
        }
        self::validateSecret($secret);
        $digest = hash_hmac('sha256', self::base($event->id(), $deliveryId,
            $timestamp, $event->body()), $secret);
        return [
            'SqueHub-Webhook-Id' => $event->id(),
            'SqueHub-Webhook-Delivery-Id' => $deliveryId,
            'SqueHub-Webhook-Timestamp' => (string) $timestamp,
            'SqueHub-Webhook-Signature' => 'v1=' . $digest,
        ];
    }

    /**
     * Check all configured current/previous secrets without revealing which
     * one matched. The caller bounds the timestamp and claims a receipt after
     * this check; neither control can be replaced by HMAC verification.
     *
     * @param list<string> $secrets One active secret and at most two rotation secrets.
     */
    public static function verify(string $eventId, string $deliveryId, int $timestamp,
        #[\SensitiveParameter] string $rawBody,
        #[\SensitiveParameter] string $signature,
        #[\SensitiveParameter] array $secrets): bool
    {
        self::validateSecrets($secrets);
        if (!WebhookEvent::validId($eventId) || !self::validDeliveryId($deliveryId)
            || $timestamp < 1 || $rawBody === ''
            || strlen($rawBody) > WebhookEvent::MAX_BODY_BYTES
            || preg_match('/\Av1=[a-f0-9]{64}\z/D', $signature) !== 1) {
            return false;
        }
        $provided = substr($signature, 3);
        $base = self::base($eventId, $deliveryId, $timestamp, $rawBody);
        $matched = false;
        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $base, $secret);
            if (hash_equals($expected, $provided)) $matched = true;
        }
        return $matched;
    }

    public static function validDeliveryId(string $deliveryId): bool
    {
        return preg_match('/\Awhd_[A-Za-z0-9_-]{32}\z/D', $deliveryId) === 1;
    }

    private static function base(string $eventId, string $deliveryId, int $timestamp,
        string $body): string
    {
        return "v1\n{$timestamp}\n{$eventId}\n{$deliveryId}\n{$body}";
    }

    /** A length gate catches obvious misconfiguration; actual entropy is operational policy. */
    private static function validateSecret(string $secret): void
    {
        if (strlen($secret) < 32 || strlen($secret) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $secret) === 1) {
            throw new WebhookException('Webhook signing secret is invalid.');
        }
    }

    /** @param array<mixed> $secrets */
    private static function validateSecrets(array $secrets): void
    {
        if (!array_is_list($secrets) || $secrets === [] || count($secrets) > self::MAX_SECRETS) {
            throw new WebhookException('Webhook signing secrets are invalid.');
        }
        foreach ($secrets as $secret) {
            if (!is_string($secret)) {
                throw new WebhookException('Webhook signing secrets are invalid.');
            }
            self::validateSecret($secret);
        }
    }
}
