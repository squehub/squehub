<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\ModelClock;
use App\Diagnostics\Diagnostics;
use App\Foundation\Application;
use App\Http\Request;
use App\Plugins;
use App\Queue\Drivers\DatabaseQueueDriver;
use App\Queue\Queue;
use App\Queue\QueueCodec;
use App\Queue\QueueException;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\QueueServiceProvider;
use App\Queue\Worker;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Mutable UTC time makes delay and stale-reservation tests deterministic. */
final class QueueTestClock implements ModelClock
{
    private DateTimeImmutable $time;

    public function __construct()
    {
        $this->time = new DateTimeImmutable('2026-09-24 12:00:00', new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable { return $this->time; }
    public function advance(int $seconds): void { $this->time = $this->time->modify('+' . $seconds . ' seconds'); }
}

/** Application job uses the Plugins alias without changing canonical identity. */
final class QueueProbeJob implements Plugins\QueueJob
{
    public static array $handled = [];

    public function __construct(private array $data)
    {
    }

    public function handle(): void
    {
        self::$handled[] = $this->data;
        if (($this->data['fail'] ?? false) === true) {
            throw new \RuntimeException('secret-in-job-payload');
        }
    }

    public function toQueuePayload(): array { return $this->data; }
    public static function fromQueuePayload(array $payload): static { return new static($payload); }
}

/** Reconstruction failures must not expose their exception text. */
final class QueueBrokenJob implements QueueJob
{
    public function handle(): void {}
    public function toQueuePayload(): array { return ['secret' => 'secret-in-job-payload']; }
    public static function fromQueuePayload(array $payload): static
    {
        throw new \RuntimeException((string) ($payload['secret'] ?? 'missing'));
    }
}

/** Real SQL coverage for Queue dispatch, reservation, retries, and failures. */
final class QueueFoundationTest extends TestCase
{
    private TemporaryProject $project;
    private QueueTestClock $clock;
    private DatabaseManager $databases;
    private QueueManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Queue integration.');
        }
        $this->project = new TemporaryProject();
        $this->clock = new QueueTestClock();
        $this->databases = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => $this->project->path('queue.sqlite'),
            ]],
        ]]));
        $connection = $this->databases->connection();
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        (new \CreateQueueTables())->up($connection->pdo(), $connection->schema());
        $this->manager = new QueueManager($this->settings(),
            fn (?string $name) => $this->databases->connection($name), $this->clock);
        QueueProbeJob::$handled = [];
    }

    protected function tearDown(): void
    {
        Queue::setResolver(null);
        if (isset($this->databases)) $this->databases->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    private function settings(): array
    {
        return ['default' => 'sync', 'connections' => [
            'sync' => ['driver' => 'sync'],
            'database' => ['driver' => 'database', 'database_connection' => 'test',
                'retry_after' => 30],
        ]];
    }

    private function driver(): DatabaseQueueDriver
    {
        $driver = $this->manager->driver('database');
        self::assertInstanceOf(DatabaseQueueDriver::class, $driver);
        return $driver;
    }

    public function testSyncGatewayHelperAndDelayExecuteImmediately(): void
    {
        Queue::setResolver(fn () => $this->manager);
        $job = new QueueProbeJob(['value' => 42]);
        self::assertSame($this->manager, queue());
        self::assertSame($this->manager, Plugins\Queue::manager());
        Plugins\Queue::dispatch($job, delay: 60);
        queue()->dispatch(new QueueProbeJob(['value' => 43]));
        self::assertSame([['value' => 42], ['value' => 43]], QueueProbeJob::$handled);
        self::assertSame(0, (int) $this->databases->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
    }

    public function testPayloadIsVersionedJsonWithExplicitReconstruction(): void
    {
        $json = QueueCodec::encode(new QueueProbeJob(['nested' => ['ok' => true, 'n' => null], 'f' => 1.5]));
        self::assertSame(1, json_decode($json, true)['version']);
        self::assertInstanceOf(QueueProbeJob::class, QueueCodec::decode($json));
        foreach (['not-json', '{}', '{"version":2,"job":"Example","data":{}}',
            '{"version":1,"job":"Missing\\\\Job","data":{}}',
            '{"version":1,"job":"stdClass","data":{}}'] as $invalid) {
            try {
                QueueCodec::decode($invalid);
                self::fail('Invalid payload accepted.');
            } catch (QueueException $exception) {
                self::assertStringNotContainsString('secret-in-job-payload', $exception->getMessage());
            }
        }
        foreach ([new \stdClass(), static fn () => null, INF, fopen('php://memory', 'r')] as $unsupported) {
            try {
                QueueCodec::encode(new QueueProbeJob(['bad' => $unsupported]));
                self::fail('Unsupported payload accepted.');
            } catch (QueueException) {
                self::assertTrue(true);
            } finally {
                if (is_resource($unsupported)) fclose($unsupported);
            }
        }
        foreach ([
            static fn () => QueueCodec::encode(new QueueProbeJob(['data' => str_repeat('x', 60000)])),
            static fn () => QueueCodec::decode(str_repeat('x', 60001)),
        ] as $oversized) {
            try {
                $oversized();
                self::fail('Oversized queue payload accepted.');
            } catch (QueueException $exception) {
                self::assertStringContainsString('size', $exception->getMessage());
            }
        }
    }

    public function testDelayedNamedJobUsesAtomicReservationAndAcknowledge(): void
    {
        $this->manager->dispatch(new QueueProbeJob(['id' => 7]), queue: 'reports', delay: 10,
            connection: 'database');
        self::assertNull($this->driver()->reserve('default'));
        self::assertNull($this->driver()->reserve('reports'));
        $this->clock->advance(10);
        $reserved = $this->driver()->reserve('reports');
        self::assertNotNull($reserved);
        self::assertSame(1, $reserved->attempts);
        self::assertNull($this->driver()->reserve('reports'));
        self::assertSame(['id' => 7], QueueCodec::decode($reserved->payload)->toQueuePayload());
        $this->driver()->acknowledge($reserved);
        self::assertSame(0, (int) $this->databases->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
    }

    public function testWorkerRetriesThenRecordsSafeFailure(): void
    {
        $this->manager->dispatch(new QueueProbeJob(['fail' => true, 'secret' => 'secret-in-job-payload']),
            connection: 'database');
        $worker = new Worker($this->manager);
        self::assertSame(1, $worker->run(connection: 'database', tries: 2, backoff: 5, once: true));
        self::assertSame(1, (int) $this->databases->raw('SELECT `attempts` FROM `queue_jobs`')->fetchColumn());
        self::assertSame(0, $worker->run(connection: 'database', once: true));
        $this->clock->advance(5);
        self::assertSame(1, $worker->run(connection: 'database', tries: 2, once: true));
        self::assertSame(0, (int) $this->databases->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
        $failure = $this->databases->raw('SELECT * FROM `queue_failed_jobs`')->fetch();
        self::assertSame(2, (int) $failure['attempts']);
        self::assertSame(QueueProbeJob::class, $failure['job_class']);
        self::assertStringNotContainsString('secret-in-job-payload', json_encode($failure));
        self::assertArrayNotHasKey('payload', $failure);
    }

    public function testCorruptJobFailsAndLaterJobStillRuns(): void
    {
        $this->databases->raw('INSERT INTO `queue_jobs` (`queue`,`payload`,`attempts`,`available_at`,`created_at`)'
            . ' VALUES (?,?,0,?,?)', ['default', '{bad', '2026-09-24 12:00:00', '2026-09-24 12:00:00']);
        $this->manager->dispatch(new QueueProbeJob(['good' => true]), connection: 'database');
        $worker = new Worker($this->manager);
        self::assertTrue($worker->workOnce(connection: 'database'));
        self::assertTrue($worker->workOnce(connection: 'database'));
        self::assertSame([['good' => true]], QueueProbeJob::$handled);
        self::assertSame(1, (int) $this->databases->raw('SELECT COUNT(*) FROM `queue_failed_jobs`')->fetchColumn());
        self::assertSame('unknown', $this->databases->raw('SELECT `job_class` FROM `queue_failed_jobs`')->fetchColumn());
    }

    public function testStaleReservationIsRecoveredAndPreviousTokenCannotAcknowledge(): void
    {
        $this->manager->dispatch(new QueueProbeJob(['id' => 1]), connection: 'database');
        $first = $this->driver()->reserve('default');
        self::assertNotNull($first);
        $other = new DatabaseQueueDriver($this->databases->connection(), $this->clock,
            retryAfter: 30);
        self::assertNull($other->reserve('default'));
        $this->clock->advance(30);
        $second = $other->reserve('default');
        self::assertNotNull($second);
        self::assertSame(2, $second->attempts);
        self::assertNotSame($first->token, $second->token);
        $this->expectException(QueueException::class);
        $this->driver()->acknowledge($first);
    }

    public function testInvalidNamesConnectionsAndSyncWorkerFailClearly(): void
    {
        foreach (['', '../private', 'bad/name', str_repeat('x', 129)] as $name) {
            try {
                $this->manager->dispatch(new QueueProbeJob([]), $name);
                self::fail('Invalid queue accepted.');
            } catch (QueueException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(QueueException::class);
        (new Worker($this->manager))->workOnce();
    }

    public function testUnknownConnectionDriverAndUnsupportedPayloadFailClearly(): void
    {
        try {
            $this->manager->driver('missing');
            self::fail('Missing Queue connection accepted.');
        } catch (QueueException $exception) {
            self::assertStringContainsString('not configured', $exception->getMessage());
        }
        $manager = new QueueManager(['default' => 'broken', 'connections' => [
            'broken' => ['driver' => 'imaginary'],
        ]]);
        $this->expectException(QueueException::class);
        $manager->driver();
    }

    public function testReconstructionFailureBecomesPrivateFailedRecord(): void
    {
        $this->manager->dispatch(new QueueBrokenJob(), connection: 'database');
        self::assertTrue((new Worker($this->manager))->workOnce(connection: 'database'));
        $failure = $this->databases->raw('SELECT * FROM `queue_failed_jobs`')->fetch();
        self::assertSame('unknown', $failure['job_class']);
        self::assertStringNotContainsString('secret-in-job-payload', json_encode($failure));
        self::assertSame(0, (int) $this->databases->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
    }

    public function testFailedRecordInsertRollsBackActiveJobRemoval(): void
    {
        $this->manager->dispatch(new QueueProbeJob(['fail' => true]), connection: 'database');
        $this->databases->schema()->drop('queue_failed_jobs');
        $failed = false;
        try {
            (new Worker($this->manager))->workOnce(connection: 'database', tries: 1);
        } catch (\Throwable) {
            $failed = true;
        }
        self::assertTrue($failed, 'Missing failed-job table did not surface.');
        self::assertSame(1, (int) $this->databases->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
    }

    public function testQueueDiagnosticsResetAndRetainNoJobData(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request());
        $manager = new QueueManager($this->settings(), null, $this->clock, $diagnostics);
        $manager->dispatch(new QueueProbeJob(['secret' => 'secret-in-job-payload']));
        $snapshot = $diagnostics->snapshot()['queue'];
        self::assertSame(1, $snapshot['dispatched']);
        self::assertGreaterThanOrEqual(0.0, $snapshot['time_ms']);
        self::assertStringNotContainsString('secret-in-job-payload', json_encode($snapshot));
        $diagnostics->begin(new Request());
        self::assertSame(0, $diagnostics->snapshot()['queue']['dispatched']);
    }

    public function testProviderBootIsDatabaseIndependentAndManagersAreIsolated(): void
    {
        $first = new QueueManager($this->settings());
        $second = new QueueManager($this->settings());
        self::assertNotSame($first->driver(), $second->driver());
        $project = new TemporaryProject();
        try {
            $project->write('Config/Queue.php', '<?php return ["default"=>"sync","connections"=>["sync"=>["driver"=>"sync"]]];');
            $app = new Application($project->path());
            $app->register(QueueServiceProvider::class);
            $app->bootstrap();
            self::assertInstanceOf(QueueManager::class, $app->container()->make(QueueManager::class));
            Plugins\Queue::dispatch(new QueueProbeJob(['isolated' => true]));
            self::assertSame([['isolated' => true]], QueueProbeJob::$handled);
        } finally {
            $project->remove();
        }
    }
}
