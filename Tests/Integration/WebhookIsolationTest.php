<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\Database;
use App\Database\DatabaseServiceProvider;
use App\Diagnostics\Diagnostic;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\Request;
use App\HttpClient\Http;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpServiceProvider;
use App\Queue\Queue;
use App\Queue\QueueServiceProvider;
use App\Webhooks\VerifiedWebhook;
use App\Webhooks\Webhook;
use App\Webhooks\WebhookEvent;
use App\Webhooks\WebhookManager;
use App\Webhooks\WebhookServiceProvider;
use App\Webhooks\WebhookSignature;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Application-owned array receipts and deliveries cannot cross peer instances. */
final class WebhookIsolationTest extends TestCase
{
    private const SOURCE_SECRET = '0123456789abcdef0123456789abcdef';
    private const FIRST_SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SECOND_SECRET = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testIndependentApplicationsKeepPeerConfigurationAndStateSeparate(): void
    {
        $firstProject = new TemporaryProject();
        $secondProject = new TemporaryProject();
        try {
            $first = $this->application($firstProject,
                'https://first.example.test/hook', self::FIRST_SECRET);
            $second = $this->application($secondProject,
                'https://second.example.test/hook', self::SECOND_SECRET);
            $firstManager = $first->container()->make(WebhookManager::class);
            $secondManager = $second->container()->make(WebhookManager::class);
            self::assertNotSame($firstManager, $secondManager);

            $event = WebhookEvent::create('invoice.paid', ['invoice_id' => 91],
                new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $deliveryId = WebhookSignature::generateDeliveryId();
            $headers = WebhookSignature::headers($event, $deliveryId, time(),
                self::SOURCE_SECRET);
            $headers['Content-Type'] = 'application/json';
            $request = new Request('POST', '/webhook', [], [], [], [], $headers,
                [], $event->body());
            $firstHandled = 0;
            $secondHandled = 0;
            self::assertTrue($firstManager->source('payments')->handle($request,
                static function (VerifiedWebhook $verified) use (&$firstHandled): void {
                    ++$firstHandled;
                }));
            self::assertFalse($firstManager->source('payments')->handle($request,
                static function (VerifiedWebhook $verified) use (&$firstHandled): void {
                    ++$firstHandled;
                }));
            self::assertTrue($secondManager->source('payments')->handle($request,
                static function (VerifiedWebhook $verified) use (&$secondHandled): void {
                    ++$secondHandled;
                }));
            self::assertSame(1, $firstHandled);
            self::assertSame(1, $secondHandled);

            $firstClient = $first->container()->make(HttpClient::class);
            $secondClient = $second->container()->make(HttpClient::class);
            $firstManager->deliverQueued('billing', $event->body(), $event->id(), $deliveryId);
            $firstManager->deliverQueued('billing', $event->body(), $event->id(), $deliveryId);
            $secondManager->deliverQueued('billing', $event->body(), $event->id(), $deliveryId);
            self::assertCount(1, $firstClient->captured());
            self::assertCount(1, $secondClient->captured());
            self::assertSame('https://first.example.test/hook',
                $firstClient->captured()[0]->url);
            self::assertSame('https://second.example.test/hook',
                $secondClient->captured()[0]->url);
            $firstSignature = $firstClient->captured()[0]->headers['squehub-webhook-signature'];
            $secondSignature = $secondClient->captured()[0]->headers['squehub-webhook-signature'];
            self::assertNotSame($firstSignature, $secondSignature);
            self::assertTrue(WebhookSignature::verify($event->id(), $deliveryId,
                (int) $firstClient->captured()[0]->headers['squehub-webhook-timestamp'],
                $event->body(), $firstSignature, [self::FIRST_SECRET]));
            self::assertTrue(WebhookSignature::verify($event->id(), $deliveryId,
                (int) $secondClient->captured()[0]->headers['squehub-webhook-timestamp'],
                $event->body(), $secondSignature, [self::SECOND_SECRET]));
        } finally {
            Webhook::setResolver(null);
            Queue::setResolver(null);
            Http::setResolver(null);
            Database::setResolver(null);
            Diagnostic::setResolver(null);
            $firstProject->remove();
            $secondProject->remove();
        }
    }

    private function application(TemporaryProject $project, string $url,
        string $secret): Application
    {
        $project->write('Config/App.php', '<?php return ["env" => "production", "debug" => false];');
        $project->write('Config/Database.php', '<?php return ' . var_export([
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => ':memory:',
            ]],
        ], true) . ';');
        $project->write('Config/Queue.php', '<?php return '
            . var_export(['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]], true) . ';');
        $project->write('Config/Webhooks.php', '<?php return ' . var_export([
            'receipt_store' => 'array', 'delivery_store' => 'array',
            'sources' => ['payments' => ['secret' => self::SOURCE_SECRET]],
            'endpoints' => ['billing' => ['url' => $url, 'secret' => $secret]],
        ], true) . ';');
        $app = new Application($project->path());
        foreach ([DiagnosticsServiceProvider::class, DatabaseServiceProvider::class,
            QueueServiceProvider::class, HttpServiceProvider::class,
            WebhookServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        $app->container()->make(HttpClient::class)->fake(['POST ' . $url => new HttpResponse(204)]);
        return $app;
    }
}
