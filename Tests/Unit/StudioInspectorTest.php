<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Broadcasting\BroadcastManager;
use App\Container\Container;
use App\Events\EventDispatcher;
use App\Profiler\ArrayProfilerStore;
use App\Profiler\ProfileRecord;
use App\Profiler\ProfilerManager;
use App\Profiler\ProfilerSettings;
use App\Studio\StudioInspector;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/** Studio projects fixed safe metadata rather than returning subsystem objects. */
final class StudioInspectorTest extends TestCase
{
    public function testSnapshotIsBoundedAndDoesNotExposeCredentialsOrProbeDependencies(): void
    {
        $project = TestApplication::temporary();
        try {
            $app = $project->application();
            $app->config()->set('database.connections.testing.password', 'STUDIO_SECRET_DO_NOT_LEAK');
            $app->config()->set('storage.drives.private', [
                'driver' => 'local', 'root' => 'STUDIO_SECRET_DO_NOT_LEAK',
            ]);
            $app->config()->set('storage.drives.cloud', [
                'driver' => 's3', 'access_key' => 'STUDIO_SECRET_DO_NOT_LEAK',
            ]);
            $app->config()->set('mail.default', 'resend');
            $app->config()->set('mail.transports.resend', [
                'driver' => 'resend', 'api_key' => 'STUDIO_SECRET_DO_NOT_LEAK',
            ]);
            $app->config()->set('translation.supported',
                ['en', 'fr', 'STUDIO_SECRET_DO_NOT_LEAK']);
            $inspector = new StudioInspector($app);
            $snapshot = $inspector->snapshot();
            self::assertSame(['overview', 'routes', 'activation', 'profiles', 'health',
                'queue', 'scheduler', 'events', 'broadcast', 'infrastructure', 'contract',
                'observations'],
                array_keys($snapshot));
            self::assertSame('observed', $snapshot['routes']['state']);
            self::assertSame([], $snapshot['routes']['items']);
            self::assertSame('observed', $snapshot['contract']['state']);
            self::assertSame('not_probed', $snapshot['health']['dependencies']);
            self::assertSame('configured', $snapshot['queue']['state']);
            self::assertNull($snapshot['queue']['counts']);
            self::assertSame('array', $snapshot['infrastructure']['storage']['drives'][0]['driver']);
            self::assertSame('s3', $snapshot['infrastructure']['storage']['drives'][2]['driver']);
            self::assertSame('resend', $snapshot['infrastructure']['mail']['driver']);
            self::assertTrue($snapshot['infrastructure']['mail']['provider_token_configured']);
            self::assertFalse($snapshot['infrastructure']['mail']['live_delivery_checked']);
            self::assertFalse($snapshot['infrastructure']['cache']['reachability_checked']);
            self::assertSame('sqlite', $snapshot['infrastructure']['database']['connections'][0]['driver']);
            self::assertSame(['en', 'fr'], $snapshot['infrastructure']['translation']['supported']);
            self::assertSame(extension_loaded('intl'),
                $snapshot['infrastructure']['translation']['intl_available']);
            self::assertSame(0, $snapshot['overview']['package_count']);
            self::assertSame(0, $snapshot['overview']['kit_count']);
            self::assertSame('unavailable', $snapshot['observations']['state']);
            self::assertStringNotContainsString('STUDIO_SECRET_DO_NOT_LEAK',
                json_encode($snapshot, JSON_THROW_ON_ERROR));
        } finally {
            $project->cleanup();
        }
    }

    public function testEventAndBroadcastInspectionNeverCallsRegisteredCallbacks(): void
    {
        $invoked = false;
        $events = new EventDispatcher(new Container());
        $events->listen(\stdClass::class, static function () use (&$invoked): void {
            $invoked = true;
        });
        $summary = $events->subscriptionsSummary(1);
        self::assertSame([['event' => \stdClass::class, 'listener' => 'callable',
            'priority' => 0, 'queued' => false]], $summary['items']);
        self::assertFalse($summary['truncated']);
        $events->listen(\stdClass::class, static function (): void {});
        self::assertTrue($events->subscriptionsSummary(1)['truncated']);

        $broadcast = new BroadcastManager(new Container(), ['enabled' => true,
            'driver' => 'null']);
        $broadcast->privateChannel('private.accounts.{id}',
            static function () use (&$invoked): bool { $invoked = true; return true; });
        $inspection = $broadcast->inspection();
        self::assertTrue($inspection['enabled']);
        self::assertSame('null', $inspection['driver']);
        self::assertSame(['private.accounts.{id}'], $inspection['patterns']);
        self::assertFalse($invoked);
    }

    public function testRequestedLimitsCannotExpandTheInspectionSurface(): void
    {
        $project = TestApplication::temporary();
        try {
            $inspector = new StudioInspector($project->application());
            self::assertSame([], $inspector->routes(PHP_INT_MAX)['items']);
            self::assertSame([], $inspector->events(PHP_INT_MAX)['items']);
            self::assertSame([], $inspector->profiles(PHP_INT_MAX)['items']);
            self::assertNull($inspector->profile('..\\private'));
        } finally {
            $project->cleanup();
        }
    }

    public function testProfilesRemainBoundedAndApplicationOwned(): void
    {
        $first = TestApplication::temporary(['app' => ['env' => 'development']]);
        $second = TestApplication::temporary(['app' => ['env' => 'development']]);
        try {
            $app = $first->application();
            $app->config()->set('profiler.enabled', true);
            $settings = new ProfilerSettings(['enabled' => true, 'store' => 'array'],
                'development');
            $store = new ArrayProfilerStore($settings);
            foreach (['a', 'b'] as $index => $digit) {
                $metrics = $index === 0
                    ? ['db.query.ok' => ['count' => 2, 'time_ms' => 3.5]]
                    : ['cache.read.ok' => ['count' => 1, 'time_ms' => 0.5]];
                $store->save(new ProfileRecord(str_repeat($digit, 32), 1000 + $index,
                    'http.request', str_repeat('c', 32), null, 1.0,
                    [], $metrics, 0, 0), 1001);
            }
            $app->container()->instance(ProfilerManager::class,
                new ProfilerManager($settings, $store, static fn (): int => 1001));
            $inspector = new StudioInspector($app);
            $list = $inspector->profiles(1);
            self::assertSame('observed', $list['state']);
            self::assertCount(1, $list['items']);
            self::assertTrue($list['truncated']);
            self::assertSame(str_repeat('b', 32), $list['items'][0]['id']);
            self::assertSame(str_repeat('a', 32),
                $inspector->profile(str_repeat('a', 32))['id']);
            self::assertSame('observed', $inspector->observations()['state']);
            self::assertSame(2, $inspector->observations()['sample_size']);
            self::assertSame(['count' => 2, 'time_ms' => 3.5],
                $inspector->observations()['categories']['database']);
            self::assertSame(['count' => 1, 'time_ms' => 0.5],
                $inspector->observations()['categories']['cache']);
            self::assertSame('unavailable', (new StudioInspector($second->application()))
                ->profiles()['state']);
        } finally {
            $first->cleanup();
            $second->cleanup();
        }
    }
}
