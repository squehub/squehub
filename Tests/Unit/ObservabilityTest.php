<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Foundation\Application;
use App\Cache\CacheDriver;
use App\Cache\CacheEntry;
use App\Cache\CacheResolution;
use App\Cache\CacheStore;
use App\Config\Repository;
use App\Database\SystemModelClock;
use App\Diagnostics\Diagnostics;
use App\Observability\ArrayObservationExporter;
use App\Observability\ObservationConsumer;
use App\Observability\ObservationExporter;
use App\Observability\ObservationReport;
use App\Observability\ObservabilityManager;
use App\Observability\ObservabilityServiceProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises bounded local observations without a collector or network service. */
final class ObservabilityTest extends TestCase
{
    public function testDisabledRecorderDoesNotAllocateOrChangeCallbackExecution(): void
    {
        $recorder = new ObservabilityManager();
        $calls = 0;
        self::assertNull($recorder->begin('http.request'));
        self::assertSame('result', $recorder->span('http.controller', function () use (&$calls): string {
            ++$calls;
            return 'result';
        }));
        $recorder->record('db.query', 2.0, attributes: ['connection' => 'main']);
        self::assertSame(1, $calls);
        self::assertSame([], $recorder->metrics());
    }

    public function testCacheBackendFailureIsObservedWithoutReplacingTheExceptionOrKeyPrivacy(): void
    {
        $failure = new RuntimeException('backend failed with SQUEHUB_SECRET');
        $driver = new class($failure) implements CacheDriver {
            public function __construct(private RuntimeException $failure) {}
            public function fetch(string $hash, callable $now): ?CacheEntry { throw $this->failure; }
            public function put(string $hash, CacheEntry $entry): void {}
            public function remove(string $hash, callable $now): bool { return false; }
            public function take(string $hash, callable $now): ?CacheEntry { return null; }
            public function remember(string $hash, callable $now, callable $producer): CacheResolution
            { throw $this->failure; }
            public function clear(): void {}
        };
        $exporter = new ArrayObservationExporter();
        $recorder = new ObservabilityManager(['enabled' => true, 'sampling' => 'all'], $exporter);
        $diagnostics = new Diagnostics(new Repository([]));
        $diagnostics->setObservability($recorder);
        $cache = new CacheStore($driver, 'test', new SystemModelClock(), $diagnostics);
        $root = $recorder->begin('http.request');
        try {
            $cache->read('SQUEHUB_SECRET_KEY');
            self::fail('Expected backend failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        } finally {
            $root?->finish();
        }
        self::assertSame(1, $exporter->reports()[0]->metrics['cache.read.error']['count']);
        self::assertStringNotContainsString('SQUEHUB_SECRET',
            json_encode($exporter->reports()[0]->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testNestedSpansCarryOnlyAllowedBoundedMetadata(): void
    {
        $exporter = new ArrayObservationExporter();
        $recorder = new ObservabilityManager(['enabled' => true, 'sampling' => 'all'], $exporter);
        $root = $recorder->begin('http.request', ['method' => 'GET', 'password' => 'SQUEHUB_SECRET'],
            str_repeat('a', 32));
        $recorder->span('http.middleware', function () use ($recorder): void {
            $recorder->record('db.query', 1.25, attributes: [
                'connection' => 'main', 'operation' => 'select',
                'sql' => 'SELECT SQUEHUB_SECRET', 'token' => 'SQUEHUB_SECRET',
            ]);
        }, ['middleware' => 'ExampleMiddleware']);
        $root?->finish(['route_pattern' => '/users/{user}', 'status' => 200]);
        $reports = $exporter->reports();
        self::assertCount(1, $reports);
        $report = $reports[0];
        self::assertSame(str_repeat('a', 32), $report->correlationId);
        self::assertNotSame($report->traceId, $report->correlationId);
        self::assertCount(3, $report->spans);
        self::assertSame('http.request', $report->spans[0]->operation);
        self::assertSame('/users/{user}', $report->spans[0]->attributes['route_pattern']);
        self::assertSame($report->spans[0]->id, $report->spans[1]->parentId);
        self::assertSame($report->spans[1]->id, $report->spans[2]->parentId);
        self::assertSame(1, $report->metrics['db.query.ok']['count']);
        self::assertStringNotContainsString('SQUEHUB_SECRET', json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testLocalConsumerBypassesTraceSamplingWhileBoundsStillApply(): void
    {
        $exporter = new ArrayObservationExporter();
        $consumer = new class implements ObservationConsumer {
            /** @var list<ObservationReport> */
            public array $reports = [];
            public function accept(ObservationReport $report): void { $this->reports[] = $report; }
        };
        $recorder = new ObservabilityManager(['enabled' => true, 'sampling' => 'ratio',
            'ratio' => 0.0, 'max_spans' => 8, 'max_metrics' => 2], $exporter);
        $recorder->subscribe($consumer);
        $root = $recorder->begin('queue.job');
        for ($index = 0; $index < 20; ++$index) {
            $recorder->record('db.query', 0.5);
        }
        $recorder->record('cache.read', 0.5);
        $recorder->record('cache.write', 0.5);
        $root?->finish();
        self::assertCount(1, $consumer->reports);
        self::assertFalse($consumer->reports[0]->sampled);
        self::assertCount(8, $consumer->reports[0]->spans);
        self::assertGreaterThan(0, $consumer->reports[0]->droppedSpans);
        self::assertSame([], $exporter->reports());
        self::assertCount(1, $exporter->metricBatches());
        self::assertSame(20, $recorder->metrics()['db.query.ok']['count']);
        self::assertGreaterThan(0, $recorder->status()['dropped_metrics']);
    }

    public function testExporterAndConsumerFailuresNeverReplaceApplicationResults(): void
    {
        $exporter = new class implements ObservationExporter {
            public function exportTrace(ObservationReport $report): void
            { throw new RuntimeException('SQUEHUB_SECRET'); }
            public function exportMetrics(array $metrics): void
            { throw new RuntimeException('SQUEHUB_SECRET'); }
        };
        $consumer = new class implements ObservationConsumer {
            public function accept(ObservationReport $report): void
            { throw new RuntimeException('SQUEHUB_SECRET'); }
        };
        $recorder = new ObservabilityManager(['enabled' => true, 'sampling' => 'all'], $exporter);
        $recorder->subscribe($consumer);
        self::assertSame(5, $recorder->span('http.request', static fn (): int => 5));
        self::assertSame(2, $recorder->status()['export_failures']);
        self::assertSame(1, $recorder->status()['consumer_failures']);
        self::assertStringNotContainsString('SQUEHUB_SECRET',
            json_encode($recorder->status(), JSON_THROW_ON_ERROR));
        try {
            $recorder->span('http.request', static function (): never {
                throw new RuntimeException('application result');
            });
            self::fail('Expected original application exception.');
        } catch (RuntimeException $failure) {
            self::assertSame('application result', $failure->getMessage());
        }
    }

    public function testCompletedScopeIsDetachedBeforeReentrantConsumerWork(): void
    {
        $exporter = new ArrayObservationExporter();
        $recorder = new ObservabilityManager(['enabled' => true, 'sampling' => 'all'], $exporter);
        $recorder->subscribe(new class($recorder) implements ObservationConsumer {
            public function __construct(private ObservabilityManager $recorder) {}
            public function accept(ObservationReport $report): void
            {
                $this->recorder->record('storage.read', 1.0);
                $this->recorder->span('profiler.export', static fn (): null => null);
            }
        });
        $recorder->span('http.request', static fn (): null => null);
        $reports = $exporter->reports();
        self::assertCount(1, $reports);
        self::assertSame(['http.request.ok'], array_keys($reports[0]->metrics));
        self::assertSame(1, $recorder->metrics()['storage.read.ok']['count']);
        self::assertSame(1, $recorder->metrics()['profiler.export.ok']['count']);
    }

    public function testTwoApplicationsOwnSeparateRecorders(): void
    {
        $firstProject = new TemporaryProject();
        $secondProject = new TemporaryProject();
        try {
            $firstProject->write('Config/Observability.php',
                '<?php return ["enabled" => true, "sampling" => "all", "exporter" => "array"];');
            $secondProject->write('Config/Observability.php',
                '<?php return ["enabled" => true, "sampling" => "all", "exporter" => "array"];');
            $first = new Application($firstProject->path());
            $second = new Application($secondProject->path());
            $first->register(ObservabilityServiceProvider::class);
            $second->register(ObservabilityServiceProvider::class);
            $first->bootstrap();
            $second->bootstrap();
            $left = $first->container()->make(ObservabilityManager::class);
            $right = $second->container()->make(ObservabilityManager::class);
            self::assertNotSame($left, $right);
            $left->span('http.request', static fn (): null => null);
            self::assertArrayHasKey('http.request.ok', $left->metrics());
            self::assertSame([], $right->metrics());
        } finally {
            $firstProject->remove();
            $secondProject->remove();
        }
    }
}
