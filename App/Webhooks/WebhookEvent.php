<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Support\SecureRandom;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * One logical SqueHub webhook event and the exact JSON bytes delivered for it.
 *
 * The v1 envelope is canonical: stable field order and encoding make duplicate
 * JSON keys or a different interpretation of signed bytes invalid. A retry
 * keeps this event ID and body even when it uses a new delivery ID.
 */
final class WebhookEvent
{
    public const MAX_BODY_BYTES = 32768;
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** @param array<string|int,mixed> $data */
    private function __construct(
        private string $id,
        private string $type,
        private DateTimeImmutable $createdAt,
        private array $data,
        private string $body,
    ) {
    }

    /**
     * Encode intentional application data once. No Model or arbitrary object
     * is serialized, and the resulting bytes are the ones to sign and send.
     *
     * @param array<string|int,mixed> $data
     */
    public static function create(string $type, array $data, DateTimeImmutable $now): self
    {
        self::validateType($type);
        self::validateData($data, 0);
        $id = 'evt_' . SecureRandom::token(24);
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        // The public envelope has whole-second precision, so the in-memory
        // event must expose that same instant rather than hidden microseconds.
        $createdAt = $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'),
            (int) $utc->format('s'));
        $body = self::encode(self::envelope($id, $type, $createdAt, $data));
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new WebhookException('Webhook event exceeds the supported size.');
        }
        return new self($id, $type, $createdAt, $data, $body);
    }

    /**
     * Accept only the canonical v1 envelope, retaining its original bytes.
     * Re-encoding is a format check, never a replacement for signature input.
     */
    public static function fromJson(string $rawBody): self
    {
        if ($rawBody === '' || strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new WebhookException('Webhook event body is invalid or too large.');
        }
        try {
            $value = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new WebhookException('Webhook event JSON is invalid.', 0, $exception);
        }
        if (!is_array($value) || array_keys($value) !== ['version', 'id', 'type', 'created_at', 'data']
            || ($value['version'] ?? null) !== 1
            || !is_string($value['id'] ?? null) || !self::validId($value['id'])
            || !is_string($value['type'] ?? null)
            || !is_string($value['created_at'] ?? null)
            || !is_array($value['data'] ?? null)) {
            throw new WebhookException('Webhook event envelope is invalid.');
        }
        self::validateType($value['type']);
        self::validateData($value['data'], 0);
        $createdAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z', $value['created_at'], new DateTimeZone('UTC')
        );
        if ($createdAt === false || $createdAt->format('Y-m-d\TH:i:s\Z') !== $value['created_at']) {
            throw new WebhookException('Webhook event creation time is invalid.');
        }
        if (self::encode(self::envelope($value['id'], $value['type'], $createdAt, $value['data']))
            !== $rawBody) {
            throw new WebhookException('Webhook event envelope is not canonical.');
        }
        return new self($value['id'], $value['type'], $createdAt, $value['data'], $rawBody);
    }

    public function id(): string { return $this->id; }
    public function type(): string { return $this->type; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    /** @return array<string|int,mixed> */
    public function data(): array { return $this->data; }
    public function body(): string { return $this->body; }
    public function version(): int { return 1; }

    /** Keep event data out of accidental debug dumps and exception context. */
    public function __debugInfo(): array
    {
        return ['version' => 1, 'id' => $this->id, 'data' => '[REDACTED]', 'body' => '[REDACTED]'];
    }

    public static function validId(string $id): bool
    {
        return preg_match('/\Aevt_[A-Za-z0-9_-]{32}\z/D', $id) === 1;
    }

    /** @param array<string|int,mixed> $data @return array<string,mixed> */
    private static function envelope(string $id, string $type, DateTimeImmutable $createdAt, array $data): array
    {
        return [
            'version' => 1,
            'id' => $id,
            'type' => $type,
            'created_at' => $createdAt->format('Y-m-d\TH:i:s\Z'),
            'data' => $data,
        ];
    }

    private static function validateType(string $type): void
    {
        if (strlen($type) > 128
            || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D', $type) !== 1) {
            throw new WebhookException('Webhook event type is invalid.');
        }
    }

    /**
     * Reject objects, resources, non-finite floats, and recursive structures
     * before encoding; a depth bound terminates self-referential PHP arrays.
     */
    private static function validateData(mixed $value, int $depth): void
    {
        if ($depth > 30) {
            throw new WebhookException('Webhook event data nesting is too deep.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (!is_int($key) && !is_string($key)) {
                    throw new WebhookException('Webhook event data key is invalid.');
                }
                self::validateData($item, $depth + 1);
            }
            return;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)
            || (is_float($value) && is_finite($value))) {
            return;
        }
        throw new WebhookException('Webhook event data contains an unsupported value.');
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string
    {
        try {
            return json_encode($value, self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new WebhookException('Webhook event data is not valid JSON.', 0, $exception);
        }
    }
}
