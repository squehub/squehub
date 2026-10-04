<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Webhooks\VerifiedWebhook;
use App\Webhooks\WebhookEvent;
use App\Webhooks\WebhookException;
use App\Webhooks\WebhookSignature;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Signing-profile fixtures use fixed time and local secrets without HTTP. */
final class WebhookSigningTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';
    private const PREVIOUS = 'fedcba9876543210fedcba9876543210';
    private const TIMESTAMP = 1_790_444_600;

    private static function event(array $data = ['order_id' => 42]): WebhookEvent
    {
        return WebhookEvent::create('order.created', $data,
            new DateTimeImmutable('2026-09-26T21:30:00.987654+03:00'));
    }

    public function testEventEnvelopeIsCanonicalBoundedAndUtc(): void
    {
        $event = self::event(['order_id' => 42, 'total' => 125.0]);
        self::assertMatchesRegularExpression('/\Aevt_[A-Za-z0-9_-]{32}\z/D', $event->id());
        self::assertSame('order.created', $event->type());
        self::assertSame(1, $event->version());
        self::assertSame('2026-09-26T18:30:00Z', $event->createdAt()->format('Y-m-d\TH:i:s\Z'));
        self::assertSame(0, (int) $event->createdAt()->format('u'));
        self::assertSame(
            '{"version":1,"id":"' . $event->id()
                . '","type":"order.created","created_at":"2026-09-26T18:30:00Z",'
                . '"data":{"order_id":42,"total":125.0}}',
            $event->body()
        );
        $parsed = WebhookEvent::fromJson($event->body());
        self::assertSame($event->body(), $parsed->body());
        self::assertSame($event->id(), $parsed->id());
        self::assertSame(['order_id' => 42, 'total' => 125.0], $parsed->data());
    }

    public function testIncomingEnvelopeRejectsNoncanonicalAndAmbiguousJson(): void
    {
        $body = self::event()->body();
        foreach ([
            ' ' . $body,
            str_replace('"version":1,', '"version":1,"version":1,', $body),
            str_replace('"version":1,', '"version":2,', $body),
            str_replace('"data":', '"extra":true,"data":', $body),
            str_replace('"created_at":"2026-09-26T18:30:00Z"',
                '"created_at":"2026-02-30T18:30:00Z"', $body),
            '{"version":1',
            str_repeat('x', WebhookEvent::MAX_BODY_BYTES + 1),
        ] as $invalid) {
            try {
                WebhookEvent::fromJson($invalid);
                self::fail('An invalid event envelope was accepted.');
            } catch (WebhookException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEventCreationRejectsUnsafeTypesAndUnsupportedData(): void
    {
        $clock = new DateTimeImmutable('2026-09-26T18:30:00Z');
        foreach (['Order Created', 'order..created', '1order', str_repeat('a', 129)] as $type) {
            $this->reject(fn () => WebhookEvent::create($type, [], $clock));
        }
        foreach ([
            ['object' => new stdClass()],
            ['non_finite' => INF],
            ['invalid_utf8' => "\xB1"],
            ['large' => str_repeat('x', WebhookEvent::MAX_BODY_BYTES)],
        ] as $data) {
            $this->reject(fn () => WebhookEvent::create('order.created', $data, $clock));
        }
        $cycle = [];
        $cycle['self'] = &$cycle;
        $this->reject(fn () => WebhookEvent::create('order.created', $cycle, $clock));
    }

    public function testSignatureMatchesExactDocumentedSigningBase(): void
    {
        $event = self::event();
        $deliveryId = WebhookSignature::generateDeliveryId();
        self::assertMatchesRegularExpression('/\Awhd_[A-Za-z0-9_-]{32}\z/D', $deliveryId);
        $headers = WebhookSignature::headers($event, $deliveryId, self::TIMESTAMP, self::SECRET);
        $expected = hash_hmac('sha256', "v1\n" . self::TIMESTAMP . "\n" . $event->id()
            . "\n" . $deliveryId . "\n" . $event->body(), self::SECRET);
        self::assertSame([
            'SqueHub-Webhook-Id' => $event->id(),
            'SqueHub-Webhook-Delivery-Id' => $deliveryId,
            'SqueHub-Webhook-Timestamp' => (string) self::TIMESTAMP,
            'SqueHub-Webhook-Signature' => 'v1=' . $expected,
        ], $headers);
        self::assertTrue(WebhookSignature::verify($event->id(), $deliveryId,
            self::TIMESTAMP, $event->body(), $headers['SqueHub-Webhook-Signature'], [self::SECRET]));
    }

    public function testSignatureRejectsModifiedBytesAndMetadataButAcceptsRotationSecret(): void
    {
        $event = self::event();
        $deliveryId = WebhookSignature::generateDeliveryId();
        $signature = WebhookSignature::headers($event, $deliveryId,
            self::TIMESTAMP, self::PREVIOUS)['SqueHub-Webhook-Signature'];
        self::assertTrue(WebhookSignature::verify($event->id(), $deliveryId,
            self::TIMESTAMP, $event->body(), $signature, [self::SECRET, self::PREVIOUS]));
        foreach ([
            [$event->id(), $deliveryId, self::TIMESTAMP, $event->body() . ' ', $signature],
            [$event->id(), $deliveryId, self::TIMESTAMP + 1, $event->body(), $signature],
            [$event->id(), WebhookSignature::generateDeliveryId(), self::TIMESTAMP, $event->body(), $signature],
            ['evt_invalid', $deliveryId, self::TIMESTAMP, $event->body(), $signature],
            [$event->id(), $deliveryId, self::TIMESTAMP, $event->body(), strtoupper($signature)],
            [$event->id(), $deliveryId, 0, $event->body(), $signature],
        ] as [$eventId, $delivery, $timestamp, $body, $candidate]) {
            self::assertFalse(WebhookSignature::verify($eventId, $delivery,
                $timestamp, $body, $candidate, [self::SECRET, self::PREVIOUS]));
        }
    }

    public function testInvalidSecretConfigurationFailsWithoutEchoingSecret(): void
    {
        $event = self::event();
        $deliveryId = WebhookSignature::generateDeliveryId();
        $this->reject(fn () => WebhookSignature::headers($event, $deliveryId,
            self::TIMESTAMP, 'short-secret'));
        $this->reject(fn () => WebhookSignature::verify($event->id(), $deliveryId,
            self::TIMESTAMP, $event->body(), 'v1=' . str_repeat('0', 64),
            [self::SECRET, self::PREVIOUS, self::SECRET, self::PREVIOUS]));
    }

    public function testVerifiedResultExposesOnlyExpectedValues(): void
    {
        $secretMarker = 'SQUEHUB_WEBHOOK_PRIVATE_BODY';
        $event = self::event(['marker' => $secretMarker]);
        $deliveryId = WebhookSignature::generateDeliveryId();
        $verified = new VerifiedWebhook('billing', $event, $deliveryId);
        self::assertSame('billing', $verified->source());
        self::assertSame($event->id(), $verified->id());
        self::assertSame('order.created', $verified->type());
        self::assertSame($deliveryId, $verified->deliveryId());
        self::assertSame($event->createdAt(), $verified->createdAt());
        self::assertSame(['marker' => $secretMarker], $verified->data());
        ob_start();
        var_dump($event, $verified);
        $debug = (string) ob_get_clean();
        self::assertStringNotContainsString($secretMarker, $debug);
        $this->reject(fn () => new VerifiedWebhook('bad/source', $event, $deliveryId));
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            self::fail('Invalid webhook input was accepted.');
        } catch (WebhookException $exception) {
            self::assertStringNotContainsString('short-secret', $exception->getMessage());
        }
    }
}
