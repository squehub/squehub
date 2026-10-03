<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use App\Packages\PackageStateStore;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises each generator through the real console in a disposable project. */
final class GeneratorCliTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $this->project->write('.example.env', "APP_ENV=development\nAPP_KEY=\n");
        $this->project->write('.env', 'APP_ENV=testing' . "\n" . 'APP_KEY=base64:'
            . base64_encode(str_repeat('g', 32)) . "\n");
        $this->project->write('Config/App.php', "<?php return ['env' => 'testing', 'debug' => false];");
        $this->project->write('CliRunner.php', '<?php declare(strict_types=1); require '
            . var_export($root . '/vendor/autoload.php', true) . '; '
            . '$squehubApp = new \\App\\Foundation\\Application(__DIR__); '
            . '\\App\\Foundation\\CliBootstrapMode::configure($squehubApp, $argv); '
            . '$squehubApp->bootstrap(); require '
            . var_export($root . '/App/Clis/Clis.php', true) . ';');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testCommandInventoryAndHelpUseCanonicalSeederVocabulary(): void
    {
        $list = $this->runCommand('list', '--raw');
        self::assertSame(0, $list->getExitCode(), $list->getErrorOutput());
        foreach (['controller', 'middleware', 'migration', 'model', 'seeder'] as $kind) {
            self::assertStringContainsString('make:' . $kind, $list->getOutput());
            $help = $this->runCommand('make:' . $kind, '--help');
            self::assertSame(0, $help->getExitCode(), $help->getErrorOutput());
            self::assertStringContainsString('--preview', $help->getOutput());
            self::assertStringContainsString('--yes', $help->getOutput());
        }
        self::assertStringNotContainsString('make:dumper', $list->getOutput());
        self::assertStringNotContainsString('dump:run', $list->getOutput());
        self::assertStringNotContainsString('dump:rollback', $list->getOutput());
        self::assertStringContainsString('seed:rollback', $list->getOutput());
        self::assertStringContainsString('seed:status', $list->getOutput());
    }

    public function testPreviewMakesNoFilesAndEachCommandCreatesOneValidPhpFile(): void
    {
        $commands = [
            'controller' => ['GeneratedPanelController', 'Project/Controllers/GeneratedPanelController.php'],
            'middleware' => ['GeneratedAudit', 'Project/Middleware/GeneratedAudit.php'],
            'model' => ['GeneratedInvoice', 'Project/Models/GeneratedInvoice.php'],
            'seeder' => ['GeneratedInvoiceSeeder', 'Database/Seeders/GeneratedInvoiceSeeder.php'],
        ];

        foreach ($commands as $kind => [$name, $relativePath]) {
            $beforePreview = $this->snapshot();
            $preview = $this->runCommand('make:' . $kind, $name, '--preview');
            self::assertSame(0, $preview->getExitCode(), $preview->getOutput() . $preview->getErrorOutput());
            self::assertStringContainsString('SqueHub Change Plan', $preview->getOutput());
            self::assertStringContainsString('CREATE', $preview->getOutput());
            self::assertStringContainsString($relativePath, $preview->getOutput());
            self::assertSame($beforePreview, $this->snapshot(),
                'Preview must leave the entire disposable project byte-identical.');
            self::assertFileDoesNotExist($this->project->path($relativePath));

            $unconfirmed = $this->runCommand('make:' . $kind, $name);
            self::assertNotSame(0, $unconfirmed->getExitCode());
            self::assertStringContainsString('Use --yes to apply', $unconfirmed->getOutput());
            self::assertFileDoesNotExist($this->project->path($relativePath));

            $create = $this->runCommand('make:' . $kind, $name, '--yes');
            self::assertSame(0, $create->getExitCode(), $create->getOutput() . $create->getErrorOutput());
            self::assertStringContainsString('Created ' . $relativePath, $create->getOutput());
            self::assertFileExists($this->project->path($relativePath));
            $this->assertPhpLints($relativePath);

            $content = (string) file_get_contents($this->project->path($relativePath));
            $repeat = $this->runCommand('make:' . $kind, $name, '--yes');
            self::assertNotSame(0, $repeat->getExitCode());
            self::assertSame($content, file_get_contents($this->project->path($relativePath)));
        }

        $beforeMigration = $this->snapshot();
        $migrationPreview = $this->runCommand('make:migration',
            'create_generated_invoices_table', '--preview');
        self::assertSame(0, $migrationPreview->getExitCode());
        self::assertSame($beforeMigration, $this->snapshot());
        $migration = $this->runCommand('make:migration', 'create_generated_invoices_table', '--yes');
        self::assertSame(0, $migration->getExitCode(), $migration->getOutput() . $migration->getErrorOutput());
        $files = glob($this->project->path('Database/Migrations/*_create_generated_invoices_table.php')) ?: [];
        self::assertCount(1, $files);
        $this->assertPhpLints('Database/Migrations/' . basename($files[0]));
    }

    public function testPackageGenerationUsesExistingPackageOnly(): void
    {
        $this->project->write('Project/Packages/Commerce/Commerce.php',
            '<?php namespace Packages\\Commerce; final class Commerce extends \\App\\Plugins\\ServiceProvider {}');
        $beforePreview = $this->snapshot();
        $preview = $this->runCommand('make:controller', 'OrderController', '--package=Commerce', '--preview');
        self::assertSame(0, $preview->getExitCode(), $preview->getOutput() . $preview->getErrorOutput());
        self::assertStringContainsString('Project/Packages/Commerce/Controllers/OrderController.php', $preview->getOutput());
        self::assertSame($beforePreview, $this->snapshot());
        self::assertFileDoesNotExist($this->project->path('Project/Packages/Commerce/Controllers/OrderController.php'));

        $create = $this->runCommand('make:controller', 'OrderController', '--package=Commerce', '--yes');
        self::assertSame(0, $create->getExitCode(), $create->getOutput() . $create->getErrorOutput());
        self::assertFileExists($this->project->path('Project/Packages/Commerce/Controllers/OrderController.php'));
        $this->assertPhpLints('Project/Packages/Commerce/Controllers/OrderController.php');

        $missing = $this->runCommand('make:model', 'MissingModel', '--package=Missing');
        self::assertNotSame(0, $missing->getExitCode());
        self::assertDirectoryDoesNotExist($this->project->path('Project/Packages/Missing'));

        foreach (['migration' => 'create_package_data', 'seeder' => 'PackageSeeder'] as $kind => $name) {
            $unsupported = $this->runCommand('make:' . $kind, $name, '--package=Commerce');
            self::assertNotSame(0, $unsupported->getExitCode());
            self::assertDirectoryDoesNotExist($this->project->path('Project/Packages/Commerce/Database'));
        }
    }

    public function testPreviewAndApplyDoNotBootAnEnabledPackage(): void
    {
        $marker = $this->project->path('PackageHookExecuted.txt');
        $this->project->write('Project/Packages/HookProbe/HookProbe.php',
            '<?php namespace Packages\\HookProbe; file_put_contents(' . var_export($marker, true)
            . ', "executed"); throw new \\RuntimeException("Package hook ran during make command"); '
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
        $preview = $this->runCommand('make:controller', 'SafePreviewController', '--preview');
        self::assertSame(0, $preview->getExitCode(), $preview->getOutput() . $preview->getErrorOutput());
        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($marker);

        $help = $this->runCommand('help', 'make:controller');
        self::assertSame(0, $help->getExitCode(), $help->getOutput() . $help->getErrorOutput());
        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($marker);

        $apply = $this->runCommand('make:controller', 'SafePreviewController', '--yes');
        self::assertSame(0, $apply->getExitCode(), $apply->getOutput() . $apply->getErrorOutput());
        self::assertFileExists($this->project->path('Project/Controllers/SafePreviewController.php'));
        self::assertFileDoesNotExist($marker);
    }

    public function testGeneratedMiddlewareRunsThroughColdStartHttpPipeline(): void
    {
        $create = $this->runCommand('make:middleware', 'GeneratedAudit', '--yes');
        self::assertSame(0, $create->getExitCode(), $create->getOutput() . $create->getErrorOutput());

        $root = dirname(__DIR__, 2);
        $runner = <<<'PHP'
<?php
declare(strict_types=1);
require __AUTOLOAD__;
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('Project\\', __PROJECT__);
$loader->register(true);
$app = new \App\Foundation\Application(__DIR__);
$app->register(\App\Http\HttpServiceProvider::class);
$app->register(\App\Routing\RoutingServiceProvider::class);
$app->bootstrap();
\App\Routing\Route::path('/generated')
    ->get(static fn (): string => 'reached')
    ->through(\Project\Middleware\GeneratedAudit::class);
$response = $app->container()->make(\App\Http\Kernel::class)
    ->handle(new \App\Http\Request('GET', '/generated'));
echo $response->status() . '|' . $response->content();
PHP;
        $this->project->write('PipelineRunner.php', str_replace(
            ['__AUTOLOAD__', '__PROJECT__'],
            [var_export($root . '/vendor/autoload.php', true),
                var_export($this->project->path('Project'), true)],
            $runner
        ));
        $process = new Process([PHP_BINARY, $this->project->path('PipelineRunner.php')],
            $this->project->path());
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('200|reached', $process->getOutput());
    }

    public function testInvalidInputFailsWithoutWritingOrExposingStackTrace(): void
    {
        foreach (['../Escape', 'Admin/../Escape', 'C:\\Escape', 'Bad..Name'] as $name) {
            $run = $this->runCommand('make:controller', $name);
            self::assertNotSame(0, $run->getExitCode(), $name);
            self::assertStringNotContainsString('Fatal error', $run->getOutput() . $run->getErrorOutput());
            self::assertStringNotContainsString('Stack trace', $run->getOutput() . $run->getErrorOutput());
        }
        self::assertDirectoryDoesNotExist($this->project->path('Project/Controllers'));
    }

    private function runCommand(string ...$args): Process
    {
        $process = new Process([PHP_BINARY, $this->project->path('CliRunner.php'), ...$args, '--no-ansi'],
            $this->project->path());
        $process->run();
        return $process;
    }

    private function assertPhpLints(string $relativePath): void
    {
        $lint = new Process([PHP_BINARY, '-l', $this->project->path($relativePath)]);
        $lint->run();
        self::assertTrue($lint->isSuccessful(), $lint->getOutput() . $lint->getErrorOutput());
    }

    /** @return array<string,string> Include directory names and file bytes. */
    private function snapshot(): array
    {
        $root = $this->project->path();
        $entries = [];
        $walk = static function (string $directory, string $relative) use (&$walk, &$entries): void {
            foreach (scandir($directory) ?: [] as $name) {
                if ($name === '.' || $name === '..') { continue; }
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
        $walk($root, '');
        ksort($entries, SORT_STRING);
        return $entries;
    }
}
