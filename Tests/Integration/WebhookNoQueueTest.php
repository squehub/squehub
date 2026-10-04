<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\DatabaseServiceProvider;
use App\Database\Exception\ConnectionException;
use App\Foundation\Application;
use App\Http\Request;
use App\HttpClient\Http;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpServiceProvider;
use App\Webhooks\Webhook;
use App\Webhooks\WebhookException;
use App\Webhooks\WebhookServiceProvider;
use App\Webhooks\WebhookSignature;
use App\Webhooks\InvalidWebhookException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Webhook sending and verification stay usable when Queue is not registered. */
final class WebhookNoQueueTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';
    private const URL = 'https://hooks.example.test/receive';

    private TemporaryProject $project;
    private Application $app;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env" => "production", "debug" => false];');
        // Webhook HTTPS verification must override an insecure general HTTP
        // client setting so signed application data is not sent to a spoofed peer.
        $this->project->write('Config/HttpClient.php', '<?php return ["verify_peer" => false];');
        $this->project->write('Config/Webhooks.php', '<?php return ' . var_export([
            'endpoints' => ['billing' => ['url' => self::URL, 'secret' => self::SECRET]],
            'sources' => ['billing' => ['secret' => self::SECRET]],
            'receipt_store' => 'array', 'delivery_store' => 'array',
        ], true) . ';');
        $this->app = new Application($this->project->path());
        foreach ([DatabaseServiceProvider::class, HttpServiceProvider::class,
            WebhookServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
    }

    protected function tearDown(): void
    {
        Webhook::setResolver(null);
        Http::setResolver(null);
        if (isset($this->project)) $this->project->remove();
    }

    public function testSynchronousDeliveryAndIncomingVerificationNeedNoQueueProvider(): void
    {
        $client = $this->app->container()->make(HttpClient::class);
        $client->fake(['POST ' . self::URL => new HttpResponse(204)]);

        $event = Webhook::event('order.created', ['order_id' => 42]);
        $result = Webhook::endpoint('billing')->send($event);
        self::assertTrue($result->successful());
        self::assertCount(1, $client->captured());
        self::assertTrue($client->captured()[0]->verifyPeer);

        $headers = WebhookSignature::headers($event,
            WebhookSignature::generateDeliveryId(), time(), self::SECRET);
        $headers['Content-Type'] = 'application/json';
        $verified = Webhook::source('billing')->verify(
            new Request('POST', '/hooks/billing', [], [], [], [], $headers, [], $event->body())
        );
        self::assertSame($event->id(), $verified->id());
        self::assertSame(['order_id' => 42], $verified->data());
    }

    public function testQueueRequestFailsClearlyWithoutQueueProvider(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Queue');
        Webhook::endpoint('billing')->queue('order.created', ['order_id' => 42]);
    }

    public function testAfterCommitFailsClearlyWithoutQueueProvider(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Queue');
        Webhook::endpoint('billing')->afterCommit('order.created', ['order_id' => 42]);
    }

    public function testPeerNamesContainingDotsRemainLiteralConfigurationKeys(): void
    {
        $this->app->config()->set('webhooks.endpoints', [
            'billing.v1' => ['url' => self::URL, 'secret' => self::SECRET],
        ]);
        $this->app->config()->set('webhooks.sources', [
            'billing.v1' => ['secret' => self::SECRET],
        ]);
        $client = $this->app->container()->make(HttpClient::class);
        $client->fake(['POST ' . self::URL => new HttpResponse(204)]);
        $event = Webhook::event('order.created', ['order_id' => 42]);
        self::assertTrue(Webhook::endpoint('billing.v1')->send($event)->successful());

        $headers = WebhookSignature::headers($event,
            WebhookSignature::generateDeliveryId(), time(), self::SECRET);
        $headers['Content-Type'] = 'application/json';
        $verified = Webhook::source('billing.v1')->verify(
            new Request('POST', '/hooks/billing', [], [], [], [], $headers, [], $event->body())
        );
        self::assertSame('billing.v1', $verified->source());
    }

    public function testVerificationDoesNotOpenReceiptDatabaseBeforeAuthenticityCheck(): void
    {
        // This Application has no configured database connection. A signed
        // request can still be verified; only claiming a receipt requires one.
        $this->project->write('Config/Webhooks.php', '<?php return ' . var_export([
            'sources' => ['billing' => ['secret' => self::SECRET]],
            'receipt_store' => 'database',
        ], true) . ';');
        $app = new Application($this->project->path());
        foreach ([DatabaseServiceProvider::class, HttpServiceProvider::class,
            WebhookServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();

        $event = Webhook::event('order.created', ['order_id' => 42]);
        $headers = WebhookSignature::headers($event,
            WebhookSignature::generateDeliveryId(), time(), self::SECRET);
        $headers['Content-Type'] = 'application/json';
        $source = Webhook::source('billing');
        $valid = new Request('POST', '/hooks/billing', [], [], [], [], $headers, [], $event->body());
        self::assertSame($event->id(), $source->verify($valid)->id());

        $invalidHeaders = $headers;
        $invalidHeaders['SqueHub-Webhook-Signature'] = 'v1=' . str_repeat('0', 64);
        try {
            $source->handle(new Request('POST', '/hooks/billing', [], [], [], [],
                $invalidHeaders, [], $event->body()), static function (): void {
                self::fail('Unauthenticated webhook reached the application callback.');
            });
            self::fail('Invalid signature was accepted.');
        } catch (InvalidWebhookException) {
            self::assertTrue(true);
        }

        $called = false;
        try {
            $source->handle($valid, static function () use (&$called): void { $called = true; });
            self::fail('Receipt claim succeeded without a database connection.');
        } catch (ConnectionException) {
            self::assertFalse($called);
        }
    }
}
