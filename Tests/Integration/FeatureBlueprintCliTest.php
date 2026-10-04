<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\DatabaseManager;
use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Packages\PackageStateStore;
use App\Plugins\TestCase;
use Composer\Autoload\ClassLoader;
use PDO;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises Feature Blueprints through the real CLI in a disposable project. */
final class FeatureBlueprintCliTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $this->project->write('.example.env', "APP_ENV=development\nAPP_KEY=\n");
        $this->project->write('.env', 'APP_ENV=testing' . "\n" . 'APP_KEY=base64:'
            . base64_encode(str_repeat('f', 32)) . "\n");
        $this->project->write('Config/App.php', "<?php return ['env' => 'testing', 'debug' => false];");
        $this->project->write('CliRunner.php', '<?php declare(strict_types=1); require '
            . var_export($root . '/vendor/autoload.php', true) . '; '
            . '$loader = new \\Composer\\Autoload\\ClassLoader(); '
            . '$loader->addPsr4("Project\\\\", __DIR__ . "/Project"); '
            . '$loader->register(true); '
            . '$squehubApp = new \\App\\Foundation\\Application(__DIR__); '
            . '\\App\\Foundation\\CliBootstrapMode::configure($squehubApp, $argv); '
            . '$squehubApp->register(\\App\\Routing\\RoutingServiceProvider::class); '
            . '$squehubApp->bootstrap(); require '
            . var_export($root . '/App/Clis/Clis.php', true) . ';');
    }

    protected function tearDown(): void
    {
        try {
            $this->project->remove();
        } finally {
            parent::tearDown();
        }
    }

    public function testFeatureCommandAppearsInListAndDocumentsSafeUsage(): void
    {
        $list = $this->runCommand('list', '--raw');
        self::assertSame(0, $list->getExitCode(), $list->getErrorOutput());
        self::assertStringContainsString('make:feature', $list->getOutput());

        $help = $this->runCommand('make:feature', '--help');
        self::assertSame(0, $help->getExitCode(), $help->getErrorOutput());
        foreach (['--package', '--table', '--route', '--preview', '--yes'] as $option) {
            self::assertStringContainsString($option, $help->getOutput());
        }
        self::assertStringContainsString('migration', strtolower($help->getOutput()));

        $generalHelp = $this->runCommand('help');
        self::assertSame(0, $generalHelp->getExitCode(), $generalHelp->getErrorOutput());
        self::assertStringContainsString('make:feature', $generalHelp->getOutput());
    }

    public function testPreviewDoesNotWriteOrExecuteAnEnabledPackage(): void
    {
        $marker = $this->project->path('PackageHookExecuted.txt');
        $this->project->write('Project/Packages/HookProbe/HookProbe.php',
            '<?php namespace Packages\\HookProbe; file_put_contents(' . var_export($marker, true)
            . ', "executed"); throw new \\RuntimeException("Package hook ran during preview"); '
            . 'final class HookProbe extends \\App\\Plugins\\ServiceProvider {}');
        (new PackageStateStore($this->project->path('Project/Packages')))->write([
            'HookProbe' => [
                'enabled' => true,
                'source_kind' => 'manual',
                'source' => 'manual',
                'files' => (object) [],
            ],
        ]);

        $before = $this->snapshot();
        $preview = $this->runCommand('make:feature', 'Post', '--preview');
        self::assertSame(0, $preview->getExitCode(), $preview->getOutput() . $preview->getErrorOutput());
        self::assertStringContainsString('SqueHub Feature Blueprint', $preview->getOutput());
        self::assertStringContainsString('Migration execution', $preview->getOutput());
        self::assertSame($before, $this->snapshot(), 'Preview must leave every project byte unchanged.');
        self::assertFileDoesNotExist($marker);
        self::assertFileDoesNotExist($this->project->path('Project/Models/Post.php'));
    }

    public function testNonInteractiveApplyRequiresYesAndDoesNotRunMigrationsOrSeeders(): void
    {
        $marker = $this->project->path('SeederExecuted.txt');
        $this->project->write('Database/Seeders/DangerSeeder.php',
            '<?php file_put_contents(' . var_export($marker, true) . ', "executed");');
        $before = $this->snapshot();

        $unconfirmed = $this->runCommand('make:feature', 'Post');
        self::assertNotSame(0, $unconfirmed->getExitCode());
        self::assertStringContainsString('Use --yes', $unconfirmed->getOutput());
        self::assertSame($before, $this->snapshot());

        $apply = $this->runCommand('make:feature', 'Post', '--yes');
        self::assertSame(0, $apply->getExitCode(), $apply->getOutput() . $apply->getErrorOutput());
        self::assertFileExists($this->project->path('Project/Models/Post.php'));
        self::assertFileExists($this->project->path('Project/Controllers/PostController.php'));
        self::assertFileExists($this->project->path('Project/Api/Resources/PostResource.php'));
        self::assertFileExists($this->project->path('Project/Validation/PostValidation.php'));
        self::assertFileExists($this->project->path('Project/Routes/Features/Post.php'));
        self::assertFileExists($this->project->path('Tests/Integration/PostFeatureTest.php'));
        self::assertFileDoesNotExist($marker);
        self::assertFileDoesNotExist($this->project->path('Storage/Database.sqlite'));
        self::assertDirectoryDoesNotExist($this->project->path('Project/Packages/Post'));

        $migrations = glob($this->project->path('Database/Migrations/*create_post_table.php')) ?: [];
        self::assertCount(1, $migrations);
        $this->assertPhpLints($migrations[0]);
        foreach (['Project/Models/Post.php', 'Project/Controllers/PostController.php',
            'Project/Api/Resources/PostResource.php', 'Project/Validation/PostValidation.php',
            'Project/Routes/Features/Post.php', 'Tests/Integration/PostFeatureTest.php'] as $relative) {
            $this->assertPhpLints($this->project->path($relative));
        }

        $route = (string) file_get_contents($this->project->path('Project/Routes/Features/Post.php'));
        self::assertStringContainsString("Route::path('/post')", $route);
        self::assertStringContainsString("->named('post.index')", $route);
        self::assertStringNotContainsString('Route::get(', $route);

        $after = $this->snapshot();
        $repeat = $this->runCommand('make:feature', 'Post', '--yes');
        self::assertNotSame(0, $repeat->getExitCode());
        self::assertSame($after, $this->snapshot(), 'A repeated feature must not overwrite its files.');
    }

    public function testInvalidNameAndPackageTargetsWriteNothing(): void
    {
        $before = $this->snapshot();
        foreach (['../Escape', 'Admin/../Escape', 'C:\\Escape', '9Invalid'] as $name) {
            $run = $this->runCommand('make:feature', $name, '--yes');
            self::assertNotSame(0, $run->getExitCode(), $name);
            self::assertStringNotContainsString('Fatal error', $run->getOutput() . $run->getErrorOutput());
            self::assertSame($before, $this->snapshot());
        }

        $missing = $this->runCommand('make:feature', 'Order', '--package=Commerce', '--yes');
        self::assertNotSame(0, $missing->getExitCode());
        self::assertSame($before, $this->snapshot());

        $this->project->write('Project/Packages/Commerce/Commerce.php',
            '<?php namespace Packages\\Commerce; final class Commerce extends \\App\\Plugins\\ServiceProvider {}');
        $withPackage = $this->snapshot();
        $wrongCase = $this->runCommand('make:feature', 'Order', '--package=commerce', '--yes');
        self::assertNotSame(0, $wrongCase->getExitCode());
        self::assertSame($withPackage, $this->snapshot());
    }

    public function testPackageFeatureKeepsPackageDisabledAndPlacesRunnableMigrationAndTestAtRoot(): void
    {
        $this->project->write('Project/Packages/Commerce/Commerce.php',
            '<?php namespace Packages\\Commerce; final class Commerce extends \\App\\Plugins\\ServiceProvider {}');
        $state = $this->project->path('Project/Activation.json');
        self::assertFileDoesNotExist($state);

        $before = $this->snapshot();
        $preview = $this->runCommand('make:feature', 'Order', '--package=Commerce', '--preview');
        self::assertSame(0, $preview->getExitCode(), $preview->getOutput() . $preview->getErrorOutput());
        self::assertStringContainsString('Owner: package Commerce', $preview->getOutput());
        self::assertSame($before, $this->snapshot());

        $apply = $this->runCommand('make:feature', 'Order', '--package=Commerce', '--yes');
        self::assertSame(0, $apply->getExitCode(), $apply->getOutput() . $apply->getErrorOutput());
        foreach (['Models/Order.php', 'Controllers/OrderController.php',
            'Api/Resources/OrderResource.php', 'Validation/OrderValidation.php',
            'Routes/Features/Order.php'] as $relative) {
            $path = $this->project->path('Project/Packages/Commerce/' . $relative);
            self::assertFileExists($path);
            $this->assertPhpLints($path);
        }
        self::assertFileExists($this->project->path('Tests/Integration/CommerceOrderFeatureTest.php'));
        $this->assertPhpLints($this->project->path('Tests/Integration/CommerceOrderFeatureTest.php'));
        self::assertCount(1,
            glob($this->project->path('Database/Migrations/*create_commerce_order_table.php')) ?: []);
        self::assertFileDoesNotExist($state, 'Source generation must not activate the Package.');

        $routeList = $this->runCommand('route:list');
        self::assertSame(0, $routeList->getExitCode(), $routeList->getOutput() . $routeList->getErrorOutput());
        self::assertStringNotContainsString('/commerce/order', $routeList->getOutput());

        // Activation is a separate Package lifecycle decision, not a generator side effect.
        $packages = new PackageManager(new Application($this->project->path()));
        self::assertTrue($packages->apply($packages->planEnable('Commerce'))->complete());
        $enabledRoutes = $this->runCommand('route:list');
        self::assertSame(0, $enabledRoutes->getExitCode(),
            $enabledRoutes->getOutput() . $enabledRoutes->getErrorOutput());
        self::assertStringContainsString('/commerce/order', $enabledRoutes->getOutput());
        self::assertStringContainsString('commerce.order.index', $enabledRoutes->getOutput());

        $root = dirname(__DIR__, 2);
        $probe = <<<'PHP'
<?php
declare(strict_types=1);
require __AUTOLOAD__;
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('Project\\', __DIR__ . '/Project');
$loader->addPsr4('Packages\\', __DIR__ . '/Project/Packages');
$loader->register(true);
$squehubApp = new \App\Foundation\Application(__DIR__);
$squehubApp->register(\App\Routing\RoutingServiceProvider::class);
$squehubApp->bootstrap();
require __ROUTES__;
echo $squehubApp->contributions()->ownerOf('route', 'GET /commerce/order')?->key() ?? 'missing';
PHP;
        $this->project->write('RouteOwnerProbe.php', str_replace(
            ['__AUTOLOAD__', '__ROUTES__'],
            [var_export($root . '/vendor/autoload.php', true),
                var_export($root . '/Bootstrap/Routes.php', true)],
            $probe
        ));
        $ownerProbe = new Process([PHP_BINARY, $this->project->path('RouteOwnerProbe.php')],
            $this->project->path());
        $ownerProbe->run();
        self::assertSame(0, $ownerProbe->getExitCode(), $ownerProbe->getErrorOutput());
        self::assertSame('package:Commerce', trim($ownerProbe->getOutput()));
    }

    public function testExplicitTableAndRouteAvoidNameInflection(): void
    {
        $apply = $this->runCommand('make:feature', 'News', '--table=news_items',
            '--route=/news/items', '--yes');
        self::assertSame(0, $apply->getExitCode(), $apply->getOutput() . $apply->getErrorOutput());
        self::assertCount(1,
            glob($this->project->path('Database/Migrations/*create_news_items_table.php')) ?: []);
        $route = (string) file_get_contents($this->project->path('Project/Routes/Features/News.php'));
        self::assertStringContainsString("Route::path('/news/items')", $route);
    }

    public function testGeneratedFeatureTestRunsWithThePublicTestingApi(): void
    {
        $apply = $this->runCommand('make:feature', 'Post', '--yes');
        self::assertSame(0, $apply->getExitCode(), $apply->getOutput() . $apply->getErrorOutput());

        $this->assertGeneratedTestPasses('Tests/Integration/PostFeatureTest.php');
    }

    public function testGeneratedPackageFeatureTestRunsWithThePublicTestingApi(): void
    {
        $this->project->write('Project/Packages/Commerce/Commerce.php',
            '<?php namespace Packages\\Commerce; final class Commerce extends \\App\\Plugins\\ServiceProvider {}');
        $apply = $this->runCommand('make:feature', 'Order', '--package=Commerce', '--yes');
        self::assertSame(0, $apply->getExitCode(), $apply->getOutput() . $apply->getErrorOutput());

        $this->assertGeneratedTestPasses('Tests/Integration/CommerceOrderFeatureTest.php');
    }

    public function testGeneratedModelAndResourceReturnOnlyIdFromRealSqlite(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for generated feature HTTP integration.');
        }
        $apply = $this->runCommand('make:feature', 'Post', '--yes');
        self::assertSame(0, $apply->getExitCode(), $apply->getOutput() . $apply->getErrorOutput());

        $migration = glob($this->project->path('Database/Migrations/*create_post_table.php')) ?: [];
        self::assertCount(1, $migration);
        $this->testApplication()->write('Database/Migrations/' . basename($migration[0]),
            (string) file_get_contents($migration[0]));
        $this->testApplication()->write('Project/Routes/Features/Post.php',
            (string) file_get_contents($this->project->path('Project/Routes/Features/Post.php')));

        $loader = new ClassLoader();
        $loader->addPsr4('Project\\', $this->project->path('Project'));
        $loader->register(true);
        try {
            self::assertSame([basename($migration[0])], $this->migrate());
            $database = $this->app()->container()->make(DatabaseManager::class);
            self::assertSame(1, $database->connection()->pdo()->exec('INSERT INTO post DEFAULT VALUES'));
            $response = $this->getJson('/post')->assertOk()->assertJson();
            self::assertSame([
                'data' => [['id' => 1]],
                'meta' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'pages' => 1,
                    'from' => 1, 'to' => 1, 'has_next' => false, 'has_previous' => false],
            ], json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            $loader->unregister();
        }
    }

    private function assertGeneratedTestPasses(string $relative): void
    {
        $root = dirname(__DIR__, 2);
        $this->project->write('FeatureTestBootstrap.php', '<?php declare(strict_types=1); require '
            . var_export($root . '/vendor/autoload.php', true) . '; '
            . '$loader = new \\Composer\\Autoload\\ClassLoader(); '
            . '$loader->addPsr4("Project\\\\", __DIR__ . "/Project"); '
            . '$loader->register(true);');
        $test = new Process([PHP_BINARY, $root . '/vendor/bin/phpunit', '--no-configuration',
            '--bootstrap', $this->project->path('FeatureTestBootstrap.php'),
            $this->project->path($relative)], $this->project->path());
        $test->run();
        self::assertSame(0, $test->getExitCode(), $test->getOutput() . $test->getErrorOutput());
        self::assertStringContainsString('OK', $test->getOutput());
    }

    private function runCommand(string ...$args): Process
    {
        $process = new Process([PHP_BINARY, $this->project->path('CliRunner.php'), ...$args, '--no-ansi'],
            $this->project->path());
        $process->run();
        return $process;
    }

    private function assertPhpLints(string $path): void
    {
        $lint = new Process([PHP_BINARY, '-l', $path]);
        $lint->run();
        self::assertTrue($lint->isSuccessful(), $path . ': ' . $lint->getOutput() . $lint->getErrorOutput());
    }

    /** @return array<string,string> Directory entries and file hashes. */
    private function snapshot(): array
    {
        $entries = [];
        $walk = static function (string $directory, string $relative) use (&$walk, &$entries): void {
            foreach (scandir($directory) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $child = ltrim($relative . '/' . $name, '/');
                $path = $directory . '/' . $name;
                if (is_dir($path)) {
                    $entries[$child . '/'] = 'directory';
                    $walk($path, $child);
                } else {
                    $entries[$child] = hash_file('sha256', $path) ?: 'unreadable';
                }
            }
        };
        $walk($this->project->path(), '');
        ksort($entries, SORT_STRING);
        return $entries;
    }
}
