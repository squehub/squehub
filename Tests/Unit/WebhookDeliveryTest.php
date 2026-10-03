<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Database\ModelClock;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpConnectionException;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpTimeoutException;
use App\Queue\QueueCodec;
use App\Webhooks\Queue\DeliverWebhook;
use App\Webhooks\WebhookEndpointConfig;
use App\Webhooks\WebhookEvent;
use App\Webhooks\WebhookException;
use App\Webhooks\WebhookSender;
use App\Webhooks\WebhookSignature;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** Outbound delivery uses the exact signed body and a bounded retry policy. */
final class WebhookDeliveryTest extends TestCase
{
    private const URL = 'https://billing.example.test/receive';
    private const SECRET = 'test-only-webhook-secret-32-bytes-long';

    private WebhookDeliveryClock $clock;
    private Diagnostics $diagnostics;
    private HttpClient $http;
    private WebhookEvent $event;
    private string $deliveryId;

    protected function setUp(): void
    {
        $this->clock = new WebhookDeliveryClock(1700000000);
        $this->diagnostics = new Diagnostics(new Repository([]));
        $this->diagnostics->begin(new Request());
        $this->http = new HttpClient([], null, $this->diagnostics);
        $this->event = WebhookEvent::create('order.created', ['order_id' => 42],
            $this->clock->now());
        $this->deliveryId = WebhookSignature::generateDeliveryId();
    }

    public function testExactBodyAndSignatureAreSentOnceWithoutExposingResponseBody(): void
    {
        $this->http->fake(['POST ' . self::URL => new HttpResponse(204,
            'remote-secret-that-must-not-be-retained')]);
        $result = $this->sender()->send($this->endpoint(), $this->event, $this->deliveryId);
        $sent = $this->http->captured();
        self::assertCount(1, $sent);
        self::assertSame('POST', $sent[0]->method);
        self::assertSame(self::URL, $sent[0]->url);
        self::assertSame('application/json', $sent[0]->headers['content-type']);
        self::assertSame($this->event->body(), $sent[0]->body);
        self::assertSame($this->event->id(), $sent[0]->headers['squehub-webhook-id']);
        self::assertSame($this->deliveryId, $sent[0]->headers['squehub-webhook-delivery-id']);
        self::assertSame('1700000000', $sent[0]->headers['squehub-webhook-timestamp']);
        self::assertSame('v1=' . hash_hmac('sha256', "v1\n1700000000\n"
            . $this->event->id() . "\n" . $this->deliveryId . "\n"
            . $this->event->body(), self::SECRET),
            $sent[0]->headers['squehub-webhook-signature']);
        self::assertTrue($result->successful());
        self::assertSame($this->event->id(), $result->eventId());
        self::assertSame($this->deliveryId, $result->deliveryId());
        self::assertSame(204, $result->status());
        self::assertSame(1, $result->attempts());
        self::assertNull($result->failureCategory());
        self::assertFalse($result->retryable());
        self::assertStringNotContainsString('remote-secret', serialize($result));
        self::assertSame(1, $this->diagnostics->snapshot()['webhooks']['delivery_attempts']);
    }

    public function testOnlyTransientHttpStatusesRetry(): void
    {
        $this->http->fake(['POST ' . self::URL => [
            new HttpResponse(503, 'server says private-data', ['Retry-After' => ['0']]),
            new HttpResponse(204),
        ]]);
        $result = $this->sender()->send($this->endpoint(['max_attempts' => 2]),
            $this->event, $this->deliveryId);
        self::assertTrue($result->successful());
        self::assertSame(2, $result->attempts());
        self::assertCount(2, $this->http->captured());
        self::assertSame($this->http->captured()[0]->body, $this->http->captured()[1]->body);
        self::assertSame($this->http->captured()[0]->headers['squehub-webhook-delivery-id'],
            $this->http->captured()[1]->headers['squehub-webhook-delivery-id']);
        self::assertSame(1, $this->diagnostics->snapshot()['webhooks']['delivery_retries']);

        $this->http->fake(['POST ' . self::URL => [
            new HttpResponse(400, 'must not be retried'), new HttpResponse(204),
        ]]);
        $terminal = $this->sender()->send($this->endpoint(['max_attempts' => 2]),
            $this->event, $this->deliveryId);
        self::assertFalse($terminal->successful());
        self::assertSame(400, $terminal->status());
        self::assertSame(1, $terminal->attempts());
        self::assertSame('http_status', $terminal->failureCategory());
        self::assertFalse($terminal->retryable());
        self::assertCount(1, $this->http->captured());
    }

    public function testNetworkFailuresRetryAndExhaustWithSafeMetadata(): void
    {
        $this->http->fake(['POST ' . self::URL => [
            new HttpTimeoutException('External HTTP request timed out.'),
            new HttpConnectionException('External HTTP request failed.'),
        ]]);
        $result = $this->sender()->send($this->endpoint(['max_attempts' => 2]),
            $this->event, $this->deliveryId);
        self::assertFalse($result->successful());
        self::assertNull($result->status());
        self::assertSame(2, $result->attempts());
        self::assertSame('connection', $result->failureCategory());
        self::assertTrue($result->retryable());
        self::assertSame(2, $this->diagnostics->snapshot()['webhooks']['delivery_attempts']);
        self::assertSame(1, $this->diagnostics->snapshot()['webhooks']['delivery_failures']);
    }

    public function testRetryStatusSetIsExplicitAndRedirectsRemainTerminal(): void
    {
        foreach ([408, 429, 500, 502, 503, 504] as $status) {
            $this->http->fake(['POST ' . self::URL => [
                new HttpResponse($status), new HttpResponse(202),
            ]]);
            $result = $this->sender()->send($this->endpoint(['max_attempts' => 2]),
                $this->event, $this->deliveryId);
            self::assertTrue($result->successful());
            self::assertSame(2, $result->attempts());
            self::assertCount(2, $this->http->captured());
        }

        foreach ([301, 302, 400, 401, 403, 404, 422] as $status) {
            $this->http->fake(['POST ' . self::URL => [
                new HttpResponse($status, '', ['Location' => ['https://elsewhere.example.test/']]),
                new HttpResponse(202),
            ]]);
            $result = $this->sender()->send($this->endpoint(['max_attempts' => 2]),
                $this->event, $this->deliveryId);
            self::assertFalse($result->successful());
            self::assertFalse($result->retryable());
            self::assertSame(1, $result->attempts());
            self::assertCount(1, $this->http->captured());
        }
    }

    public function testEndpointConfigurationRequiresTrustedHttpsAndBoundedPolicy(): void
    {
        $local = $this->endpoint(['url' => 'http://127.0.0.1:8000/hook',
            'allow_local_http' => true]);
        self::assertSame('http://127.0.0.1:8000/hook', $local->url());
        self::assertSame('billing', $local->name());
        self::assertSame(1, $local->maxAttempts());
        self::assertSame(0, $local->retryDelayMs());
        self::assertStringNotContainsString(self::SECRET,
            json_encode($local->__debugInfo(), JSON_THROW_ON_ERROR));

        foreach ([
            ['url' => 'http://example.test/hook'],
            ['url' => 'http://example.test/hook', 'allow_local_http' => true],
            ['url' => 'ftp://example.test/hook'],
            ['url' => 'https://192.168.1.10/hook'],
            ['url' => 'https://8.8.8.8/hook'],
            ['url' => 'https://[::1]/hook'],
            ['url' => 'https://localhost/hook'],
            ['url' => 'https://localhost./hook'],
            ['url' => 'https://worker.local/hook'],
            ['url' => 'https://worker.local./hook'],
            ['url' => 'https://worker.localhost/hook'],
            ['url' => 'https://worker.internal/hook'],
            ['url' => 'https://127.1/hook'],
            ['url' => 'https://0x7f000001/hook'],
            ['url' => 'https://user:pass@example.test/hook'],
            ['url' => 'https://example.test/hook#fragment'],
            ['secret' => 'short'],
            ['secret' => str_repeat('x', 513)],
            ['secret' => str_repeat('x', 32) . "\n"],
            ['max_attempts' => 0],
            ['max_attempts' => 4],
            ['retry_delay_ms' => 1001],
        ] as $override) {
            try {
                $this->endpoint($override);
                self::fail('Unsafe endpoint configuration was accepted.');
            } catch (WebhookException $exception) {
                self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
                self::assertStringNotContainsString('user:pass', $exception->getMessage());
            }
        }
    }

    public function testQueuePayloadIsVersionedBoundedAndHasNoSigningCredentials(): void
    {
        $payload = ['version' => 1, 'endpoint' => 'billing', 'body' => $this->event->body(),
            'event_id' => $this->event->id(), 'delivery_id' => $this->deliveryId];
        $job = DeliverWebhook::fromQueuePayload($payload);
        self::assertSame($payload, $job->toQueuePayload());
        $encoded = QueueCodec::encode($job);
        self::assertStringNotContainsString(self::SECRET, $encoded);
        self::assertStringNotContainsString('https://billing.example.test', $encoded);
        self::assertStringNotContainsString('signature', $encoded);
        self::assertInstanceOf(DeliverWebhook::class, QueueCodec::decode($encoded));

        foreach ([
            ['version' => 2],
            ['endpoint' => '../billing'],
            ['delivery_id' => 'wrong'],
            ['event_id' => WebhookEvent::create('other.event', [], $this->clock->now())->id()],
            ['body' => str_repeat('x', WebhookEvent::MAX_BODY_BYTES + 1)],
        ] as $change) {
            $this->expectInvalidQueuePayload(array_replace($payload, $change));
        }
    }

    /** @param array<string,mixed> $payload */
    private function expectInvalidQueuePayload(array $payload): void
    {
        try {
            DeliverWebhook::fromQueuePayload($payload);
            self::fail('Invalid queued webhook payload was accepted.');
        } catch (WebhookException) {
            self::assertTrue(true);
        }
    }

    /** @param array<string,mixed> $overrides */
    private function endpoint(array $overrides = []): WebhookEndpointConfig
    {
        return new WebhookEndpointConfig('billing', array_replace([
            'url' => self::URL, 'secret' => self::SECRET,
        ], $overrides));
    }

    private function sender(): WebhookSender
    {
        return new WebhookSender($this->http, $this->clock, $this->diagnostics);
    }
}

/** Deterministic UTC attempt timestamps without sleeps in delivery tests. */
final class WebhookDeliveryClock implements ModelClock
{
    public function __construct(private int $timestamp)
    {
    }

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->timestamp))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
