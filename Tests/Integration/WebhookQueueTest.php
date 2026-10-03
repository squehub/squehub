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
use App\Http\Request;
use App\HttpClient\Http;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpServiceProvider as OutboundHttpServiceProvider;
use App\Queue\Queue;
use App\Queue\QueueManager;
use App\Queue\QueueServiceProvider;
use App\Queue\Worker;
use App\Webhooks\Webhook;
use App\Webhooks\WebhookEvent;
use App\Webhooks\WebhookServiceProvider;
use App\Webhooks\WebhookSignature;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Database Queue and outer-transaction tests preserve event identity across processes. */
final class WebhookQueueTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';
    private const URL = 'https://hooks.example.test/receive';

    private TemporaryProject $project;
    private Application $app;
    private DatabaseManager $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for webhook Queue integration.');
        }
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env" => "production", "debug" => false];');
        $this->project->write('Config/Database.php', '<?php return ' . var_export([
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => $this->project->path('webhook-queue.sqlite'),
            ]],
        ], true) . ';');
        $this->project->write('Config/Queue.php', '<?php return ' . var_export([
            'default' => 'database', 'connections' => [
                'database' => ['driver' => 'database', 'database_connection' => 'test'],
                'sync' => ['driver' => 'sync'],
            ],
        ], true) . ';');
        $this->project->write('Config/Webhooks.php', '<?php return ' . var_export([
            'endpoints' => ['billing' => [
                'url' => self::URL, 'secret' => self::SECRET,
                'max_attempts' => 1,
            ]],
            'receipt_store' => 'array',
        ], true) . ';');

        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, DatabaseServiceProvider::class,
            QueueServiceProvider::class, OutboundHttpServiceProvider::class,
            WebhookServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $this->database = $this->app->container()->make(DatabaseManager::class);
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        (new \CreateQueueTables())->up($this->database->connection()->pdo(),
            $this->database->connection()->schema());
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_26_create_webhook_deliveries.php';
        (new \CreateWebhookDeliveries())->up($this->database->connection()->pdo(),
            $this->database->connection()->schema());
    }

    protected function tearDown(): void
    {
        Webhook::setResolver(null);
        Queue::setResolver(null);
        Http::setResolver(null);
        Database::setResolver(null);
        Diagnostic::setResolver(null);
        if (isset($this->database)) $this->database->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    public function testDatabaseQueuePersistsBoundedWorkAndCliWorkerDeliversAcrossProcesses(): void
    {
        $marker = 'SQUEHUB_WEBHOOK_PRIVATE_DATA';
        $diagnostics = $this->app->container()->make(Diagnostics::class);
        $diagnostics->begin(new Request('POST', '/test/dispatch'));
        $ticket = Webhook::endpoint('billing')->queue('order.created', ['marker' => $marker]);
        self::assertMatchesRegularExpression('/\Aevt_[A-Za-z0-9_-]{32}\z/', $ticket->eventId());
        self::assertMatchesRegularExpression('/\Awhd_[A-Za-z0-9_-]{32}\z/', $ticket->deliveryId());
        self::assertSame(1, $diagnostics->snapshot()['webhooks']['outgoing_events']);
        self::assertSame(0, $diagnostics->snapshot()['webhooks']['delivery_attempts']);
        self::assertStringNotContainsString($marker,
            json_encode($diagnostics->snapshot(), JSON_THROW_ON_ERROR));

        $stored = (string) $this->database->connection()->raw(
            'SELECT `payload` FROM `queue_jobs`')->fetchColumn();
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString(self::SECRET, $stored);
        self::assertStringNotContainsString(self::URL, $stored);
        self::assertStringNotContainsString('SqueHub-Webhook-Signature', $stored);
        self::assertStringContainsString($marker, $stored);

        $root = dirname(__DIR__, 2);
        $runner = '<?php require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . 'final class WebhookCaptureTransport implements \\App\\HttpClient\\HttpTransport {'
            . 'public function send(\\App\\HttpClient\\OutgoingRequest $request): '
            . '\\App\\HttpClient\\HttpResponse {'
            . 'file_put_contents(__DIR__."/capture.json", json_encode(['
            . '["method"=>$request->method,"body"=>$request->body,'
            . '"headers"=>$request->headers]], JSON_THROW_ON_ERROR));'
            . 'return new \\App\\HttpClient\\HttpResponse(204); }} '
            . '$squehubApp=new \\App\\Foundation\\Application(__DIR__); '
            . 'foreach ([\\App\\Database\\DatabaseServiceProvider::class,'
            . '\\App\\Queue\\QueueServiceProvider::class,'
            . '\\App\\HttpClient\\HttpServiceProvider::class,'
            . '\\App\\Webhooks\\WebhookServiceProvider::class] as $provider) '
            . '$squehubApp->register($provider); $squehubApp->bootstrap(); '
            . '$squehubApp->container()->instance(\\App\\HttpClient\\HttpClient::class,'
            . 'new \\App\\HttpClient\\HttpClient([],new WebhookCaptureTransport())); '
            . 'require ' . var_export($root . '/squehub', true) . ';';
        $this->project->write('CliRunner.php', $runner);
        $worker = new Process([PHP_BINARY, $this->project->path('CliRunner.php'),
            'queue:work', '--once'], $root);
        $worker->run();
        self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput() . $worker->getOutput());
        self::assertStringContainsString('Processed 1 Queue job(s).', $worker->getOutput());
        self::assertFileExists($this->project->path('capture.json'));
        $captured = json_decode((string) file_get_contents($this->project->path('capture.json')),
            true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $captured);
        self::assertSame('POST', $captured[0]['method']);
        $body = (string) $captured[0]['body'];
        $event = WebhookEvent::fromJson($body);
        self::assertSame($ticket->eventId(), $event->id());
        self::assertSame(['marker' => $marker], $event->data());
        $headers = $captured[0]['headers'];
        self::assertSame($ticket->deliveryId(), $headers['squehub-webhook-delivery-id']);
        self::assertTrue(WebhookSignature::verify($event->id(), $ticket->deliveryId(),
            (int) $headers['squehub-webhook-timestamp'], $body,
            $headers['squehub-webhook-signature'], [self::SECRET]));
        self::assertSame(0, (int) $this->database->connection()->raw(
            'SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
        self::assertSame(0, (int) $this->database->connection()->raw(
            'SELECT COUNT(*) FROM `queue_failed_jobs`')->fetchColumn());
    }

    public function testAfterCommitWaitsForOutermostCommitAndDiscardsRolledBackDispatch(): void
    {
        $connection = $this->database->connection();
        $endpoint = Webhook::endpoint('billing');

        $connection->begin();
        $discarded = $endpoint->afterCommit('order.created', ['case' => 'outer-rollback'],
            connection: 'database', transactionConnection: 'test');
        self::assertSame(0, $this->queuedCount());
        $connection->rollback();
        self::assertSame(0, $this->queuedCount());

        $connection->begin();
        $connection->begin();
        $kept = $endpoint->afterCommit('order.created', ['case' => 'nested-commit'],
            connection: 'database', transactionConnection: 'test');
        $connection->commit();
        self::assertSame(0, $this->queuedCount());
        $connection->commit();
        self::assertSame(1, $this->queuedCount());
        $payload = (string) $connection->raw('SELECT `payload` FROM `queue_jobs`')->fetchColumn();
        self::assertStringContainsString($kept->eventId(), $payload);
        self::assertStringNotContainsString($discarded->eventId(), $payload);

        $connection->begin();
        $connection->begin();
        $endpoint->afterCommit('order.created', ['case' => 'nested-rollback'],
            connection: 'database', transactionConnection: 'test');
        $connection->commit();
        $connection->rollback();
        self::assertSame(1, $this->queuedCount());
    }

    public function testSyncQueueDeliveryRunsImmediatelyWithPrivateDiagnostics(): void
    {
        $client = $this->app->container()->make(HttpClient::class);
        $client->fake(['POST ' . self::URL => new HttpResponse(204)]);
        $diagnostics = $this->app->container()->make(Diagnostics::class);
        $diagnostics->begin(new Request('POST', '/test/sync'));
        $ticket = Webhook::endpoint('billing')->queue('order.created',
            ['marker' => 'SQUEHUB_WEBHOOK_SYNC_PRIVATE'], connection: 'sync');
        self::assertSame(0, $this->queuedCount());
        self::assertCount(1, $client->captured());
        self::assertSame($ticket->eventId(),
            WebhookEvent::fromJson((string) $client->captured()[0]->body)->id());
        $metrics = $diagnostics->snapshot()['webhooks'];
        self::assertSame(1, $metrics['outgoing_events']);
        self::assertSame(1, $metrics['delivery_attempts']);
        self::assertSame(1, $metrics['delivery_successes']);
        self::assertStringNotContainsString('SQUEHUB_WEBHOOK_SYNC_PRIVATE',
            json_encode($diagnostics->snapshot(), JSON_THROW_ON_ERROR));
    }

    public function testTransientFailureUsesQueueRetryWithStableDeliveryIdentity(): void
    {
        $client = $this->app->container()->make(HttpClient::class);
        $client->fake(['POST ' . self::URL => [new HttpResponse(503), new HttpResponse(204)]]);
        $ticket = Webhook::endpoint('billing')->queue('order.created', ['order' => 42]);

        self::assertTrue($this->worker()->workOnce(tries: 2, backoff: 0));
        self::assertSame(1, $this->queuedCount());
        $first = $this->database->connection()->raw(
            'SELECT `state`, `attempt_count`, `last_http_status` FROM `webhook_deliveries`')->fetch();
        self::assertSame('retrying', $first['state']);
        self::assertSame(1, (int) $first['attempt_count']);
        self::assertSame(503, (int) $first['last_http_status']);

        self::assertTrue($this->worker()->workOnce(tries: 2, backoff: 0));
        self::assertSame(0, $this->queuedCount());
        self::assertCount(2, $client->captured());
        foreach ($client->captured() as $request) {
            $event = WebhookEvent::fromJson((string) $request->body);
            self::assertSame($ticket->eventId(), $event->id());
            self::assertSame($ticket->deliveryId(),
                $request->headers['squehub-webhook-delivery-id']);
            self::assertTrue(WebhookSignature::verify($event->id(), $ticket->deliveryId(),
                (int) $request->headers['squehub-webhook-timestamp'], $event->body(),
                $request->headers['squehub-webhook-signature'], [self::SECRET]));
        }
        $last = $this->database->connection()->raw(
            'SELECT `state`, `attempt_count`, `last_http_status` FROM `webhook_deliveries`')->fetch();
        self::assertSame('succeeded', $last['state']);
        self::assertSame(2, (int) $last['attempt_count']);
        self::assertSame(204, (int) $last['last_http_status']);
        self::assertSame(0, (int) $this->database->connection()->raw(
            'SELECT COUNT(*) FROM `queue_failed_jobs`')->fetchColumn());
    }

    public function testTerminalPeerRejectionIsAcknowledgedWithoutRetry(): void
    {
        $client = $this->app->container()->make(HttpClient::class);
        $client->fake(['POST ' . self::URL => new HttpResponse(400)]);
        $ticket = Webhook::endpoint('billing')->queue('order.created', ['order' => 43]);

        self::assertTrue($this->worker()->workOnce(tries: 3, backoff: 0));
        self::assertSame(0, $this->queuedCount());
        self::assertCount(1, $client->captured());
        $event = WebhookEvent::fromJson((string) $client->captured()[0]->body);
        self::assertSame($ticket->eventId(), $event->id());
        self::assertSame($ticket->deliveryId(),
            $client->captured()[0]->headers['squehub-webhook-delivery-id']);
        $row = $this->database->connection()->raw(
            'SELECT `state`, `attempt_count`, `last_http_status`, `last_failure_category` '
            . 'FROM `webhook_deliveries`')->fetch();
        self::assertSame('failed', $row['state']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertSame(400, (int) $row['last_http_status']);
        self::assertSame('http_status', $row['last_failure_category']);
        self::assertSame(0, (int) $this->database->connection()->raw(
            'SELECT COUNT(*) FROM `queue_failed_jobs`')->fetchColumn());
    }

    public function testExhaustedTransientDeliveryRetainsOnlySafeFailureMetadata(): void
    {
        $private = 'SQUEHUB_WEBHOOK_QUEUE_PRIVATE_PAYLOAD';
        $client = $this->app->container()->make(HttpClient::class);
        $client->fake(['POST ' . self::URL => [new HttpResponse(503), new HttpResponse(503)]]);
        $ticket = Webhook::endpoint('billing')->queue('order.created', ['secret' => $private]);

        self::assertTrue($this->worker()->workOnce(tries: 2, backoff: 0));
        self::assertTrue($this->worker()->workOnce(tries: 2, backoff: 0));
        self::assertSame(0, $this->queuedCount());
        self::assertCount(2, $client->captured());
        foreach ($client->captured() as $request) {
            self::assertSame($ticket->eventId(),
                WebhookEvent::fromJson((string) $request->body)->id());
            self::assertSame($ticket->deliveryId(),
                $request->headers['squehub-webhook-delivery-id']);
        }
        $failed = $this->database->connection()->raw(
            'SELECT `job_class`, `error_type`, `error_message`, `attempts` '
            . 'FROM `queue_failed_jobs`')->fetch();
        self::assertIsArray($failed);
        self::assertSame(2, (int) $failed['attempts']);
        self::assertSame('Job failed after maximum attempts.', $failed['error_message']);
        self::assertStringNotContainsString($private,
            json_encode($failed, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::SECRET,
            json_encode($failed, JSON_THROW_ON_ERROR));
        $ledger = $this->database->connection()->raw(
            'SELECT `state`, `attempt_count`, `last_http_status`, `last_failure_category` '
            . 'FROM `webhook_deliveries`')->fetch();
        self::assertSame('failed', $ledger['state']);
        self::assertSame(2, (int) $ledger['attempt_count']);
        self::assertSame(503, (int) $ledger['last_http_status']);
        self::assertSame('http_status', $ledger['last_failure_category']);
    }

    private function worker(): Worker
    {
        return new Worker($this->app->container()->make(QueueManager::class),
            $this->app->container()->make(Diagnostics::class), $this->app->container());
    }

    private function queuedCount(): int
    {
        return (int) $this->database->connection()->raw(
            'SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn();
    }
}
