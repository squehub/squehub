<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Diagnostics\Diagnostic;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\HttpClient\Http;
use App\HttpClient\HttpServiceProvider as OutboundHttpServiceProvider;
use App\Queue\Queue;
use App\Queue\QueueServiceProvider;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionServiceProvider;
use App\Webhooks\VerifiedWebhook;
use App\Webhooks\Webhook;
use App\Webhooks\WebhookEvent;
use App\Webhooks\WebhookServiceProvider;
use App\Webhooks\WebhookSignature;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real Kernel/SQLite coverage for signed incoming requests and receipt claims. */
final class WebhookHttpTest extends TestCase
{
    private const CURRENT_SECRET = '0123456789abcdef0123456789abcdef';
    private const PREVIOUS_SECRET = 'fedcba9876543210fedcba9876543210';

    private TemporaryProject $project;
    private Application $app;
    private Kernel $kernel;
    private DatabaseManager $database;
    private Diagnostics $diagnostics;
    private int $processed = 0;
    private bool $failNext = false;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for webhook HTTP integration.');
        }
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env" => "production", "debug" => false];');
        $this->project->write('Config/Api.php', '<?php return ["enabled" => true, "paths" => ["/api"]];');
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/Csrf.php', '<?php return ' . var_export([
            'enabled' => true, 'field' => '_csrf', 'header' => 'X-CSRF-Token',
            'except' => ['/api/webhooks/payments', '/api/webhooks/expired'],
        ], true) . ';');
        $this->project->write('Config/Database.php', '<?php return ' . var_export([
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => $this->project->path('webhooks.sqlite'),
            ]],
        ], true) . ';');
        $this->project->write('Config/Queue.php', '<?php return '
            . var_export(['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]], true) . ';');
        $this->project->write('Config/Webhooks.php', '<?php return ' . var_export([
            'receipt_store' => 'database', 'timestamp_tolerance' => 300,
            'sources' => ['payments' => [
                'secret' => self::CURRENT_SECRET,
                'previous_secrets' => [[
                    'secret' => self::PREVIOUS_SECRET,
                    'expires_at' => time() + 3600,
                ]],
            ], 'expired' => [
                'secret' => self::CURRENT_SECRET,
                'previous_secrets' => [[
                    'secret' => self::PREVIOUS_SECRET,
                    'expires_at' => time() - 1,
                ]],
            ]],
        ], true) . ';');

        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, DatabaseServiceProvider::class,
            SessionServiceProvider::class, OutboundHttpServiceProvider::class,
            QueueServiceProvider::class, HttpServiceProvider::class,
            RoutingServiceProvider::class, CsrfServiceProvider::class,
            WebhookServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $this->database = $this->app->container()->make(DatabaseManager::class);
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_26_create_webhook_receipts.php';
        (new \CreateWebhookReceipts())->up($this->database->connection()->pdo(),
            $this->database->connection()->schema());
        $this->kernel = $this->app->container()->make(Kernel::class);
        $this->diagnostics = $this->app->container()->make(Diagnostics::class);
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->post('/api/webhooks/payments', function (Request $request): JsonResponse {
            $accepted = Webhook::source('payments')->handle($request,
                function (VerifiedWebhook $event): void {
                    ++$this->processed;
                    if ($this->failNext) {
                        $this->failNext = false;
                        throw new RuntimeException('SQUEHUB_WEBHOOK_PRIVATE_HANDLER_FAILURE');
                    }
                    self::assertSame('invoice.paid', $event->type());
                    self::assertSame(['invoice_id' => 42], $event->data());
                });
            return new JsonResponse(['accepted' => $accepted]);
        });
        $routes->post('/api/ordinary', function (Request $request): JsonResponse {
            ++$this->processed;
            return new JsonResponse(['accepted' => true]);
        });
        $routes->post('/api/webhooks/expired', function (Request $request): JsonResponse {
            Webhook::source('expired')->verify($request);
            ++$this->processed;
            return new JsonResponse(['accepted' => true]);
        });
    }

    protected function tearDown(): void
    {
        Webhook::setResolver(null);
        Queue::setResolver(null);
        Http::setResolver(null);
        Route::setResolver(null);
        Session::setResolver(null);
        Database::setResolver(null);
        Diagnostic::setResolver(null);
        if (isset($this->database)) $this->database->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    public function testOnlyExplicitWebhookPathBypassesCsrfAndProcessesOnce(): void
    {
        $event = $this->event();
        $request = $this->signed($event);
        $ordinary = $this->kernel->handle($this->signed($event, path: '/api/ordinary'));
        self::assertSame(403, $ordinary->status());
        self::assertSame(0, $this->processed);
        self::assertSame(0, $this->diagnostics->snapshot()['webhooks']['incoming_verified']);

        $first = $this->kernel->handle($request);
        self::assertSame(200, $first->status());
        self::assertSame(['accepted' => true], json_decode($first->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->processed);
        self::assertSame(1, $this->diagnostics->snapshot()['webhooks']['incoming_verified']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $first->header('X-Request-ID'));

        $duplicate = $this->kernel->handle($request);
        self::assertSame(200, $duplicate->status());
        self::assertSame(['accepted' => false], json_decode($duplicate->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->processed);
        self::assertSame(1, $this->diagnostics->snapshot()['webhooks']['incoming_duplicates']);
        self::assertSame(1, (int) $this->database->connection()->raw(
            'SELECT COUNT(*) FROM `webhook_receipts`')->fetchColumn());
    }

    public function testVerificationAloneDoesNotConsumeReceiptAndRetainsRawBody(): void
    {
        $event = $this->event();
        $request = $this->signed($event);
        $first = Webhook::source('payments')->verify($request);
        $second = Webhook::source('payments')->verify($request);
        self::assertSame($event->id(), $first->id());
        self::assertSame($first->id(), $second->id());
        self::assertSame($event->body(), $request->rawBody());
        self::assertSame(['invoice_id' => 42], $request->json('data'));
        self::assertSame($event->body(), $request->rawBody());
        self::assertSame(0, (int) $this->database->connection()->raw(
            'SELECT COUNT(*) FROM `webhook_receipts`')->fetchColumn());
    }

    public function testFailedApplicationHandlingIsRetryableWithoutDuplicateSideEffect(): void
    {
        $request = $this->signed($this->event());
        $this->failNext = true;
        $first = $this->kernel->handle($request);
        self::assertSame(500, $first->status());
        self::assertStringNotContainsString('SQUEHUB_WEBHOOK_PRIVATE_HANDLER_FAILURE',
            $first->content());
        self::assertStringNotContainsString('SQUEHUB_WEBHOOK_PRIVATE_HANDLER_FAILURE',
            json_encode($this->diagnostics->snapshot(), JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->processed);
        $row = $this->database->connection()->raw('SELECT `state` FROM `webhook_receipts`')->fetch();
        self::assertSame('failed', $row['state']);

        $retry = $this->kernel->handle($request);
        self::assertSame(200, $retry->status());
        self::assertSame(2, $this->processed);
        $row = $this->database->connection()->raw('SELECT `state` FROM `webhook_receipts`')->fetch();
        self::assertSame('processed', $row['state']);
    }

    public function testBadSignatureTimestampContentTypeSizeAndRepeatedHeaderShareSafeError(): void
    {
        $event = $this->event();
        foreach ([
            $this->signed($event, body: $event->body() . ' '),
            $this->signed($event, timestamp: time() - 3600),
            $this->signed($event, timestamp: time() + 3600),
            $this->signed($event, repeatSignatureHeader: true),
            $this->signed($event, body: str_repeat('x', WebhookEvent::MAX_BODY_BYTES + 1)),
            $this->signed($event, contentType: 'text/plain'),
            $this->signed($event, path: '/api/webhooks/expired',
                secret: self::PREVIOUS_SECRET),
        ] as $request) {
            $response = $this->kernel->handle($request);
            self::assertSame(400, $response->status());
            self::assertStringContainsString('invalid_webhook', $response->content());
            self::assertStringNotContainsString(self::CURRENT_SECRET, $response->content());
            self::assertStringNotContainsString('invoice_id', $response->content());
        }
        self::assertSame(0, $this->processed);
        self::assertSame(0, (int) $this->database->connection()->raw(
            'SELECT COUNT(*) FROM `webhook_receipts`')->fetchColumn());
    }

    public function testPreviousSecretIsAcceptedWithinConfiguredRotationWindow(): void
    {
        $event = $this->event();
        $response = $this->kernel->handle($this->signed($event, secret: self::PREVIOUS_SECRET));
        self::assertSame(200, $response->status());
        self::assertSame(1, $this->processed);
        self::assertSame(1, $this->diagnostics->snapshot()['webhooks']['incoming_verified']);
        $snapshot = json_encode($this->diagnostics->snapshot(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::CURRENT_SECRET, $snapshot);
        self::assertStringNotContainsString(self::PREVIOUS_SECRET, $snapshot);
        self::assertStringNotContainsString($event->id(), $snapshot);
        self::assertStringNotContainsString('invoice.paid', $snapshot);
    }

    private function event(): WebhookEvent
    {
        return WebhookEvent::create('invoice.paid', ['invoice_id' => 42],
            new DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    private function signed(WebhookEvent $event, string $path = '/api/webhooks/payments',
        ?string $body = null, ?int $timestamp = null,
        string $secret = self::CURRENT_SECRET, bool $repeatSignatureHeader = false,
        string $contentType = 'application/json'): Request
    {
        $timestamp ??= time();
        $headers = WebhookSignature::headers($event,
            WebhookSignature::generateDeliveryId(), $timestamp, $secret);
        if ($repeatSignatureHeader) {
            $headers['squehub-webhook-signature'] = $headers['SqueHub-Webhook-Signature'];
        }
        $headers['Content-Type'] = $contentType;
        $headers['Accept'] = 'application/json';
        return new Request('POST', $path, [], [], [], [], $headers, [], $body ?? $event->body());
    }
}
