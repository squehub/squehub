<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Cache\CacheServiceProvider;
use App\Database\DatabaseServiceProvider;
use App\Foundation\Application;
use App\Foundation\ServiceProvider;
use App\Health\HealthCheck;
use App\Health\HealthException;
use App\Health\HealthManager;
use App\Health\HealthReport;
use App\Health\HealthResult;
use App\Health\HealthServiceProvider;
use App\Queue\QueueServiceProvider;
use App\RateLimit\RateLimitServiceProvider;
use App\Redis\RedisManager;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Session\SessionServiceProvider;
use App\Storage\StorageServiceProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real Application wiring with isolated configuration and an in-memory SQLite connection. */
final class HealthTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        \App\Health\Health::setResolver(null);
        \App\Routing\Route::setResolver(null);
        foreach ($this->projects as $project) $project->remove();
    }

    private function app(bool $endpoints = false, string $cacheDriver = 'array', bool $unavailableRedis = false): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $project->write('.env', "APP_ENV=testing\n");
        $project->write('composer.json', '{"require":{"php":"^8.2"}}');
        $project->write('Config/App.php', '<?php return ["env" => "testing", "debug" => false];');
        $project->write('Config/Health.php', '<?php return ["endpoints_enabled" => ' . ($endpoints ? 'true' : 'false')
            . ', "middleware" => [], "require_database" => true, "require_storage" => true];');
        $project->write('Config/Database.php', '<?php return ["default" => "sqlite", "connections" => ["sqlite" => ["driver" => "sqlite", "database" => ":memory:"]]];');
        $project->write('Config/Cache.php', '<?php return ["driver" => "' . $cacheDriver . '"];');
        $project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $project->write('Config/RateLimit.php', '<?php return ["store" => "array", "prefix" => "health-test"];');
        $project->write('Config/Queue.php', '<?php return ["default" => "sync", "connections" => ["sync" => ["driver" => "sync"]]];');
        $project->write('Config/Storage.php', '<?php return ["default" => "memory", "drives" => ["memory" => ["driver" => "array"]]];');
        $app = new Application($project->path());
        if ($unavailableRedis) {
            $app->container()->instance(RedisManager::class, new RedisManager([
                'default' => 'main', 'client' => 'auto',
                'connections' => ['main' => ['host' => '127.0.0.1']],
            ], null, null, static fn (string $client): bool => false));
        }
        foreach ([DatabaseServiceProvider::class, CacheServiceProvider::class, SessionServiceProvider::class,
            RateLimitServiceProvider::class, QueueServiceProvider::class, StorageServiceProvider::class,
            RoutingServiceProvider::class, HealthServiceProvider::class] as $provider) $app->register($provider);
        $app->bootstrap();
        return $app;
    }

    public function testLivenessIsDependencyFreeAndReadinessUsesSelectedServices(): void
    {
        $app = $this->app();
        $manager = $app->container()->make(HealthManager::class);
        self::assertSame($manager, $app->container()->make(HealthManager::class));
        self::assertSame($manager, \App\Plugins\Health::manager());
        self::assertTrue(class_exists(\App\Plugins\HealthManager::class));
        self::assertTrue(interface_exists(\App\Plugins\HealthCheck::class));
        self::assertTrue(class_exists(\App\Plugins\HealthResult::class));
        self::assertTrue(class_exists(\App\Plugins\HealthReport::class));
        self::assertTrue($manager->live()->healthy());
        self::assertCount(1, $manager->live()->results());
        self::assertTrue($manager->ready()->healthy(), json_encode($manager->ready()->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('pass', $manager->ready()->toArray()['status']);
        $selected = $manager->infrastructure();
        self::assertSame('array', $selected['cache']['selected']);
        self::assertSame('array', $selected['session']['selected']);
        self::assertSame('array', $selected['rate_limit']['selected']);
        self::assertSame('sync', $selected['queue']['selected']);
        self::assertSame('array', $selected['storage']['selected']);
        self::assertTrue($app->container()->make(\App\Database\DatabaseManager::class)->connection()->isConnected());
    }

    public function testReadinessRejectsMissingOrUnchangedEnvironmentFile(): void
    {
        $app = $this->app();
        $manager = $app->container()->make(HealthManager::class);
        $environmentFile = $app->basePath('.env');
        $templateFile = $app->basePath('.example.env');

        unlink($environmentFile);
        self::assertContains('env_missing', array_column($manager->ready()->toArray()['results'], 'code'));
        self::assertFalse($manager->ready()->healthy());

        file_put_contents($templateFile, "APP_ENV=testing\n");
        file_put_contents($environmentFile, "# copied template\r\nAPP_ENV=testing\r\n");
        self::assertContains('env_unchanged', array_column($manager->doctor()->toArray()['results'], 'code'));

        file_put_contents($environmentFile, "APP_ENV=testing\nAPP_NAME=Configured\n");
        self::assertNotContains('env_unchanged', array_column($manager->ready()->toArray()['results'], 'code'));
    }

    public function testMissingDatabaseAndProductionDebugFailReadinessButNotLiveness(): void
    {
        $app = $this->app();
        $app->config()->set('database.default', 'missing');
        $app->config()->set('app.env', 'production');
        $app->config()->set('app.debug', true);
        $manager = $app->container()->make(HealthManager::class);
        self::assertTrue($manager->live()->healthy());
        $ready = $manager->ready();
        self::assertFalse($ready->healthy());
        self::assertSame(2, $ready->counts()['fail']);
        self::assertContains('debug_enabled_in_production', array_column($ready->toArray()['results'], 'code'));
        self::assertNotContains('missing', array_column($ready->toArray()['results'], 'summary'));
    }

    public function testPackageChecksAreLazyIsolatedAndRedacted(): void
    {
        $app = $this->app();
        $app->config()->set('crypt.keys.primary', 'HEALTH_TEST_SECRET');
        $app->config()->set('redis.connections.main.url', 'redis://user:HEALTH_TEST_SECRET@127.0.0.1:1');
        $manager = $app->container()->make(HealthManager::class);
        $runs = 0;
        $manager->register('payments', static function () use (&$runs): HealthResult {
            ++$runs;
            return HealthResult::warning('payments', 'package',
                'Credential HEALTH_TEST_SECRET and redis://user:HEALTH_TEST_SECRET@127.0.0.1:1 unavailable.',
                'configuration_missing');
        }, true);
        $manager->register('broken', static function (): HealthResult {
            throw new RuntimeException('HEALTH_TEST_SECRET from provider');
        });
        self::assertSame(0, $runs);
        $ready = $manager->ready();
        self::assertTrue($ready->healthy());
        self::assertTrue($ready->hasWarnings());
        self::assertSame(1, $runs);
        $doctor = $manager->doctor();
        self::assertSame(2, $runs);
        self::assertContains('check_failed', array_column($doctor->toArray()['results'], 'code'));
        self::assertStringNotContainsString('HEALTH_TEST_SECRET', json_encode($doctor->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame('pass', $doctor->results()[0]->status());
    }

    public function testClassChecksUseContainerInjectionAndRejectDuplicates(): void
    {
        $app = $this->app();
        $manager = $app->container()->make(HealthManager::class);
        $manager->register('container_probe', ContainerHealthCheck::class);
        $doctor = $manager->doctor();
        self::assertContains('container_probe', array_column($doctor->toArray()['results'], 'name'));
        $results = $doctor->results();
        self::assertSame('pass', $results[array_key_last($results)]->status());
        $this->expectException(HealthException::class);
        $manager->register('container_probe', ContainerHealthCheck::class);
    }

    public function testOptionalRoutesAreMinimalAndDoNotLeakReportDetails(): void
    {
        $disabled = $this->app();
        self::assertFalse($disabled->container()->make(RouteRegistry::class)->hasName('health.live'));
        $enabled = $this->app(true);
        $routes = $enabled->container()->make(RouteRegistry::class);
        self::assertSame('/health/live', $routes->url('health.live'));
        self::assertSame('/health/ready', $routes->url('health.ready'));
        $responses = [];
        foreach ($routes->all() as $route) $responses[$route->uri()] = ($route->action())();
        self::assertSame(200, $responses['/health/live']->status());
        self::assertSame('{"status":"ok"}', $responses['/health/live']->content());
        self::assertSame(200, $responses['/health/ready']->status());
        self::assertSame('{"status":"ready"}', $responses['/health/ready']->content());
        self::assertSame('no-store, max-age=0', $responses['/health/ready']->header('Cache-Control'));
        self::assertSame('application/json; charset=UTF-8', $responses['/health/ready']->header('Content-Type'));
        $this->expectException(\LogicException::class);
        $routes->addLegacy('GET', '/health/live', static fn (): string => 'override', null, []);
    }

    public function testUnavailableReadinessEndpointRemainsMinimal(): void
    {
        $app = $this->app(true);
        $app->config()->set('database.default', 'missing');
        $routes = $app->container()->make(RouteRegistry::class);
        $failed = ($routes->all()[1]->action())();
        self::assertSame(503, $failed->status());
        self::assertSame('{"status":"unavailable"}', $failed->content());
        self::assertStringNotContainsString('missing', $failed->content());
    }

    public function testExplicitRedisFailsAndAutoFallsBackWithoutCreatingCacheFiles(): void
    {
        $required = $this->app(false, 'redis', true);
        $requiredReport = $required->container()->make(HealthManager::class)->ready();
        self::assertFalse($requiredReport->healthy());
        self::assertContains('redis_unavailable', array_column($requiredReport->toArray()['results'], 'code'));
        $optional = $this->app(false, 'auto', true);
        $optionalManager = $optional->container()->make(HealthManager::class);
        self::assertTrue($optionalManager->ready()->healthy());
        self::assertSame('file', $optionalManager->infrastructure()['cache']['selected']);
        self::assertSame('client_unavailable', $optionalManager->infrastructure()['cache']['reason']);
        self::assertDirectoryDoesNotExist($optional->basePath('Storage/Cache'));
    }

    public function testRequiredQueueAndSchedulerInspectTablesWithoutDispatching(): void
    {
        $app = $this->app();
        $config = $app->config();
        $config->set('health.require_queue', true);
        $config->set('health.require_scheduler', true);
        $config->set('scheduler.store', 'database');
        $config->set('queue.default', 'jobs.primary');
        $config->set('queue.connections', ['jobs.primary' => [
            'driver' => 'database', 'table' => 'queue_jobs', 'failed_table' => 'queue_failed_jobs',
        ]]);
        $database = $app->container()->make(\App\Database\DatabaseManager::class);
        $app->container()->instance(\App\Queue\QueueManager::class, new \App\Queue\QueueManager([
            'default' => 'jobs.primary', 'connections' => ['jobs.primary' => [
                'driver' => 'database', 'table' => 'queue_jobs', 'failed_table' => 'queue_failed_jobs',
            ]],
        ], static fn (?string $name): \App\Database\Connection => $database->connection($name)));
        $manager = $app->container()->make(HealthManager::class);
        self::assertSame('database', $manager->infrastructure()['queue']['configured']);
        self::assertSame('database', $manager->infrastructure()['queue']['selected']);
        $missing = $manager->ready();
        self::assertSame(2, $missing->counts()['fail']);
        self::assertContains('missing_table', array_column($missing->toArray()['results'], 'code'));
        $pdo = $database->connection()->pdo();
        foreach (['queue_jobs', 'queue_failed_jobs', 'schedule_runs', 'schedule_locks'] as $table) {
            $pdo->exec('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, payload TEXT NULL)');
        }
        self::assertTrue($manager->ready()->healthy());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM queue_jobs')->fetchColumn());
    }

    public function testRequiredCryptAndUnavailableStorageFailClearly(): void
    {
        $app = $this->app();
        $app->config()->set('health.require_crypt', true);
        $app->config()->set('storage.default', 'missing');
        $ready = $app->container()->make(HealthManager::class)->ready();
        self::assertFalse($ready->healthy());
        self::assertContains('crypt_unavailable', array_column($ready->toArray()['results'], 'code'));
        self::assertContains('storage_invalid', array_column($ready->toArray()['results'], 'code'));
    }

    public function testOptionalProviderInspectionSeparatesConfigurationFromLiveProof(): void
    {
        $app = $this->app();
        $config = $app->config();
        $config->set('mail.default', 'resend');
        $config->set('mail.from.address', 'sender@example.test');
        $config->set('mail.transports.resend', [
            'driver' => 'resend', 'api_key' => 'PROVIDER_HEALTH_PRIVATE_TOKEN',
            'timeout' => 10,
        ]);
        $config->set('storage.default', 's3');
        $config->set('storage.drives.s3', [
            'driver' => 's3', 'bucket' => 'squehub-test-bucket',
            'region' => 'us-east-1', 'prefix' => 'health-test',
        ]);
        $manager = $app->container()->make(HealthManager::class);
        $results = [];
        foreach ($manager->doctor()->results() as $result) {
            $results[$result->name()] = $result;
        }
        self::assertSame('warning', $results['mail']->status());
        self::assertSame('not_probed', $results['mail']->code());
        self::assertSame('s3', $manager->infrastructure()['storage']['configured']);
        self::assertSame(class_exists('Aws\\S3\\S3Client') ? 'not_probed'
            : 'storage_dependency_missing', $results['storage']->code());
        self::assertStringNotContainsString('PROVIDER_HEALTH_PRIVATE_TOKEN',
            json_encode($manager->doctor()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testDoctorDoesNotStartSessionOrModifyApplicationRecords(): void
    {
        $app = $this->app();
        $pdo = $app->container()->make(\App\Database\DatabaseManager::class)->connection()->pdo();
        $pdo->exec('CREATE TABLE health_marker (id INTEGER PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO health_marker (value) VALUES ('keep')");
        $sessionBefore = session_status();
        $app->container()->make(HealthManager::class)->doctor();
        self::assertSame($sessionBefore, session_status());
        self::assertSame('keep', $pdo->query('SELECT value FROM health_marker')->fetchColumn());
        self::assertDirectoryDoesNotExist($app->basePath('Storage/RateLimits'));
        self::assertDirectoryDoesNotExist($app->basePath('Storage/Cache'));
    }

    public function testDoctorDetectsProductionTlsPolicyWithoutExposingSettings(): void
    {
        $app = $this->app();
        $app->config()->set('app.env', 'production');
        $app->config()->set('httpClient.verify_peer', false);
        $app->config()->set('mail', [
            'default' => 'smtp', 'from' => ['address' => 'sender@example.test'],
            'transports' => ['smtp' => ['driver' => 'smtp', 'host' => 'mail.example.test',
                'port' => 587, 'timeout' => 5, 'encryption' => 'tls',
                'verify_peer' => false, 'username' => null, 'password' => null]],
        ]);
        $report = $app->container()->make(HealthManager::class)->doctor();
        self::assertContains('tls_verification_disabled', array_column($report->toArray()['results'], 'code'));
        self::assertGreaterThanOrEqual(2, $report->counts()['fail']);
        self::assertStringNotContainsString('mail.example.test', json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testReportsAreFreshAndRegistriesAreApplicationIsolated(): void
    {
        $first = $this->app()->container()->make(HealthManager::class);
        $second = $this->app()->container()->make(HealthManager::class);
        $first->register('only_first', static fn (): HealthResult => HealthResult::pass('only_first', 'package'));
        self::assertNotSame($first, $second);
        self::assertContains('only_first', array_column($first->doctor()->toArray()['results'], 'name'));
        self::assertNotContains('only_first', array_column($second->doctor()->toArray()['results'], 'name'));
    }

    public function testEarlierPackageProviderCanRegisterDuringBoot(): void
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $project->write('Config/App.php', '<?php return ["env" => "testing", "debug" => false];');
        $project->write('Config/Health.php', '<?php return ["endpoints_enabled" => false];');
        $app = new Application($project->path());
        $app->register(PackageHealthProvider::class);
        $app->register(HealthServiceProvider::class);
        $app->bootstrap();
        $results = $app->container()->make(HealthManager::class)->doctor()->toArray()['results'];
        self::assertContains('package_probe', array_column($results, 'name'));
    }

    public function testResultAndReportContractsAreStable(): void
    {
        $report = new HealthReport('doctor', [HealthResult::pass('a', 'package'),
            HealthResult::warning('b', 'package', 'Warning.', 'warning'),
            HealthResult::fail('c', 'package', 'Failed.', 'failure'),
            HealthResult::skipped('d', 'package', 'Skipped.', 'optional')]);
        self::assertFalse($report->healthy());
        self::assertTrue($report->hasFailures());
        self::assertTrue($report->hasWarnings());
        self::assertSame(['pass' => 1, 'warning' => 1, 'fail' => 1, 'skipped' => 1], $report->counts());
        self::assertSame('fail', $report->toArray()['status']);
    }
}

/** A reusable package-style check whose dependency is injected by Container. */
final class ContainerHealthCheck implements HealthCheck
{
    public function __construct(private Application $app) {}
    public function check(): HealthResult
    {
        return $this->app->isBooted()
            ? HealthResult::pass('container_probe', 'package', 'Container can resolve the check.')
            : HealthResult::fail('container_probe', 'package', 'Application did not boot.', 'not_booted');
    }
}

/** Simulates an application/package provider booting before the Health provider. */
final class PackageHealthProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void
    {
        \App\Plugins\Health::manager()->register('package_probe',
            static fn (): HealthResult => HealthResult::pass('package_probe', 'package'));
    }
}
