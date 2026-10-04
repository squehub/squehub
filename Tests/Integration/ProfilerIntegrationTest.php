<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Cache\CacheServiceProvider;
use App\Cache\CacheStore;
use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Events\EventDispatcher;
use App\Events\EventServiceProvider;
use App\Foundation\Application;
use App\Frontend\ServePackageAsset;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpServiceProvider as OutgoingHttpServiceProvider;
use App\Observability\ObservabilityManager;
use App\Profiler\ArrayProfilerStore;
use App\Profiler\ProfileRecord;
use App\Profiler\ProfilerManager;
use App\Profiler\ProfilerServiceProvider;
use App\Profiler\ProfilerSettings;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
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

/** Route middleware enters the existing observation timeline. */
final class ProfilerPassMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}

/** A global request guard that passes control to the route pipeline. */
final class ProfilerGlobalPassMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}

/** A global guard that prevents route matching and controller execution. */
final class ProfilerGlobalStopMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return new Response('blocked', 403);
    }
}

/** Worker fixture has no persisted secrets or transport dependency. */
final class ProfilerJob implements QueueJob
{
    public static int $handled = 0;
    public function handle(): void { ++self::$handled; }
    public function toQueuePayload(): array { return []; }
    public static function fromQueuePayload(array $payload): static { return new static(); }
}

/** The same bounded profile schema covers requests, Queue jobs, and Scheduler ticks. */
final class ProfilerIntegrationTest extends TestCase
{
    public function testGlobalAndRouteMiddlewareKeepExecutionOrderInOneTimeline(): void
    {
        $project = new TemporaryProject();
        try {
            $app = self::app($project, 'development', 'array', true);
            $kernel = $app->container()->make(Kernel::class);
            $kernel->addGlobalMiddleware(new ProfilerGlobalPassMiddleware());
            $app->container()->make(RouteRegistry::class)
                ->add('GET', '/ordered', static fn (): Response => new Response('ok'))
                ->through(ProfilerPassMiddleware::class);
            self::assertSame(200, $kernel->handle(new Request('GET', '/ordered'))->status());
            $profile = $app->container()->make(ProfilerManager::class)->latest()[0];
            $global = $route = $controller = null;
            foreach ($profile->events as $index => $event) {
                if ($event['operation'] === 'http.middleware'
                    && ($event['attributes']['middleware'] ?? null) === ProfilerGlobalPassMiddleware::class) {
                    $global = $index;
                    self::assertSame('global', $event['attributes']['mode']);
                }
                if ($event['operation'] === 'http.middleware'
                    && ($event['attributes']['middleware'] ?? null) === ProfilerPassMiddleware::class) {
                    $route = $index;
                }
                if ($event['operation'] === 'http.controller') $controller = $index;
            }
            self::assertNotNull($global);
            self::assertNotNull($route);
            self::assertNotNull($controller);
            self::assertLessThan($route, $global);
            self::assertLessThan($controller, $route);
        } finally {
            $project->remove();
        }
    }

    public function testGlobalShortCircuitProducesNoRouteOrControllerTimeline(): void
    {
        $project = new TemporaryProject();
        try {
            $app = self::app($project, 'development', 'array', true);
            $kernel = $app->container()->make(Kernel::class);
            $kernel->addGlobalMiddleware(new ProfilerGlobalStopMiddleware());
            $calls = 0;
            $app->container()->make(RouteRegistry::class)->add('GET', '/blocked',
                static function () use (&$calls): Response {
                    ++$calls;
                    return new Response('unexpected');
                })->through(ProfilerPassMiddleware::class);
            self::assertSame(403, $kernel->handle(new Request('GET', '/blocked'))->status());
            self::assertSame(0, $calls);
            $profile = $app->container()->make(ProfilerManager::class)->latest()[0];
            self::assertSame(403, $profile->events[0]['attributes']['status']);
            // The framework Package-asset guard runs before this test's
            // short-circuit guard; neither enters the route pipeline.
            self::assertSame(['http.request', 'http.middleware', 'http.middleware'],
                array_column($profile->events, 'operation'));
            self::assertSame(ServePackageAsset::class,
                $profile->events[1]['attributes']['middleware']);
            self::assertSame(ProfilerGlobalStopMiddleware::class,
                $profile->events[2]['attributes']['middleware']);
        } finally {
            $project->remove();
        }
    }

    public function testHttpProfileUsesExistingInstrumentationAndOmitsSecrets(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $project = new TemporaryProject();
        try {
            $app = self::app($project, 'development', 'array', true);
            $container = $app->container();
            $observer = $container->make(ObservabilityManager::class);
            $profiler = $container->make(ProfilerManager::class);
            self::assertTrue($observer->enabled());
            self::assertFalse($observer->status()['export_enabled']);
            $database = $container->make(DatabaseManager::class);
            $database->raw('CREATE TABLE profiler_items (id INTEGER PRIMARY KEY, name TEXT)');
            $database->raw('INSERT INTO profiler_items VALUES (12345, ?)', ['SQUEHUB_PROFILE_SECRET']);
            $cache = $container->make(CacheStore::class);
            $events = $container->make(EventDispatcher::class);
            $events->listen(\stdClass::class, static function (\stdClass $event): void {});
            $outbound = $container->make(HttpClient::class);
            $outbound->fake(['GET https://api.example.test/ping' => new HttpResponse(200, 'ok')]);
            $routes = $container->make(RouteRegistry::class);
            $routes->add('GET', '/profile/{id}', function (Request $request) use (
                $database, $cache, $events, $outbound): Response {
                $database->table('profiler_items')->filter('id', (int) $request->route('id'))->first();
                $cache->read('SQUEHUB_PROFILE_SECRET_KEY');
                $cache->store('SQUEHUB_PROFILE_SECRET_KEY', 'value');
                $events->emit(new \stdClass());
                $outbound->pending()->withToken('SQUEHUB_PROFILE_SECRET_TOKEN')
                    ->get('https://api.example.test/ping');
                return new Response('ok');
            })->named('profile.show')->through(ProfilerPassMiddleware::class);
            $response = $container->make(Kernel::class)
                ->handle(new Request('GET', '/profile/12345'));
            self::assertSame(200, $response->status());
            $profiles = $profiler->latest();
            self::assertCount(1, $profiles);
            $profile = $profiles[0];
            self::assertSame('http.request', $profile->operation);
            self::assertSame($response->header('X-Request-ID'), $profile->correlationId);
            self::assertSame('GET', $profile->events[0]['attributes']['method']);
            self::assertSame('/profile/{id}', $profile->events[0]['attributes']['route_pattern']);
            self::assertSame(200, $profile->events[0]['attributes']['status']);
            $operations = array_column($profile->events, 'operation');
            foreach (['http.middleware', 'http.controller', 'db.query', 'cache.read',
                'cache.write', 'event.emit', 'http_client.request'] as $operation) {
                self::assertContains($operation, $operations);
            }
            self::assertSame(1, $profile->metrics['db.query.ok']['count']);
            $serialized = json_encode($profile->toArray(), JSON_THROW_ON_ERROR);
            foreach (['SQUEHUB_PROFILE_SECRET', '12345', 'profiler_items'] as $secret) {
                self::assertStringNotContainsString($secret, $serialized);
            }
        } finally {
            $project->remove();
        }
    }

    public function testProductionDebugDoesNotActivateOrCreateFiles(): void
    {
        $project = new TemporaryProject();
        try {
            $app = self::app($project, 'production', 'file', true);
            $observer = $app->container()->make(ObservabilityManager::class);
            self::assertFalse($observer->enabled());
            $profiler = $app->container()->make(ProfilerManager::class);
            self::assertFalse($profiler->enabled());
            self::assertSame([], $profiler->latest());
            self::assertDirectoryDoesNotExist($project->path('Storage/Logs/Profiler'));
        } finally {
            $project->remove();
        }
    }

    public function testDevelopmentFileStoreIsLazyUntilACompletedRequest(): void
    {
        $project = new TemporaryProject();
        try {
            $app = self::app($project, 'development', 'file', true);
            $root = $project->path('Storage/Logs/Profiler');
            self::assertDirectoryDoesNotExist($root);
            $app->container()->make(RouteRegistry::class)
                ->add('GET', '/profile', static fn (): Response => new Response('ok'));
            $response = $app->container()->make(Kernel::class)->handle(new Request('GET', '/profile'));
            self::assertSame(200, $response->status());
            self::assertDirectoryExists($root);
            self::assertCount(1, $app->container()->make(ProfilerManager::class)->latest());
        } finally {
            $project->remove();
        }
    }

    public function testTwoApplicationsDoNotShareArrayProfiles(): void
    {
        $first = new TemporaryProject();
        $second = new TemporaryProject();
        try {
            $a = self::app($first, 'development', 'array', true);
            $b = self::app($second, 'development', 'array', true);
            $a->container()->make(RouteRegistry::class)
                ->add('GET', '/first', static fn (): Response => new Response('first'));
            $a->container()->make(Kernel::class)->handle(new Request('GET', '/first'));
            self::assertCount(1, $a->container()->make(ProfilerManager::class)->latest());
            self::assertSame([], $b->container()->make(ProfilerManager::class)->latest());
            self::assertNotSame($a->container()->make(ProfilerManager::class),
                $b->container()->make(ProfilerManager::class));
        } finally {
            $first->remove();
            $second->remove();
        }
    }

    public function testSchedulerAndWorkerProduceTheSameProfileShapeWithoutCrossJobState(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $project = new TemporaryProject();
        $settings = new ProfilerSettings(['enabled' => true, 'store' => 'array'], 'development');
        $profiler = new ProfilerManager($settings, new ArrayProfilerStore($settings),
            static fn (): int => 1000);
        $observer = new ObservabilityManager();
        $observer->activateLocalConsumer($profiler);
        $diagnostics = new Diagnostics(new Repository([]));
        $diagnostics->setObservability($observer);
        $database = new DatabaseManager(new Repository(['database' => ['default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite',
                'database' => $project->path('profiler-queue.sqlite')]]]]));
        try {
            $scheduler = new Scheduler(new \App\Container\Container(), ['store' => 'array'],
                null, null, null, $diagnostics);
            $scheduler->call(static function (): void {})->name('profile-task')->everyMinute();
            $scheduler->run(new DateTimeImmutable('2026-09-30 10:00:00 UTC'));
            $connection = $database->connection();
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_25_add_failed_queue_payload.php';
            (new \CreateQueueTables())->up($connection->pdo(), $connection->schema());
            (new \AddFailedQueuePayload())->up($connection->pdo(), $connection->schema());
            $queue = new QueueManager(['default' => 'database', 'connections' => [
                'database' => ['driver' => 'database', 'database_connection' => 'test',
                    'retry_after' => 2],
            ]], fn (?string $name) => $database->connection($name), null, $diagnostics);
            $queue->dispatch(new ProfilerJob(), 'first-12345');
            $queue->dispatch(new ProfilerJob(), 'second-67890');
            $worker = new Worker($queue, $diagnostics);
            ProfilerJob::$handled = 0;
            self::assertTrue($worker->workOnce('first-12345'));
            self::assertTrue($worker->workOnce('second-67890'));
            self::assertSame(2, ProfilerJob::$handled);
            $profiles = $profiler->latest();
            self::assertCount(3, $profiles);
            $operations = array_count_values(array_map(
                static fn (ProfileRecord $record): string => $record->operation,
                $profiles));
            ksort($operations);
            self::assertSame(['queue.job' => 2, 'scheduler.tick' => 1], $operations);
            $jobs = array_values(array_filter($profiles,
                static fn (ProfileRecord $record): bool => $record->operation === 'queue.job'));
            self::assertNotSame($jobs[0]->traceId, $jobs[1]->traceId);
            self::assertNotSame($jobs[0]->correlationId, $jobs[1]->correlationId);
            self::assertSame(array_keys($profiles[0]->toArray()),
                array_keys($profiles[2]->toArray()));
            foreach ($profiles as $profile) {
                self::assertGreaterThanOrEqual(0.0, $profile->durationMs);
                self::assertNotEmpty($profile->events);
                self::assertSame($profile->operation, $profile->events[0]['operation']);
            }
        } finally {
            $database->disconnect();
            $project->remove();
        }
    }

    private static function app(TemporaryProject $project, string $environment,
        string $store, bool $enabled): Application
    {
        $project->write('Config/App.php', '<?php return ["env"=>"' . $environment
            . '","debug"=>true];');
        $project->write('Config/Profiler.php', '<?php return ["enabled"=>'
            . ($enabled ? 'true' : 'false') . ',"store"=>"' . $store . '"];');
        $project->write('Config/Observability.php',
            '<?php return ["enabled"=>false,"sampling"=>"off","exporter"=>"none"];');
        $project->write('Config/Cache.php', '<?php return ["driver"=>"array"];');
        $project->write('Config/Database.php',
            '<?php return ["default"=>"sqlite","connections"=>["sqlite"=>'
            . '["driver"=>"sqlite","database"=>":memory:"]]];');
        $app = new Application($project->path());
        foreach ([DiagnosticsServiceProvider::class, \App\Observability\ObservabilityServiceProvider::class,
            ProfilerServiceProvider::class, EventServiceProvider::class,
            CacheServiceProvider::class, DatabaseServiceProvider::class,
            OutgoingHttpServiceProvider::class, HttpServiceProvider::class,
            RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        return $app;
    }
}
