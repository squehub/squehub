<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Observability\ObservationReport;
use App\Observability\ObservationSpan;
use App\Observability\ArrayObservationExporter;
use App\Observability\ObservabilityManager;
use App\Profiler\ArrayProfilerStore;
use App\Profiler\FileProfilerStore;
use App\Profiler\ProfileRecord;
use App\Profiler\ProfilerException;
use App\Profiler\ProfilerManager;
use App\Profiler\ProfilerSettings;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Bounded profile values and stores use no HTTP, database, Redis, or exporter. */
final class ProfilerTest extends TestCase
{
    public function testOnlyExplicitDevelopmentOptInEnablesTheProfiler(): void
    {
        self::assertFalse((new ProfilerSettings([], 'development'))->enabled);
        self::assertTrue((new ProfilerSettings(['enabled' => true], 'development'))->enabled);
        self::assertFalse((new ProfilerSettings(['enabled' => true], 'production'))->enabled);
        self::assertFalse((new ProfilerSettings(['enabled' => true], 'testing'))->enabled);
        $this->expectException(ProfilerException::class);
        new ProfilerSettings(['max_events' => 0], 'development');
    }

    public function testLocalConsumerActivatesTheExistingRecorderWithoutExternalSampling(): void
    {
        $settings = self::settings(['store' => 'array']);
        $profiler = new ProfilerManager($settings, new ArrayProfilerStore($settings),
            static fn (): int => 1000);
        $observability = new ObservabilityManager();
        self::assertFalse($observability->enabled());
        $observability->activateLocalConsumer($profiler);
        self::assertTrue($observability->enabled());
        $scope = $observability->begin('http.request', ['method' => 'GET'], str_repeat('a', 32));
        self::assertNotNull($scope);
        $observability->record('db.query', 1.5, false,
            ['operation' => 'select', 'sql' => 'SQUEHUB_PROFILE_SECRET',
                'connection' => 'testing']);
        $scope->finish(['status' => 200]);
        $profiles = $profiler->latest();
        self::assertCount(1, $profiles);
        self::assertSame('http.request', $profiles[0]->operation);
        self::assertSame('GET', $profiles[0]->events[0]['attributes']['method']);
        self::assertSame('select', $profiles[0]->events[1]['attributes']['operation']);
        self::assertStringNotContainsString('SQUEHUB_PROFILE_SECRET',
            json_encode($profiles[0]->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame(str_repeat('a', 32), $profiles[0]->correlationId);
        self::assertNotSame($profiles[0]->id, $profiles[0]->correlationId);
    }

    public function testLocalActivationDoesNotEnableAnExporterConfiguredWhileObservabilityIsOff(): void
    {
        $settings = self::settings();
        $profiler = new ProfilerManager($settings, new ArrayProfilerStore($settings),
            static fn (): int => 1000);
        $observability = new ObservabilityManager([
            'enabled' => false, 'sampling' => 'all', 'exporter' => 'array',
        ]);
        $exporter = $observability->exporter();
        self::assertInstanceOf(ArrayObservationExporter::class, $exporter);
        $observability->activateLocalConsumer($profiler);
        $scope = $observability->begin('http.request');
        self::assertNotNull($scope);
        $scope->finish();
        self::assertCount(1, $profiler->latest());
        self::assertSame([], $exporter->reports());
        self::assertSame([], $exporter->metricBatches());
        self::assertFalse($observability->status()['export_enabled']);
    }

    public function testArrayStorePrunesCountAgeAndIsIsolated(): void
    {
        $settings = self::settings(['max_profiles' => 2, 'max_age_seconds' => 5]);
        $store = new ArrayProfilerStore($settings);
        $first = ProfileRecord::capture(self::report(), 100, $settings);
        $second = ProfileRecord::capture(self::report(), 101, $settings);
        $third = ProfileRecord::capture(self::report(), 102, $settings);
        $store->save($first, 100);
        $store->save($second, 101);
        $store->save($third, 102);
        self::assertSame([$third->id, $second->id], array_map(
            static fn (ProfileRecord $record): string => $record->id, $store->latest(2, 102)));
        self::assertNull($store->find($first->id, 102));
        self::assertCount(0, (new ArrayProfilerStore($settings))->latest(2, 102));
        self::assertSame([], $store->latest(2, 108));
    }

    public function testEventCountAndEncodedByteBudgetRetainTheRoot(): void
    {
        $settings = self::settings(['max_events' => 100, 'max_profile_bytes' => 8192,
            'max_bytes' => 8192]);
        $spans = [new ObservationSpan(str_repeat('a', 16), null,
            'http.request', 0.0, 10.0, false, ['method' => 'GET'])];
        $attributes = array_fill_keys([
            'route_pattern', 'route_name', 'controller', 'middleware',
            'connection', 'driver', 'operation', 'queue', 'event_type',
            'listener_type', 'channel_category', 'transport',
        ], str_repeat('x', 120));
        for ($i = 1; $i <= 80; ++$i) {
            $spans[] = new ObservationSpan(sprintf('%016x', $i), str_repeat('a', 16),
                'db.query', (float) $i, 0.1, false, $attributes);
        }
        $report = new ObservationReport(str_repeat('b', 32), null,
            'http.request', false, $spans, [], 0);
        $profile = ProfileRecord::capture($report, 1000, $settings);
        self::assertLessThanOrEqual(8192, $profile->bytes());
        self::assertLessThan(81, count($profile->events));
        self::assertSame('http.request', $profile->events[0]['operation']);
        self::assertSame(81 - count($profile->events), $profile->droppedEvents);
    }

    public function testFileStorePersistsZeroFractionsAndPrunesDeterministically(): void
    {
        $project = new TemporaryProject();
        $settings = self::settings(['max_profiles' => 2, 'max_age_seconds' => 10]);
        $root = $project->path('Storage/Logs/Profiler');
        try {
            $first = ProfileRecord::capture(self::report(0.0), 100, $settings);
            $second = ProfileRecord::capture(self::report(0.0), 101, $settings);
            $third = ProfileRecord::capture(self::report(0.0), 102, $settings);
            $store = new FileProfilerStore($root, $project->path(), $settings);
            self::assertDirectoryDoesNotExist($root);
            $store->save($first, 100);
            $store->save($second, 101);
            $store->save($third, 102);
            $reopened = new FileProfilerStore($root, $project->path(), $settings);
            self::assertNull($reopened->find($first->id, 102));
            self::assertSame([$third->id, $second->id], array_map(
                static fn (ProfileRecord $record): string => $record->id,
                $reopened->latest(2, 102)));
            $roundTrip = $reopened->find($second->id, 102);
            self::assertNotNull($roundTrip);
            self::assertSame(0.0, $roundTrip->durationMs);
            self::assertSame(0.0, $roundTrip->events[0]['duration_ms']);
            self::assertSame(0.0, $roundTrip->metrics['http.request.ok']['time_ms']);
            self::assertStringContainsString('"duration_ms":0.0',
                file_get_contents($root . '/' . $second->id . '.json'));
            self::assertSame([], $reopened->latest(2, 113));
        } finally {
            $project->remove();
        }
    }

    public function testTotalByteBudgetAndPersistentPrivacy(): void
    {
        $project = new TemporaryProject();
        $settings = self::settings(['max_profiles' => 100, 'max_events' => 20,
            'max_profile_bytes' => 8192, 'max_bytes' => 8192]);
        $root = $project->path('Storage/Logs/Profiler');
        try {
            $attributes = array_fill_keys([
                'route_pattern', 'route_name', 'controller', 'middleware',
                'connection', 'driver', 'operation', 'queue', 'event_type',
                'listener_type', 'channel_category', 'transport',
            ], str_repeat('x', 120));
            $spans = [new ObservationSpan(str_repeat('a', 16), null,
                'http.request', 0.0, 10.0, false, ['method' => 'GET'])];
            for ($i = 1; $i <= 4; ++$i) {
                $spans[] = new ObservationSpan(sprintf('%016x', $i),
                    str_repeat('a', 16), 'db.query', (float) $i, 0.5, false,
                    $attributes);
            }
            $report = new ObservationReport(str_repeat('b', 32), null,
                'http.request', false, $spans, [], 0);
            $first = ProfileRecord::capture($report, 100, $settings);
            $second = ProfileRecord::capture($report, 101, $settings);
            self::assertGreaterThan(4096, $first->bytes());
            $store = new FileProfilerStore($root, $project->path(), $settings);
            $store->save($first, 100);
            $store->save($second, 101);
            self::assertSame([$second->id], array_map(
                static fn (ProfileRecord $record): string => $record->id,
                $store->latest(10, 101)));

            $profiler = new ProfilerManager($settings, $store,
                static fn (): int => 102);
            $observer = new ObservabilityManager();
            $observer->activateLocalConsumer($profiler);
            $scope = $observer->begin('http.request');
            self::assertNotNull($scope);
            $observer->record('db.query', 1.0, false,
                ['sql' => 'SQUEHUB_PROFILE_SECRET', 'operation' => 'select']);
            $scope->finish();
            foreach (glob($root . '/*.json') ?: [] as $path) {
                self::assertStringNotContainsString('SQUEHUB_PROFILE_SECRET',
                    (string) file_get_contents($path));
            }
        } finally {
            $project->remove();
        }
    }

    public function testCorruptFileFailsClosedAndNeverBecomesAFreshProfile(): void
    {
        $project = new TemporaryProject();
        $settings = self::settings();
        $root = $project->path('Storage/Logs/Profiler');
        try {
            $store = new FileProfilerStore($root, $project->path(), $settings);
            $profile = ProfileRecord::capture(self::report(), 100, $settings);
            $store->save($profile, 100);
            file_put_contents($root . '/' . $profile->id . '.json', '{broken');
            $this->expectException(ProfilerException::class);
            $store->latest(1, 100);
        } finally {
            $project->remove();
        }
    }

    public function testOverfullTamperedDirectoryFailsWithoutMaterializingEveryRecord(): void
    {
        $project = new TemporaryProject();
        $settings = self::settings(['max_profiles' => 1]);
        $root = $project->path('Storage/Logs/Profiler');
        try {
            $store = new FileProfilerStore($root, $project->path(), $settings);
            $store->save(ProfileRecord::capture(self::report(), 100, $settings), 100);
            for ($i = 0; $i < 3; ++$i) {
                $record = ProfileRecord::capture(self::report(), 101 + $i, $settings);
                file_put_contents($root . '/' . $record->id . '.json',
                    json_encode($record->toArray(),
                        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            }
            $this->expectException(ProfilerException::class);
            $store->latest(1, 104);
        } finally {
            $project->remove();
        }
    }

    public function testFileRootCannotEscapeTheApplication(): void
    {
        $project = new TemporaryProject();
        $outside = new TemporaryProject();
        try {
            $this->expectException(ProfilerException::class);
            new FileProfilerStore($outside->path('Profiles'), $project->path(), self::settings());
        } finally {
            $outside->remove();
            $project->remove();
        }
    }

    public function testLinkedProfileRootIsRejectedWhenPlatformCanCreateLinks(): void
    {
        $project = new TemporaryProject();
        $outside = new TemporaryProject();
        $root = $project->path('Storage/Logs/Profiler');
        try {
            mkdir(dirname($root), 0777, true);
            if (!@symlink($outside->path(), $root)) {
                self::markTestSkipped('Directory symlinks are unavailable in this environment.');
            }
            $store = new FileProfilerStore($root, $project->path(), self::settings());
            $this->expectException(ProfilerException::class);
            $store->latest(1, 100);
        } finally {
            $outside->remove();
            $project->remove();
        }
    }

    /** @param array<string,mixed> $overrides */
    private static function settings(array $overrides = []): ProfilerSettings
    {
        return new ProfilerSettings(['enabled' => true, 'store' => 'array', ...$overrides],
            'development');
    }

    private static function report(float $duration = 1.0): ObservationReport
    {
        return new ObservationReport(str_repeat('b', 32), null, 'http.request', false,
            [new ObservationSpan(str_repeat('a', 16), null, 'http.request', 0.0,
                $duration, false, ['method' => 'GET'])],
            ['http.request.ok' => ['count' => 1, 'time_ms' => $duration]], 0);
    }
}
