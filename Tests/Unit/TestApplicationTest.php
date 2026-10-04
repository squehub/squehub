<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Auth\Auth;
use App\Authorization\Authorization;
use App\Cache\Cache;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Schema\Table;
use App\Diagnostics\Diagnostic;
use App\Http\Kernel;
use App\Http\Request;
use App\Logging\Log;
use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Routing\Route;
use App\Security\Csrf\Csrf;
use App\Session\Session;
use App\Storage\Storage;
use App\Testing\TestApplication;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

/** The test fixture uses real Application services without a real project .env. */
final class TestApplicationTest extends TestCase
{
    private ?TestApplication $fixture = null;

    protected function tearDown(): void
    {
        try {
            $this->fixture?->cleanup();
        } finally {
            Authorization::setResolver(null);
            Auth::setResolver(null);
            Cache::setResolver(null);
            Database::setResolver(null);
            Diagnostic::setResolver(null);
            Log::setResolver(null);
            Route::setResolver(null);
            Csrf::setResolver(null);
            Session::setResolver(null);
            Storage::setResolver(null);
        }
    }

    public function testDisposableProjectBootsRealServicesWithSafeDefaults(): void
    {
        $this->fixture = TestApplication::temporary();
        $root = $this->fixture->root();
        self::assertDirectoryExists($this->fixture->path('Config'));
        self::assertDirectoryExists($this->fixture->path('Project/Routes'));
        self::assertDirectoryExists($this->fixture->path('Project/Packages'));
        self::assertDirectoryExists($this->fixture->path('Storage'));
        self::assertFileDoesNotExist($root . '/.env');
        self::assertFalse($this->fixture->isBooted());

        $app = $this->fixture->application();
        self::assertTrue($this->fixture->isBooted());
        self::assertSame('testing', $app->environment());
        self::assertFalse($app->isDebug());
        self::assertSame($root, $app->basePath());
        self::assertSame('array', $app->config()->get('session.driver'));
        self::assertSame('array', $app->config()->get('cache.driver'));
        self::assertSame('array', $app->config()->get('storage.drives.memory.driver'));
        self::assertSame('array', $app->config()->get('logging.driver'));
        self::assertTrue($app->config()->get('csrf.enabled'));
        $database = $app->container()->make(DatabaseManager::class);
        self::assertSame('testing', $database->defaultName());
        self::assertFalse($database->connection()->isConnected());
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return;
        }
        $database->schema()->create('test_records', static function (Table $table): void {
            $table->id();
        });
        self::assertTrue($database->schema()->hasTable('test_records'));
        self::assertFileDoesNotExist($root . '/.env');
    }

    public function testPrebootConfigurationCanChangePolicyButCannotSwitchToLiveDatabase(): void
    {
        $this->fixture = TestApplication::temporary(['app' => ['name' => 'Fixture']]);
        $this->fixture->configure(['csrf' => ['enabled' => false]]);
        self::assertSame('Fixture', $this->fixture->application()->config()->get('app.name'));
        self::assertFalse($this->fixture->application()->config()->get('csrf.enabled'));
        try {
            $this->fixture->configure(['csrf' => ['enabled' => true]]);
            self::fail('Booted test application accepted a configuration change.');
        } catch (LogicException) {
            self::assertFalse($this->fixture->application()->config()->get('csrf.enabled'));
        }

        $separate = TestApplication::temporary();
        try {
            $this->expectException(InvalidArgumentException::class);
            $separate->configure(['database' => [
                'default' => 'live',
                'connections' => ['live' => ['driver' => 'mysql', 'database' => 'production']],
            ]]);
        } finally {
            $separate->cleanup();
        }
    }

    public function testExplicitEnvironmentOverrideKeepsEveryDatabaseDisposable(): void
    {
        $this->fixture = TestApplication::temporary(['app' => [
            'env' => 'production', 'debug' => false,
        ]]);
        $app = $this->fixture->application();
        self::assertSame('production', $app->environment());
        self::assertFalse($app->isDebug());
        self::assertSame(':memory:', $app->config()->get('database.connections.testing.database'));
        self::assertSame('array', $app->config()->get('session.driver'));
        self::assertFileDoesNotExist($this->fixture->root() . '/.env');
    }

    public function testTemporarySqliteAndLocalStorageRemainInsideOwnedRoot(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for disposable file database testing.');
        }
        $this->fixture = TestApplication::temporary();
        $databasePath = $this->fixture->path('Storage/testing.sqlite');
        $this->fixture->configure([
            'database' => ['connections' => ['testing' => ['database' => $databasePath]]],
            'storage' => ['default' => 'files', 'drives' => [
                'files' => ['driver' => 'local', 'root' => 'Storage/Files'],
            ]],
        ]);
        $app = $this->fixture->application();
        $app->container()->make(DatabaseManager::class)->schema()->create('records',
            static function (Table $table): void { $table->id(); });
        self::assertFileExists($databasePath);
        $app->container()->make(\App\Storage\StorageManager::class)->write('profile.txt', 'fixture');
        self::assertSame('fixture', file_get_contents($this->fixture->path('Storage/Files/profile.txt')));
    }

    public function testRouteLoaderUsesTemporaryProjectAndRunsOnlyOnce(): void
    {
        $this->fixture = TestApplication::temporary();
        $this->fixture->write('Project/Routes/Web.php', '<?php \\App\\Routing\\Route::path("/fixture")'
            . '->get(static fn (): string => "ready");');
        $this->fixture->loadRoutes();
        $this->fixture->loadRoutes();
        $response = $this->fixture->application()->container()->make(Kernel::class)
            ->handle(new Request('GET', '/fixture'));
        self::assertSame(200, $response->status());
        self::assertSame('ready', $response->content());
    }

    public function testEnabledPackageRoutesAreLoadedFromOwnedProject(): void
    {
        $this->fixture = TestApplication::temporary();
        $this->fixture->write('Project/Packages/Weather/Weather.php',
            '<?php namespace Packages\\Weather; final class Weather extends \\App\\Plugins\\ServiceProvider {}');
        $this->fixture->write('Project/Packages/Weather/Routes/Web.php',
            '<?php \\App\\Routing\\Route::path("/weather")'
            . '->get(static fn (): string => "forecast");');
        $planning = new PackageManager(new Application($this->fixture->root()));
        $planning->apply($planning->planEnable('Weather'));

        $this->fixture->loadRoutes();
        $response = $this->fixture->application()->container()->make(Kernel::class)
            ->handle(new Request('GET', '/weather'));
        self::assertSame(200, $response->status());
        self::assertSame('forecast', $response->content());
    }

    public function testPathsRejectTraversalDotenvAndNonExportableConfiguration(): void
    {
        $this->fixture = TestApplication::temporary();
        foreach (['../escape', '/absolute', 'Project//Routes', 'Project/../Config/App.php',
            'Project\\Routes\\Web.php', '.env', 'Config/Database.php'] as $path) {
            try {
                $this->fixture->write($path, 'unsafe');
                self::fail('Unsafe test application path was accepted.');
            } catch (InvalidArgumentException) {
                self::assertFileDoesNotExist($this->fixture->root() . '/.env');
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->fixture->configure(['app' => ['invalid' => new \stdClass()]]);
    }

    public function testCleanupIsOwnedIdempotentAndDoesNotDeleteAnotherProject(): void
    {
        $this->fixture = TestApplication::temporary();
        $other = TestApplication::temporary();
        $this->fixture->write('Storage/own.txt', 'one');
        $other->write('Storage/own.txt', 'two');
        $removed = $this->fixture->root();
        try {
            $this->fixture->cleanup();
            $this->fixture->cleanup();
            self::assertDirectoryDoesNotExist($removed);
            self::assertSame('two', file_get_contents($other->path('Storage/own.txt')));
        } finally {
            $other->cleanup();
        }
    }
}
