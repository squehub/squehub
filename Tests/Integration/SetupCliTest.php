<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use Dotenv\Dotenv;
use App\Packages\PackageStateStore;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises SqueHub Setup through a real CLI process in a disposable project. */
final class SetupCliTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $this->project->write('vendor/autoload.php', '<?php require '
            . var_export($root . '/vendor/autoload.php', true) . ';');
        $this->project->write('App/Core/Helper.php', '<?php require '
            . var_export($root . '/App/Core/Helper.php', true) . ';');
        $bootstrap = file_get_contents($root . '/Bootstrap/App.php');
        self::assertIsString($bootstrap);
        $this->project->write('Bootstrap/App.php', $bootstrap);
        $this->project->write('CliRunner.php', '<?php declare(strict_types=1); '
            . '$squehubApp = require __DIR__ . "/Bootstrap/App.php"; require '
            . var_export($root . '/App/Clis/Clis.php', true) . ';');
        $this->project->write('composer.json', '{"require":{"php":"^8.2"}}');
        $example = file_get_contents($root . '/.example.env');
        self::assertIsString($example);
        $this->project->write('.example.env', $example);
        foreach (glob($root . '/Config/*.php') ?: [] as $configFile) {
            $contents = file_get_contents($configFile);
            self::assertIsString($contents);
            $this->project->write('Config/' . basename($configFile), $contents);
        }
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testCommandIsInCompleteHelpInventory(): void
    {
        $list = $this->runCommand('list', '--raw');
        $help = $this->runCommand('help', '--raw');
        $commandHelp = $this->runCommand('setup', '--help');

        self::assertSame(0, $list->getExitCode(), $this->output($list));
        self::assertSame(0, $help->getExitCode(), $this->output($help));
        self::assertSame(0, $commandHelp->getExitCode(), $this->output($commandHelp));
        self::assertSame($list->getOutput(), $help->getOutput());
        self::assertStringContainsString('setup', $list->getOutput());
        foreach (['--environment', '--database', '--preview', '--yes'] as $option) {
            self::assertStringContainsString($option, $commandHelp->getOutput());
        }
    }

    public function testFreshNoninteractiveSetupRequiresExplicitChoicesWithoutWriting(): void
    {
        $before = $this->snapshot();
        $run = $this->runCommand('setup', '--no-interaction');

        self::assertNotSame(0, $run->getExitCode(), $this->output($run));
        self::assertStringContainsString('--environment=', $this->output($run));
        self::assertStringContainsString('--database=', $this->output($run));
        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($this->project->path('.env'));
    }

    public function testPreviewIsByteIdenticalAndDoesNotGenerateAKeyOrOpenSqlite(): void
    {
        $before = $this->snapshot();
        $preview = $this->runCommand('setup', '--environment=local', '--database=sqlite', '--preview');

        self::assertSame(0, $preview->getExitCode(), $this->output($preview));
        self::assertStringContainsString('SqueHub Setup Plan', $preview->getOutput());
        self::assertStringContainsString('.env', $preview->getOutput());
        self::assertSame($before, $this->snapshot(), 'Preview must not create or rewrite any project file.');
        self::assertFileDoesNotExist($this->project->path('.env'));
        self::assertFileDoesNotExist($this->project->path('Storage/Database.sqlite'));
        self::assertStringNotContainsString('SqueHub Doctor', $preview->getOutput());
    }

    public function testNoninteractiveApplyWithoutYesDoesNotWrite(): void
    {
        $before = $this->snapshot();
        $run = $this->runCommand('setup', '--environment=local', '--database=sqlite', '--no-interaction');

        self::assertNotSame(0, $run->getExitCode(), $this->output($run));
        self::assertStringContainsString('SqueHub Setup Plan', $run->getOutput());
        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($this->project->path('.env'));
    }

    public function testMysqlPreviewRequiresNoServerAndLeavesConfigurationUntouched(): void
    {
        $before = $this->snapshot();
        $preview = $this->runCommand('setup', '--environment=development', '--database=mysql', '--preview');

        self::assertSame(0, $preview->getExitCode(), $this->output($preview));
        self::assertStringContainsString('SqueHub Setup Plan', $preview->getOutput());
        self::assertStringContainsString('Review MySQL host, database, username and password manually',
            $preview->getOutput());
        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($this->project->path('.env'));
        self::assertFileDoesNotExist($this->project->path('Storage/Database.sqlite'));
    }

    public function testFreshSqliteApplyRebootsDoctorAndSecondRunIsIdempotent(): void
    {
        $apply = $this->runCommand('setup', '--environment=local', '--database=sqlite', '--yes');
        self::assertSame(0, $apply->getExitCode(), $this->output($apply));
        self::assertFileExists($this->project->path('.env'));
        self::assertFileExists($this->project->path('Storage/Database.sqlite'));
        $contents = file_get_contents($this->project->path('.env'));
        self::assertIsString($contents);
        $settings = Dotenv::parse($contents);
        self::assertSame('local', $settings['APP_ENV']);
        self::assertSame('sqlite', $settings['DB_CONNECTION']);
        self::assertSame('Storage/Database.sqlite', $settings['DB_SQLITE_DATABASE']);
        self::assertMatchesRegularExpression('/\Abase64:[A-Za-z0-9+\/=]+\z/D', (string) $settings['APP_KEY']);
        self::assertSame(32, strlen(base64_decode(substr((string) $settings['APP_KEY'], 7), true)));
        self::assertStringNotContainsString((string) $settings['APP_KEY'], $this->output($apply));
        self::assertStringContainsString('SqueHub Doctor', $apply->getOutput());
        self::assertMatchesRegularExpression('/application\s+environment\s+PASS/', $apply->getOutput(),
            'Doctor must see the newly written .env through a fresh Application.');

        $beforeSecondRun = $this->snapshot();
        $second = $this->runCommand('setup', '--yes');
        self::assertSame(0, $second->getExitCode(), $this->output($second));
        self::assertStringContainsString('No required setup changes detected.', $second->getOutput());
        self::assertSame($beforeSecondRun, $this->snapshot(), 'Configured setup must not rotate the key or rewrite files.');
    }

    public function testExistingCredentialsAndUnknownSettingsStayPrivateAndUnchanged(): void
    {
        $secret = 'SQUEHUB_SETUP_SECRET_DO_NOT_LEAK';
        $key = 'base64:' . base64_encode(str_repeat('k', 32));
        $contents = "APP_ENV=development\r\nAPP_DEBUG=true\r\nAPP_KEY={$key}\r\n"
            . "DB_CONNECTION=mysql\r\nDB_PASSWORD={$secret}\r\nCUSTOM_SETTING={$secret}\r\n";
        $this->project->write('.env', $contents);
        $before = $this->snapshot();

        $preview = $this->runCommand('setup', '--environment=production', '--database=sqlite', '--preview');
        self::assertSame(0, $preview->getExitCode(), $this->output($preview));
        self::assertSame($before, $this->snapshot());
        self::assertStringNotContainsString($secret, $this->output($preview));
        self::assertStringNotContainsString($key, $this->output($preview));

        $apply = $this->runCommand('setup', '--environment=production', '--database=sqlite', '--yes');
        self::assertSame(0, $apply->getExitCode(), $this->output($apply));
        self::assertStringNotContainsString($secret, $this->output($apply));
        self::assertStringNotContainsString($key, $this->output($apply));
        $after = file_get_contents($this->project->path('.env'));
        self::assertIsString($after);
        self::assertStringContainsString("DB_PASSWORD={$secret}\r\n", $after);
        self::assertStringContainsString("CUSTOM_SETTING={$secret}\r\n", $after);
        self::assertStringContainsString("APP_KEY={$key}\r\n", $after);
        $settings = Dotenv::parse($after);
        self::assertSame('production', $settings['APP_ENV']);
        self::assertSame('false', $settings['APP_DEBUG']);
        self::assertSame('sqlite', $settings['DB_CONNECTION']);
    }

    public function testInvalidEnvironmentFailsWithoutReplacingItOrLeakingItsContent(): void
    {
        $secret = 'SQUEHUB_INVALID_ENV_SECRET_DO_NOT_LEAK';
        $this->project->write('.env', "APP KEY={$secret}\n");
        $before = $this->snapshot();

        $run = $this->runCommand('setup', '--environment=local', '--database=sqlite', '--yes');
        self::assertNotSame(0, $run->getExitCode());
        self::assertStringNotContainsString($secret, $this->output($run));
        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($this->project->path('Storage/Database.sqlite'));
    }

    public function testMissingTemplateDoesNotCreateAnIncompleteEnvironment(): void
    {
        self::assertTrue(unlink($this->project->path('.example.env')));
        $before = $this->snapshot();

        $run = $this->runCommand('setup', '--environment=local', '--database=sqlite', '--yes');
        self::assertNotSame(0, $run->getExitCode());
        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($this->project->path('.env'));
    }

    public function testSetupDoesNotExecutePackagesOrMigrations(): void
    {
        $packageMarker = $this->project->path('PackageHookExecuted.txt');
        $migrationMarker = $this->project->path('MigrationExecuted.txt');
        $this->project->write('Project/Packages/SetupProbe/SetupProbe.php',
            '<?php namespace Packages\\SetupProbe; file_put_contents('
            . var_export($packageMarker, true) . ', "executed"); '
            . 'final class SetupProbe extends \\App\\Plugins\\ServiceProvider {}');
        (new PackageStateStore($this->project->path('Project/Packages')))->write([
            'SetupProbe' => [
                'enabled' => true,
                'source_kind' => 'manual',
                'source' => 'manual',
                'files' => (object) [],
            ],
        ]);
        $this->project->write('Database/Migrations/2026_09_28_000001_setup_must_not_run.php',
            '<?php file_put_contents(' . var_export($migrationMarker, true) . ', "executed");');

        $preview = $this->runCommand('setup', '--environment=local', '--database=sqlite', '--preview');
        self::assertSame(0, $preview->getExitCode(), $this->output($preview));
        self::assertFileDoesNotExist($packageMarker);
        self::assertFileDoesNotExist($migrationMarker);

        $apply = $this->runCommand('setup', '--environment=local', '--database=sqlite', '--yes');
        self::assertSame(0, $apply->getExitCode(), $this->output($apply));
        self::assertFileDoesNotExist($packageMarker);
        self::assertFileDoesNotExist($migrationMarker);
    }

    public function testManualConfigurationAndDoctorRemainIndependentOfSetup(): void
    {
        $key = 'base64:' . base64_encode(str_repeat('m', 32));
        $this->project->write('.env', "APP_ENV=local\nAPP_DEBUG=false\nAPP_KEY={$key}\n"
            . "DB_CONNECTION=sqlite\nDB_SQLITE_DATABASE=:memory:\nCACHE_DRIVER=array\n"
            . "SESSION_DRIVER=array\nRATE_LIMIT_DRIVER=array\nQUEUE_CONNECTION=sync\n");
        $before = $this->snapshot();

        $doctor = $this->runCommand('doctor');
        self::assertSame(0, $doctor->getExitCode(), $this->output($doctor));
        self::assertStringContainsString('SqueHub Doctor', $doctor->getOutput());
        self::assertMatchesRegularExpression('/application\s+environment\s+PASS/', $doctor->getOutput());
        self::assertSame($before, $this->snapshot(), 'Manual setup verification must not depend on Setup.');
    }

    public function testDoctorBootstrapFailureReportsThatSetupWasAlreadyAppliedWithoutLeakingCause(): void
    {
        $secret = 'SQUEHUB_DOCTOR_FAILURE_SECRET_DO_NOT_LEAK';
        $this->project->write('Config/App.php', '<?php $environmentName = $environment->get("APP_ENV", "development"); '
            . 'if ($environmentName === "production") { throw new \\RuntimeException(' . var_export($secret, true)
            . '); } return ["name" => "SqueHub", "env" => $environmentName, '
            . '"debug" => $environment->boolean("APP_DEBUG", false)];');

        $run = $this->runCommand('setup', '--environment=production', '--database=sqlite', '--yes');
        self::assertNotSame(0, $run->getExitCode());
        self::assertFileExists($this->project->path('.env'));
        self::assertStringContainsString('Setup changes were applied, but Doctor verification could not complete.',
            $run->getOutput());
        self::assertStringNotContainsString($secret, $this->output($run));
    }

    private function runCommand(string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, $this->project->path('CliRunner.php'),
            ...$arguments, '--no-ansi'], $this->project->path(), [
                // The disposable project must use its own .env rather than
                // inheriting a developer shell's application configuration.
                'APP_ENV' => false, 'APP_DEBUG' => false, 'APP_KEY' => false,
                'DB_CONNECTION' => false, 'DB_SQLITE_DATABASE' => false,
            ]);
        $process->run();
        return $process;
    }

    private function output(Process $process): string
    {
        return $process->getOutput() . $process->getErrorOutput();
    }

    /** @return array<string,string> Include directory names and file bytes. */
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
