<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Agent\AgentContext;
use App\Agent\AgentManager;
use App\Agent\Mcp\McpServerAdapter;
use App\Container\Container;
use App\Database\DatabaseManager;
use App\Diagnostics\Diagnostics;
use App\Http\Kernel;
use App\Http\Request;
use App\Queue\QueueAttemptContextAware;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\Worker;
use App\Routing\RouteRegistry;
use App\Scheduler\Scheduler;
use App\Testing\TestApplication;
use App\Translation\Translation;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** A JSON-safe job whose first and final failures exercise worker cleanup. */
final class StabilizationLifecycleJob implements QueueJob, QueueAttemptContextAware
{
    /** @var list<array{id:int,attempt:int,locale:string}> */
    public static array $observed = [];

    private int $attempt = 0;

    public function __construct(private readonly int $id)
    {
    }

    public function setQueueAttemptContext(int $attempt, int $maxAttempts): void
    {
        $this->attempt = $attempt;
    }

    public function handle(): void
    {
        self::$observed[] = [
            'id' => $this->id,
            'attempt' => $this->attempt,
            'locale' => Translation::locale(),
        ];
        Translation::setLocale('fr');
        if (($this->id === 30 && $this->attempt === 1) || $this->id === 31) {
            throw new RuntimeException('Lifecycle probe failure.');
        }
    }

    /** @return array{id:int} */
    public function toQueuePayload(): array
    {
        return ['id' => $this->id];
    }

    /** @param array<string,mixed> $payload */
    public static function fromQueuePayload(array $payload): static
    {
        return new static($payload['id']);
    }
}

/** Repeated real Application boundaries must release per-request and per-job state. */
final class StabilizationLifecycleTest extends TestCase
{
    protected function tearDown(): void
    {
        StabilizationLifecycleJob::$observed = [];
    }

    public function testRepeatedApplicationsAndRequestsKeepStateLocal(): void
    {
        $warmBytes = null;
        for ($index = 0; $index < 16; ++$index) {
            $fixture = TestApplication::temporary(['translation' => [
                'default' => 'en', 'fallback' => 'en', 'supported' => ['en', 'fr'],
            ]]);
            try {
                $application = $fixture->application();
                $diagnostics = $application->container()->make(Diagnostics::class);
                $routes = $application->container()->make(RouteRegistry::class);
                $routes->get('/switch', static function () use ($diagnostics): string {
                    $diagnostics->auth('attempts');
                    Translation::setLocale('fr');
                    return Translation::locale();
                });
                $routes->get('/read', static fn (): string => Translation::locale());
                $routes->get('/only-' . $index, static fn (): string => 'local');
                $kernel = $application->container()->make(Kernel::class);

                self::assertSame('fr', $kernel->handle(new Request('GET', '/switch'))->content());
                self::assertSame(1, $diagnostics->snapshot()['auth']['attempts']);
                self::assertNull($application->views()->currentRequest());
                self::assertSame('en', $kernel->handle(new Request('GET', '/read'))->content());
                self::assertSame(0, $diagnostics->snapshot()['auth']['attempts']);
                self::assertNull($application->views()->currentRequest());
                self::assertSame('local', $kernel->handle(new Request('GET', '/only-' . $index))->content());
                if ($index > 0) {
                    self::assertSame(404, $kernel->handle(new Request('GET', '/only-' . ($index - 1)))->status());
                }

                // View shares are Application-owned; a preceding fixture must
                // not contribute values to the next independently booted app.
                if ($index % 2 === 0) {
                    $application->views()->share('phase26Marker', 'app-' . $index);
                    self::assertSame('app-' . $index,
                        $application->views()->contextFor('probe')['phase26Marker']);
                } else {
                    self::assertArrayNotHasKey('phase26Marker',
                        $application->views()->contextFor('probe'));
                }
            } finally {
                $fixture->cleanup();
                unset($kernel, $routes, $diagnostics, $application, $fixture);
                gc_collect_cycles();
            }
            if ($index === 3) {
                $warmBytes = memory_get_usage(true);
            }
        }

        // The allowance is deliberately broad across PHP allocators and OSes;
        // it catches retained Applications, not ordinary allocator rounding.
        self::assertNotNull($warmBytes);
        self::assertLessThanOrEqual($warmBytes + 32 * 1024 * 1024, memory_get_usage(true));
    }

    public function testWorkerReleasesLocaleAcrossSuccessRetryAndFailure(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for the persistent Queue lifecycle probe.');
        }
        $fixture = TestApplication::temporary(['translation' => [
            'default' => 'en', 'fallback' => 'en', 'supported' => ['en', 'fr'],
        ]]);
        try {
            $application = $fixture->application();
            $database = $application->container()->make(DatabaseManager::class);
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            (new \CreateQueueTables())->up($database->connection()->pdo(), $database->schema());
            $queue = new QueueManager(['default' => 'database', 'connections' => [
                'database' => ['driver' => 'database', 'database_connection' => 'testing',
                    'retry_after' => 2],
            ]], static fn (?string $name) => $database->connection($name));
            for ($id = 0; $id < 32; ++$id) {
                $queue->dispatch(new StabilizationLifecycleJob($id));
            }

            $warmBytes = memory_get_usage(true);
            $worker = new Worker($queue, container: $application->container());
            self::assertSame(34, $worker->run(tries: 2, backoff: 0,
                maxJobs: 40, stopWhenEmpty: true));
            self::assertSame('empty', $worker->exitReason());
            self::assertCount(34, StabilizationLifecycleJob::$observed);
            // The ready Queue can run a later job before a released retry.
            // Assert attempt semantics without requiring reservation order.
            $attemptsFor = static fn (int $id): array => array_column(array_filter(
                StabilizationLifecycleJob::$observed,
                static fn (array $entry): bool => $entry['id'] === $id
            ), 'attempt');
            self::assertSame([1, 2], $attemptsFor(30));
            self::assertSame([1, 2], $attemptsFor(31));
            self::assertSame('en', Translation::locale());
            foreach (StabilizationLifecycleJob::$observed as $attempt) {
                self::assertSame('en', $attempt['locale']);
            }
            self::assertSame(0, (int) $database->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
            self::assertSame(1, (int) $database->raw('SELECT COUNT(*) FROM `queue_failed_jobs`')->fetchColumn());
            self::assertLessThanOrEqual($warmBytes + 32 * 1024 * 1024, memory_get_usage(true));
        } finally {
            $fixture->cleanup();
        }
    }

    public function testRepeatedSchedulerTicksDoNotDuplicateDefinitions(): void
    {
        $scheduler = new Scheduler(new Container(), [
            'store' => 'array', 'timezone' => 'UTC', 'prefix' => 'phase26',
        ]);
        $runs = 0;
        $scheduler->call(static function () use (&$runs): void { ++$runs; })
            ->name('phase26-probe')->everyMinute();
        $start = new DateTimeImmutable('2026-10-02 12:00:00', new DateTimeZone('UTC'));
        for ($minute = 0; $minute < 48; ++$minute) {
            $tick = $start->modify('+' . $minute . ' minutes');
            self::assertSame(1, $scheduler->run($tick)->executed);
            self::assertCount(1, $scheduler->definitions());
        }
        self::assertSame(48, $runs);
        self::assertSame(1, $scheduler->run($tick)->skipped);
    }

    public function testPersistentMcpServerKeepsCapabilitiesStableAcrossCalls(): void
    {
        if (!McpServerAdapter::available()) {
            self::markTestSkipped('The optional MCP SDK is required for the STDIO lifecycle probe.');
        }
        $fixture = TestApplication::temporary(['app' => [
            'key' => 'PHASE26_MCP_SECRET_DO_NOT_LEAK',
        ]]);
        try {
            $manager = new AgentManager($fixture->application());
            for ($index = 0; $index < 5; ++$index) {
                self::assertSame(AgentContext::VERSION,
                    $manager->resource('squehub://framework')['framework']['version']);
            }
            $warmBytes = memory_get_usage(true);
            for ($index = 0; $index < 96; ++$index) {
                self::assertSame(AgentContext::STDIO_PROTOCOL,
                    $manager->resource('squehub://framework')['protocol']['supported_stdio']);
            }
            self::assertLessThanOrEqual($warmBytes + 32 * 1024 * 1024, memory_get_usage(true));

            $frames = [json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [],
                    'clientInfo' => ['name' => 'SqueHub lifecycle test', 'version' => '1.0.0']],
            ], JSON_THROW_ON_ERROR), json_encode([
                'jsonrpc' => '2.0', 'method' => 'notifications/initialized',
            ], JSON_THROW_ON_ERROR)];
            for ($id = 2; $id <= 33; ++$id) {
                $frames[] = json_encode([
                    'jsonrpc' => '2.0', 'id' => $id, 'method' => 'resources/read',
                    'params' => ['uri' => 'squehub://framework'],
                ], JSON_THROW_ON_ERROR);
            }
            $input = fopen('php://temp', 'w+');
            $outputPath = $fixture->path('phase26-mcp-output.jsonl');
            $output = fopen($outputPath, 'w+');
            self::assertIsResource($input);
            self::assertIsResource($output);
            fwrite($input, implode("\n", $frames) . "\n");
            rewind($input);
            // The SDK closes its supplied streams after EOF. Inspect the
            // fixture-owned output file instead of retaining a closed handle.
            self::assertSame(0, (new McpServerAdapter())->run($manager, $input, $output));
            self::assertLessThanOrEqual($warmBytes + 32 * 1024 * 1024,
                memory_get_usage(true));
            $lines = preg_split('/\R/', trim((string) file_get_contents($outputPath)));
            self::assertCount(33, $lines);
            foreach ($lines as $line) {
                $response = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertArrayHasKey('result', $response);
                self::assertArrayNotHasKey('error', $response);
            }
            self::assertStringNotContainsString('PHASE26_MCP_SECRET_DO_NOT_LEAK',
                (string) file_get_contents($outputPath));
        } finally {
            $fixture->cleanup();
        }
    }
}
