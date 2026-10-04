<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Container\Container;
use App\Database\DatabaseManager;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpServiceProvider as OutgoingHttpServiceProvider;
use App\Logging\Drivers\ArrayLogger;
use App\Logging\Logger;
use App\Logging\LoggingServiceProvider;
use App\Observability\CorrelationContext;
use App\Queue\QueueCodec;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\Worker;
use App\Redis\RedisClient;
use App\Redis\RedisManager;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Scheduler\Scheduler;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;
use SqueHub\Tests\Fixtures\FakeRedisQueueClient;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__) . '/Fixtures/FakeRedisQueueClient.php';

/** Test job reads only the current Application context, never job payload metadata. */
final class CorrelationObservedJob implements QueueJob
{
    public static ?CorrelationContext $context = null;
    public static ?Diagnostics $diagnostics = null;
    /** @var list<array{label:string,id:?string}> */
    public static array $seen = [];
    /** @var list<array<string,mixed>> */
    public static array $snapshots = [];

    public function __construct(private string $label) {}
    public function handle(): void
    {
        self::$seen[] = ['label' => $this->label, 'id' => self::$context?->current()];
        if (self::$diagnostics !== null) self::$snapshots[] = self::$diagnostics->snapshot();
    }
    public function toQueuePayload(): array { return ['label' => $this->label]; }
    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) $payload['label']);
    }
}

/** Request, Queue, Scheduler, HTTP client, and Logger share one bounded ID. */
final class CorrelationIntegrationTest extends TestCase
{
    private DatabaseManager $database;
    private Diagnostics $diagnostics;
    private QueueManager $queue;
    private Worker $worker;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for correlation integration.');
        }
        $this->database = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' =>
                ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        $connection = $this->database->connection();
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_30_create_queue_compositions.php';
        (new \CreateQueueTables())->up($connection->pdo(), $connection->schema());
        (new \CreateQueueCompositions())->up($connection->pdo(), $connection->schema());
        $this->diagnostics = new Diagnostics(new Repository([]));
        $this->queue = new QueueManager(['default' => 'database', 'connections' => [
            'database' => ['driver' => 'database', 'database_connection' => 'test', 'retry_after' => 1],
            'sync' => ['driver' => 'sync'],
        ]], fn (?string $name) => $this->database->connection($name), null, $this->diagnostics);
        $this->worker = new Worker($this->queue, $this->diagnostics);
        CorrelationObservedJob::$context = $this->diagnostics->correlation();
        CorrelationObservedJob::$diagnostics = $this->diagnostics;
        CorrelationObservedJob::$seen = [];
        CorrelationObservedJob::$snapshots = [];
    }

    protected function tearDown(): void
    {
        CorrelationObservedJob::$context = null;
        CorrelationObservedJob::$diagnostics = null;
        $this->database->disconnect();
    }

    public function testWorkerInheritsEnvelopeAndClearsBetweenJobsIncludingLegacyPayload(): void
    {
        $this->diagnostics->begin(new Request('GET', '/prior'));
        $priorRequest = $this->diagnostics->requestId();
        $this->diagnostics->finish(new Response('prior'));
        $context = $this->diagnostics->correlation();
        $origin = $context->begin();
        $this->queue->dispatch(new CorrelationObservedJob('origin'));
        $context->clear();
        $this->queue->dispatch(new CorrelationObservedJob('independent'));
        // Direct driver writes an old v1 envelope with no metadata.
        $this->queue->driver()->dispatch(new CorrelationObservedJob('legacy'), 'default', 0);
        $payloads = $this->payloads();
        self::assertSame($origin, QueueCodec::correlationId($payloads[0]));
        self::assertNotSame($origin, QueueCodec::correlationId($payloads[1]));
        self::assertNull(QueueCodec::correlationId($payloads[2]));
        foreach (range(1, 3) as $_) {
            self::assertTrue($this->worker->workOnce());
            self::assertNull($context->current());
        }
        self::assertSame(['origin', 'independent', 'legacy'],
            array_column(CorrelationObservedJob::$seen, 'label'));
        self::assertSame($origin, CorrelationObservedJob::$seen[0]['id']);
        self::assertNotSame($priorRequest, $origin);
        self::assertNull(CorrelationObservedJob::$snapshots[0]['request_id']);
        self::assertNull(CorrelationObservedJob::$snapshots[0]['method']);
        self::assertSame($origin, CorrelationObservedJob::$snapshots[0]['correlation_id']);
        self::assertSame(QueueCodec::correlationId($payloads[1]), CorrelationObservedJob::$seen[1]['id']);
        self::assertNull(CorrelationObservedJob::$snapshots[1]['request_id']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/',
            (string) CorrelationObservedJob::$seen[2]['id']);
        self::assertNotSame($origin, CorrelationObservedJob::$seen[2]['id']);
    }

    public function testChainBatchAndAfterCommitCaptureOriginWithoutUsingJobData(): void
    {
        $context = $this->diagnostics->correlation();
        $origin = $context->begin();
        $this->queue->chain([new CorrelationObservedJob('chain-1'),
            new CorrelationObservedJob('chain-2')]);
        $this->queue->batch([new CorrelationObservedJob('batch-1'),
            new CorrelationObservedJob('batch-2')]);
        $this->database->connection()->transaction(function () use ($context): void {
            $this->queue->afterCommit(new CorrelationObservedJob('deferred'),
                transactionConnection: 'test');
            // The commit callback runs after this replacement, yet its
            // envelope must retain the ID captured at registration.
            $context->begin(str_repeat('b', 32));
        });
        $context->clear();
        $queued = $this->payloads();
        $dormant = $this->database->raw('SELECT `payload` FROM `queue_composition_items`'
            . ' WHERE `payload` IS NOT NULL ORDER BY `position`')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(4, $queued);
        self::assertCount(1, $dormant);
        foreach ([...$queued, ...$dormant] as $payload) {
            self::assertSame($origin, QueueCodec::correlationId((string) $payload));
            self::assertArrayNotHasKey('correlation_id',
                json_decode((string) $payload, true, 32, JSON_THROW_ON_ERROR)['data']);
        }
        for ($i = 0; $i < 5; ++$i) self::assertTrue($this->worker->workOnce());
        self::assertCount(5, CorrelationObservedJob::$seen);
        foreach (CorrelationObservedJob::$seen as $seen) self::assertSame($origin, $seen['id']);
        self::assertNull($context->current());
    }

    public function testSchedulerInvocationsUseFreshIdsAndQueuedJobsInheritEachTick(): void
    {
        $context = $this->diagnostics->correlation();
        $scheduler = new Scheduler(new Container(), ['store' => 'array'],
            null, fn () => $this->queue, null, $this->diagnostics);
        $during = [];
        $scheduler->call(static function () use (&$during, $context): void {
            $during[] = $context->current();
        })->name('observe-tick')->everyMinute();
        $scheduler->job(new CorrelationObservedJob('scheduled'))
            ->name('dispatch-tick')->everyMinute();
        $scheduler->run(new DateTimeImmutable('2026-09-30 10:00:00 UTC'));
        self::assertNull($context->current());
        $scheduler->run(new DateTimeImmutable('2026-09-30 10:01:00 UTC'));
        self::assertNull($context->current());
        self::assertCount(2, $during);
        self::assertNotSame($during[0], $during[1]);
        $payloads = $this->payloads();
        self::assertSame($during[0], QueueCodec::correlationId($payloads[0]));
        self::assertSame($during[1], QueueCodec::correlationId($payloads[1]));
    }

    public function testRedisDriverPersistsAndWorkerRestoresEnvelopeCorrelation(): void
    {
        $client = new FakeRedisQueueClient();
        $redis = new RedisManager(['default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['host' => 'localhost', 'prefix' => 'correlation:']]],
            null, static fn (): RedisClient => $client,
            static fn (string $class): bool => $class === \Redis::class);
        $queue = new QueueManager(['default' => 'redis', 'connections' => [
            'redis' => ['driver' => 'redis', 'redis_connection' => 'main',
                'namespace' => 'correlation-test', 'retry_after' => 5],
        ]], null, null, $this->diagnostics, $redis);
        $id = $this->diagnostics->correlation()->begin();
        $queue->dispatch(new CorrelationObservedJob('redis'));
        $this->diagnostics->correlation()->clear();
        $driver = $queue->driver();
        self::assertInstanceOf(\App\Queue\Drivers\RedisQueueDriver::class, $driver);
        $reserved = $driver->reserve('default');
        self::assertNotNull($reserved);
        self::assertSame($id, QueueCodec::correlationId($reserved->payload));
        $driver->release($reserved, 0);
        self::assertTrue((new Worker($queue, $this->diagnostics))->workOnce());
        self::assertSame($id, CorrelationObservedJob::$seen[0]['id']);
        self::assertNull($this->diagnostics->correlation()->current());
    }

    public function testHttpErrorsLogsAndOutboundHeadersUseSafeLocalIdentity(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Logging.php', '<?php return ["driver"=>"array","level"=>"debug"];');
            $app = new Application($project->path());
            foreach ([DiagnosticsServiceProvider::class, LoggingServiceProvider::class,
                OutgoingHttpServiceProvider::class, HttpServiceProvider::class,
                RoutingServiceProvider::class] as $provider) $app->register($provider);
            $app->bootstrap();
            $container = $app->container();
            $client = $container->make(HttpClient::class);
            $client->fake(['GET https://example.test/correlation' => new HttpResponse(200, 'ok')]);
            $logger = $container->make(Logger::class);
            $routes = $container->make(RouteRegistry::class);
            $routes->add('GET', '/correlated', function () use ($client, $logger): Response {
                $logger->info('during request');
                $client->get('https://example.test/correlation');
                $client->pending()->withHeaders(['X-Correlation-ID' => 'application-choice'])
                    ->get('https://example.test/correlation');
                return new Response('ok', 200, ['X-Correlation-ID' => 'controller-spoof']);
            });
            $routes->add('GET', '/error', static function (): never {
                throw new RuntimeException('private error');
            });
            $kernel = $container->make(Kernel::class);
            $spoof = str_repeat('c', 32);
            $response = $kernel->handle(new Request('GET', '/correlated',
                headers: ['X-Request-ID' => $spoof,
                    'X-Correlation-ID' => "attacker\r\nInjected: yes"]));
            $id = $response->header('X-Correlation-ID');
            self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $id);
            self::assertNotSame($spoof, $id);
            self::assertSame($id, $response->header('X-Request-ID'));
            self::assertSame($id, $client->captured()[0]->headers['x-correlation-id']);
            self::assertSame('application-choice', $client->captured()[1]->headers['x-correlation-id']);
            $records = $container->make(ArrayLogger::class)->records();
            self::assertSame($id, $records[0]->context['request_id']);
            self::assertSame($id, $records[0]->context['correlation_id']);
            self::assertNull($container->make(Diagnostics::class)->correlation()->current());
            $logger->info('after request');
            $after = $container->make(ArrayLogger::class)->records()[1];
            self::assertArrayNotHasKey('request_id', $after->context);
            self::assertArrayNotHasKey('correlation_id', $after->context);

            $error = $kernel->handle(new Request('GET', '/error',
                headers: ['X-Correlation-ID' => $spoof]));
            self::assertSame(500, $error->status());
            self::assertSame($error->header('X-Request-ID'), $error->header('X-Correlation-ID'));
            self::assertNotSame($id, $error->header('X-Correlation-ID'));
            $errorLog = $container->make(ArrayLogger::class)->records()[2];
            self::assertSame($error->header('X-Correlation-ID'),
                $errorLog->context['correlation_id']);
            self::assertStringNotContainsString('attacker', $error->content());
            self::assertNull($container->make(Diagnostics::class)->correlation()->current());
        } finally {
            $project->remove();
        }
    }

    public function testTwoApplicationsDoNotShareCorrelationContext(): void
    {
        $firstProject = new TemporaryProject();
        $secondProject = new TemporaryProject();
        try {
            $first = new Application($firstProject->path());
            $second = new Application($secondProject->path());
            foreach ([$first, $second] as $app) {
                $app->register(DiagnosticsServiceProvider::class);
                $app->bootstrap();
            }
            $one = $first->container()->make(Diagnostics::class)->correlation();
            $two = $second->container()->make(Diagnostics::class)->correlation();
            self::assertNotSame($one, $two);
            $one->begin();
            self::assertNull($two->current());
        } finally {
            $firstProject->remove();
            $secondProject->remove();
        }
    }

    /** @return list<string> */
    private function payloads(): array
    {
        return $this->database->raw('SELECT `payload` FROM `queue_jobs` ORDER BY `id`')
            ->fetchAll(PDO::FETCH_COLUMN);
    }
}
