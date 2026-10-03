<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Cache\CacheServiceProvider;
use App\Cache\CacheStore;
use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\BroadcastManager;
use App\Broadcasting\BroadcastServiceProvider;
use App\Broadcasting\Channel;
use App\Config\Repository;
use App\Container\Container;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Events\EventDispatcher;
use App\Events\EventServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpConnectionException;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpServiceProvider as OutgoingHttpServiceProvider;
use App\Observability\ArrayObservationExporter;
use App\Observability\ObservabilityManager;
use App\Observability\ObservabilityServiceProvider;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\QueueServiceProvider;
use App\Queue\Worker;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Scheduler\Scheduler;
use Closure;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** A named route middleware exposes one timing scope without exposing request data. */
final class ObservationPassMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}

/** Sync Queue follows the same dispatch observation path without persistence. */
final class ObservationJob implements QueueJob
{
    public static int $handled = 0;
    public function handle(): void { ++self::$handled; }
    public function toQueuePayload(): array { return []; }
    public static function fromQueuePayload(array $payload): static { return new static(); }
}

/** Public broadcast fixture with private payload that must stay out of observations. */
final class ObservationBroadcastEvent implements BroadcastEvent
{
    public function broadcastName(): string { return 'test.observation'; }
    public function broadcastChannels(): array { return [Channel::public('observations')]; }
    public function broadcastPayload(): array { return ['text' => 'SQUEHUB_SECRET']; }
}

/** Real framework boundaries feed one bounded request report. */
final class ObservabilityIntegrationTest extends TestCase
{
    public function testSchedulerTickCreatesOneIsolatedObservation(): void
    {
        $diagnostics = new Diagnostics(new Repository([]));
        $observer = new ObservabilityManager(['enabled' => true,
            'sampling' => 'all', 'exporter' => 'array']);
        $diagnostics->setObservability($observer);
        $exporter = $observer->exporter();
        self::assertInstanceOf(ArrayObservationExporter::class, $exporter);
        $scheduler = new Scheduler(new Container(), ['store' => 'array'],
            null, null, null, $diagnostics);
        $calls = 0;
        $scheduler->call(static function () use (&$calls): void { ++$calls; })
            ->name('observation-tick')->everyMinute();
        $outcome = $scheduler->run(new DateTimeImmutable('2026-09-30 10:00:00 UTC'));
        self::assertSame(1, $calls);
        self::assertSame(1, $outcome->executed);
        self::assertCount(1, $exporter->reports());
        $report = $exporter->reports()[0];
        self::assertSame('scheduler.tick', $report->operation);
        self::assertSame(1, $report->metrics['scheduler.tick.ok']['count']);
        self::assertSame(1, $report->metrics['scheduler.task.ok']['count']);
        self::assertSame(1, $report->metrics['scheduler.claimed.ok']['count']);
        self::assertSame('scheduler.tick', $report->spans[0]->operation);
        $task = array_values(array_filter($report->spans,
            static fn ($span): bool => $span->operation === 'scheduler.task'))[0];
        self::assertSame('observation-tick', $task->attributes['task']);
        self::assertSame('executed', $task->attributes['result']);
    }

    public function testSchedulerClaimRemainsVisibleAfterTaskFailureWithoutClaimingTwice(): void
    {
        $diagnostics = new Diagnostics(new Repository([]));
        $observer = new ObservabilityManager(['enabled' => true,
            'sampling' => 'all', 'exporter' => 'array']);
        $diagnostics->setObservability($observer);
        $exporter = $observer->exporter();
        self::assertInstanceOf(ArrayObservationExporter::class, $exporter);
        $scheduler = new Scheduler(new Container(), ['store' => 'array'],
            null, null, null, $diagnostics);
        $scheduler->call(static function (): void { throw new \RuntimeException('task failed'); })
            ->name('failing-claim')->everyMinute();
        $instant = new DateTimeImmutable('2026-09-30 10:00:00 UTC');

        self::assertSame(1, $scheduler->run($instant)->failed);
        self::assertSame(1, $exporter->reports()[0]->metrics['scheduler.claimed.ok']['count']);
        $task = array_values(array_filter($exporter->reports()[0]->spans,
            static fn ($span): bool => $span->operation === 'scheduler.task'))[0];
        self::assertSame('failed', $task->attributes['result']);

        self::assertSame(1, $scheduler->run($instant)->skipped);
        self::assertArrayNotHasKey('scheduler.claimed.ok', $exporter->reports()[1]->metrics);
        self::assertSame(1, $exporter->reports()[1]->metrics['scheduler.skipped.ok']['count']);
    }

    public function testOutgoingHttpObservationsCountRetriesAndClassifyFailuresSafely(): void
    {
        $diagnostics = new Diagnostics(new Repository([]));
        $observer = new ObservabilityManager(['enabled' => true,
            'sampling' => 'all', 'exporter' => 'array']);
        $diagnostics->setObservability($observer);
        $exporter = $observer->exporter();
        self::assertInstanceOf(ArrayObservationExporter::class, $exporter);
        $client = new HttpClient([], null, $diagnostics);
        $secret = 'SQUEHUB_SECRET_HTTP_RETRY';
        $client->fake(['GET https://api.example.test/status' => [
            new HttpConnectionException($secret), new HttpResponse(200, 'ok'),
        ]]);

        $scope = $observer->begin('queue.job');
        try {
            self::assertSame(200, $client->pending()->retry(2, 0)
                ->get('https://api.example.test/status')->status());
        } finally {
            $scope?->finish();
        }
        $report = $exporter->reports()[0];
        self::assertSame(2, $report->metrics['http_client.attempt.ok']['count']);
        self::assertSame(1, $report->metrics['http_client.retry.ok']['count']);
        self::assertSame(1, $report->metrics['http_client.request.ok']['count']);
        $request = array_values(array_filter($report->spans,
            static fn ($span): bool => $span->operation === 'http_client.request'))[0];
        self::assertSame('success', $request->attributes['result']);

        $client->fake(['GET https://api.example.test/status' =>
            new HttpConnectionException($secret)]);
        $scope = $observer->begin('queue.job');
        try {
            $client->get('https://api.example.test/status');
            self::fail('Expected the connection failure.');
        } catch (HttpConnectionException $failure) {
            self::assertSame($secret, $failure->getMessage());
        } finally {
            $scope?->finish(failed: true);
        }
        $failureReport = $exporter->reports()[1];
        self::assertSame(1, $failureReport->metrics['http_client.attempt.ok']['count']);
        self::assertSame(1, $failureReport->metrics['http_client.request.error']['count']);
        $request = array_values(array_filter($failureReport->spans,
            static fn ($span): bool => $span->operation === 'http_client.request'))[0];
        self::assertSame('connection_error', $request->attributes['result']);
        self::assertSame('none', $request->attributes['status_class']);
        self::assertStringNotContainsString($secret,
            json_encode($failureReport->toArray(), JSON_THROW_ON_ERROR));

        $client->fake(['GET https://api.example.test/status' => new HttpResponse(503, $secret)]);
        $scope = $observer->begin('queue.job');
        try {
            self::assertSame(503, $client->get('https://api.example.test/status')->status());
        } finally {
            $scope?->finish();
        }
        $statusReport = $exporter->reports()[2];
        self::assertSame(1, $statusReport->metrics['http_client.request.error']['count']);
        $request = array_values(array_filter($statusReport->spans,
            static fn ($span): bool => $span->operation === 'http_client.request'))[0];
        self::assertSame('http_status', $request->attributes['result']);
        self::assertSame('5xx', $request->attributes['status_class']);
        self::assertStringNotContainsString($secret,
            json_encode($statusReport->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testSeparateWorkerJobsHaveIsolatedBoundedReports(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $project = new TemporaryProject();
        $database = new DatabaseManager(new Repository(['database' => ['default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite',
                'database' => $project->path('observation-queue.sqlite')]]]]));
        try {
            $connection = $database->connection();
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_25_add_failed_queue_payload.php';
            (new \CreateQueueTables())->up($connection->pdo(), $connection->schema());
            (new \AddFailedQueuePayload())->up($connection->pdo(), $connection->schema());
            $diagnostics = new Diagnostics(new Repository([]));
            $observer = new ObservabilityManager(['enabled' => true,
                'sampling' => 'all', 'exporter' => 'array']);
            $diagnostics->setObservability($observer);
            $exporter = $observer->exporter();
            self::assertInstanceOf(ArrayObservationExporter::class, $exporter);
            $queue = new QueueManager(['default' => 'database', 'connections' => [
                'database' => ['driver' => 'database', 'database_connection' => 'test',
                    'retry_after' => 2],
            ]], fn (?string $name) => $database->connection($name), null, $diagnostics);
            $queue->dispatch(new ObservationJob(), 'first-12345');
            $queue->dispatch(new ObservationJob(), 'second-67890');
            $worker = new Worker($queue, $diagnostics);
            ObservationJob::$handled = 0;
            self::assertTrue($worker->workOnce('first-12345'));
            self::assertTrue($worker->workOnce('second-67890'));
            self::assertSame(2, ObservationJob::$handled);
            $reports = $exporter->reports();
            self::assertCount(2, $reports);
            self::assertNotSame($reports[0]->traceId, $reports[1]->traceId);
            self::assertNotSame($reports[0]->correlationId, $reports[1]->correlationId);
            foreach ($reports as $report) {
                self::assertSame('queue.job', $report->operation);
                self::assertSame(1, $report->metrics['queue.job.ok']['count']);
                self::assertSame(1, $report->metrics['queue.processed.ok']['count']);
                self::assertContains('queue.job', array_map(
                    static fn ($span): string => $span->operation, $report->spans));
                self::assertSame(ObservationJob::class, $report->spans[0]->attributes['job_class']);
                self::assertSame('database', $report->spans[0]->attributes['connection']);
                self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/',
                    (string) $report->correlationId);
                self::assertSame(['queue.processed.ok', 'queue.job.ok'], array_keys($report->metrics));
            }
        } finally {
            $database->disconnect();
            $project->remove();
        }
    }

    public function testHttpSqlCacheEventQueueAndOutgoingHttpShareOneTrace(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $project = new TemporaryProject();
        try {
            $project->write('Config/Observability.php',
                '<?php return ["enabled"=>true,"sampling"=>"all","exporter"=>"array"];');
            $project->write('Config/Cache.php', '<?php return ["driver"=>"array"];');
            $project->write('Config/Database.php',
                '<?php return ["default"=>"sqlite","connections"=>["sqlite"=>'
                . '["driver"=>"sqlite","database"=>":memory:"]]];');
            $project->write('Config/Queue.php',
                '<?php return ["default"=>"sync","connections"=>["sync"=>["driver"=>"sync"]]];');
            $project->write('Config/Broadcasting.php',
                '<?php return ["enabled"=>true,"driver"=>"array"];');
            $app = new Application($project->path());
            foreach ([DiagnosticsServiceProvider::class, ObservabilityServiceProvider::class,
                EventServiceProvider::class, CacheServiceProvider::class,
                DatabaseServiceProvider::class, QueueServiceProvider::class,
                BroadcastServiceProvider::class,
                OutgoingHttpServiceProvider::class, HttpServiceProvider::class,
                RoutingServiceProvider::class] as $provider) {
                $app->register($provider);
            }
            $app->bootstrap();
            $container = $app->container();
            $observer = $container->make(ObservabilityManager::class);
            $exporter = $observer->exporter();
            self::assertInstanceOf(ArrayObservationExporter::class, $exporter);
            $database = $container->make(DatabaseManager::class);
            $database->raw('CREATE TABLE observation_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
            $database->raw('INSERT INTO observation_items (id, name) VALUES (?, ?)', [12345, 'SQUEHUB_SECRET']);
            $cache = $container->make(CacheStore::class);
            $events = $container->make(EventDispatcher::class);
            $events->listen(\stdClass::class, static function (\stdClass $event): void {});
            $queue = $container->make(QueueManager::class);
            $broadcast = $container->make(BroadcastManager::class);
            $outbound = $container->make(HttpClient::class);
            $outbound->fake(['GET https://api.example.test/status' => new HttpResponse(200, 'ok')]);
            ObservationJob::$handled = 0;
            $routes = $container->make(RouteRegistry::class);
            $routes->add('GET', '/observed/{id}', function (Request $request) use (
                $database, $cache, $events, $queue, $broadcast, $outbound): Response {
                $row = $database->table('observation_items')->filter('id', (int) $request->route('id'))->first();
                $cache->store('SQUEHUB_SECRET_CACHE_KEY', $row['name']);
                $cache->read('SQUEHUB_SECRET_CACHE_KEY');
                $events->emit(new \stdClass());
                $queue->dispatch(new ObservationJob());
                $broadcast->send(new ObservationBroadcastEvent());
                $outbound->pending()->withToken('SQUEHUB_SECRET_TOKEN')
                    ->get('https://api.example.test/status');
                return new Response('ok');
            })->named('observed.show')->through(ObservationPassMiddleware::class);

            $response = $container->make(Kernel::class)->handle(new Request('GET', '/observed/12345'));
            self::assertSame(200, $response->status());
            self::assertSame(1, ObservationJob::$handled);
            self::assertCount(1, $exporter->reports());
            $report = $exporter->reports()[0];
            $operations = array_map(static fn ($span): string => $span->operation, $report->spans);
            foreach (['http.request', 'http.match', 'http.route', 'http.middleware',
                'http.controller', 'db.query', 'cache.write', 'cache.read', 'event.emit',
                'event.listener', 'queue.dispatch', 'broadcast.publish',
                'http_client.request'] as $operation) {
                self::assertContains($operation, $operations);
            }
            $broadcastSpan = array_values(array_filter($report->spans,
                static fn ($span): bool => $span->operation === 'broadcast.publish'))[0];
            self::assertSame('public', $broadcastSpan->attributes['channel_category']);
            self::assertSame('sync', $broadcastSpan->attributes['mode']);
            self::assertSame('array', $broadcastSpan->attributes['transport']);
            self::assertSame('/observed/{id}', $report->spans[0]->attributes['route_pattern']);
            self::assertSame($response->header('X-Request-ID'), $report->correlationId);
            self::assertSame(1, $report->metrics['db.query.ok']['count']);
            self::assertSame(1, $container->make(Diagnostics::class)->snapshot()['database']['queries']);
            $encoded = json_encode($report->toArray(), JSON_THROW_ON_ERROR);
            foreach (['SQUEHUB_SECRET', '12345', 'observation_items'] as $private) {
                self::assertStringNotContainsString($private, $encoded);
            }
        } finally {
            $project->remove();
        }
    }
}
