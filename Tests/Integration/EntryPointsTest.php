<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Foundation\EnvironmentSetup;
use App\Http\HttpServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class EntryPointsTest extends TestCase
{
    public function testConsoleListsCanonicalGenerators(): void
    {
        $process = new Process([PHP_BINARY, 'squehub', 'list', '--raw'], dirname(__DIR__, 2));
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('start', $process->getOutput());
        self::assertStringContainsString('make:controller', $process->getOutput());
        self::assertStringContainsString('make:seeder', $process->getOutput());
        self::assertStringNotContainsString('make:dumper', $process->getOutput());
        self::assertStringNotContainsString('dump:run', $process->getOutput());
        self::assertStringNotContainsString('dump:rollback', $process->getOutput());
    }

    public function testGeneratorWithoutRequiredNameReportsClearFailure(): void
    {
        $process = new Process([PHP_BINARY, 'squehub', 'make:controller'], dirname(__DIR__, 2));
        $process->run();
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringNotContainsString('not implemented yet', $process->getOutput() . $process->getErrorOutput());
        self::assertStringNotContainsString('Fatal error', $process->getOutput() . $process->getErrorOutput());
    }

    public function testBothWebEntriesUseTheSameBridge(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertStringContainsString("'/Bootstrap/Web.php'", file_get_contents($root . '/index.php'));
        self::assertStringContainsString("'/Bootstrap/Web.php'", file_get_contents($root . '/public/index.php'));
        $bridge = file_get_contents($root . '/Bootstrap/Web.php');
        self::assertStringContainsString("'/App.php'", $bridge);
        self::assertStringNotContainsString("'Database.php'", $bridge);
        self::assertStringContainsString("'config.php'", $bridge);
        self::assertStringContainsString("'Bootstrap.php'", $bridge);
        self::assertStringContainsString('Request::capture()', $bridge);
        self::assertStringNotContainsString('new LegacyRouterDispatcher($router)', $bridge);
        self::assertStringContainsString("'/Bootstrap/Routes.php'", file_get_contents($root . '/Bootstrap.php'));
        self::assertStringContainsString('RoutingServiceProvider::class', file_get_contents($root . '/Bootstrap/App.php'));
        self::assertStringContainsString('make(Kernel::class)->handle($request)', $bridge);
        self::assertStringContainsString('->send($request->method() === \'HEAD\')', $bridge);
        self::assertStringNotContainsString('$router->dispatch(', $bridge);
        self::assertFileExists($root . '/Database.php');
        self::assertFileExists($root . '/Bootstrap.php');
        // Direct legacy callers may still require Database.php explicitly.
    }

    public function testSharedBootstrapReturnsBootedApplicationWithoutDatabase(): void
    {
        $root = dirname(__DIR__, 2);
        $code = '$app = require ' . var_export($root . '/Bootstrap/App.php', true) . '; '
            . 'echo get_class($app) . "|" . ($app->isBooted() ? "booted" : "not-booted") . "|" . $app->config()->get("app.name", "missing")'
            . ' . "|" . (int) $app->hasProvider(' . var_export(HttpServiceProvider::class, true) . ');';
        $process = new Process([PHP_BINARY, '-r', $code], $root);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringStartsWith(Application::class . '|booted|', $process->getOutput());
        self::assertStringEndsWith('|1', $process->getOutput());
    }

    public function testWebEntryRendersWelcomeOrSetupWithoutAvailableMysqlConnection(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process(
            [PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), 'public/index.php'],
            $root,
            ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'APP_DEBUG' => 'false']
        );
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        if (EnvironmentSetup::notice($root) !== null) {
            self::assertStringContainsString('SqueHub setup required', $process->getOutput());
        } else {
            self::assertStringContainsString('<title>Welcome to SqueHub</title>', $process->getOutput());
        }
        self::assertStringNotContainsString('Database connection failed', $process->getOutput());
    }

    public function testDirectLegacyDatabaseCallBootstrapsConfiguredConnection(): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . '$value = \\App\\Core\\Database::query("SELECT 1 AS value")->fetchColumn(); '
            . '$pdo = require ' . var_export($root . '/Database.php', true) . '; '
            . 'echo $value . "|" . (int) ($pdo === \\App\\Core\\Database::connect());';
        $process = new Process([PHP_BINARY, '-r', $code], $root,
            ['DB_CONNECTION' => 'sqlite', 'DB_SQLITE_DATABASE' => ':memory:']);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('1|1', $process->getOutput());
    }

    public function testDatabaseHelpersResolveBootstrappedApplicationWithoutConnecting(): void
    {
        $root = dirname(__DIR__, 2);
        $code = '$app = require ' . var_export($root . '/Bootstrap/App.php', true) . '; '
            . '$manager = $app->container()->make(\\App\\Database\\DatabaseManager::class); '
            . '$connection = $manager->connection(); '
            . 'echo (int) (database() === $manager) . "|" . get_class(db("users")) . "|" . (int) $connection->isConnected();';
        $process = new Process([PHP_BINARY, '-r', $code], $root);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('1|App\\Database\\QueryBuilder|0', $process->getOutput());
    }

    public function testDirectLegacyDatabaseIncludeInsideFunctionKeepsCurrentConnection(): void
    {
        $root = dirname(__DIR__, 2);
        $code = '$app = require ' . var_export($root . '/Bootstrap/App.php', true) . '; '
            . '$manager = $app->container()->make(\\App\\Database\\DatabaseManager::class); '
            . 'function legacyInclude(): \\PDO { return require ' . var_export($root . '/Database.php', true) . '; } '
            . '$pdo = legacyInclude(); '
            . 'echo (int) ($manager === \\App\\Database\\Database::manager()) . "|" '
            . '. (int) ($pdo === $manager->connection()->pdo());';
        $process = new Process([PHP_BINARY, '-r', $code], $root,
            ['DB_CONNECTION' => 'sqlite', 'DB_SQLITE_DATABASE' => ':memory:']);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('1|1', $process->getOutput());
    }

    public function testLegacyRouterBootstrapStillInitializesWithoutDatabase(): void
    {
        $root = dirname(__DIR__, 2);
        $project = new TemporaryProject();
        $sessionDirectory = $project->path('sessions');
        mkdir($sessionDirectory);
        try {
            $code = 'session_save_path(' . var_export($sessionDirectory, true) . '); '
                . '$squehubApp = require ' . var_export($root . '/Bootstrap/App.php', true) . '; '
                . '$config = require ' . var_export($root . '/config.php', true) . '; '
                . 'require ' . var_export($root . '/Bootstrap.php', true) . '; '
                . 'session_write_close(); echo "SQUEHUB_READY=" . (int) ($router instanceof Router); '
                . 'echo " CONFIG_COMPAT=" . (int) ($config["app"]["debug"] === (DEBUG_MODE ? "true" : "false") && count($config) === 2);';
            $process = new Process([PHP_BINARY, '-r', $code], $root);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString('SQUEHUB_READY=1', $process->getOutput());
            self::assertStringContainsString('CONFIG_COMPAT=1', $process->getOutput());
        } finally {
            $project->remove();
        }
    }

    public function testDirectLegacyDebugIncludeHonorsApplicationDebugSetting(): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'define("DEBUG_MODE", true); require '
            . var_export($root . '/Config/Debug.php', true) . '; '
            . 'echo "DISPLAY=" . ini_get("display_errors");';
        $process = new Process([PHP_BINARY, '-r', $code], $root, ['APP_DEBUG' => 'false']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('DISPLAY=0', $process->getOutput());
    }
}
