<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Packages\PackageFiles;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Bundle commands use disposable project roots and never execute source PHP. */
final class BundleCliTest extends TestCase
{
    public function testExportInspectAndReviewedImportUseOneSafeCliFlow(): void
    {
        $source = new TemporaryProject();
        $archiveRoot = new TemporaryProject();
        $target = new TemporaryProject();
        try {
            $this->prepareRunner($source);
            self::assertTrue(@rmdir($target->path('config')));
            $marker = $archiveRoot->path('source-php-executed.txt');
            $source->write('Project/Routes/Web.php', '<?php // Imported application route source.');
            $source->write('Database/Seeders/TrapSeeder.php', '<?php file_put_contents('
                . var_export($marker, true) . ', "executed");');
            $source->write('Assets/logo.txt', 'portable asset');
            $source->write('Storage/Logs/private.log', 'SQUEHUB_BUNDLE_SECRET_DO_NOT_LEAK');
            $source->write('vendor/Private.php', '<?php // Not portable project source.');
            $source->write('.env', 'SQUEHUB_TEST_PRIVATE=SQUEHUB_BUNDLE_SECRET_DO_NOT_LEAK');

            $archive = $archiveRoot->path('portable.sqhb');
            $export = $this->runCommand($source, 'bundle:export', $archive);
            self::assertSame(0, $export->getExitCode(), $this->output($export));
            self::assertFileExists($archive);
            self::assertFileDoesNotExist($marker);

            $inspect = $this->runCommand($source, 'bundle:inspect', $archive, '--json');
            self::assertSame(0, $inspect->getExitCode(), $this->output($inspect));
            $manifest = json_decode($inspect->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame(1, $manifest['format']);
            self::assertContains('Project', $manifest['source_roots']);
            self::assertContains('Assets', $manifest['source_roots']);
            $paths = array_column($manifest['files'], 'path');
            self::assertContains('Project/Routes/Web.php', $paths);
            self::assertContains('Assets/logo.txt', $paths);
            self::assertNotContains('.env', $paths);
            self::assertNotContains('Storage/Logs/private.log', $paths);
            self::assertNotContains('vendor/Private.php', $paths);
            self::assertStringNotContainsString('SQUEHUB_BUNDLE_SECRET_DO_NOT_LEAK',
                $this->output($inspect));

            $before = $this->snapshot($target);
            $preview = $this->runCommand($source, 'bundle:import', $archive, $target->path(), '--preview', '--yes');
            self::assertSame(0, $preview->getExitCode(), $this->output($preview));
            self::assertStringContainsString('SqueHub Bundle Import Plan', $preview->getOutput());
            self::assertSame($before, $this->snapshot($target), 'Preview changed the target.');

            $unconfirmed = $this->runCommand($source, 'bundle:import', $archive, $target->path());
            self::assertNotSame(0, $unconfirmed->getExitCode());
            self::assertStringContainsString('Use --yes', $unconfirmed->getOutput());
            self::assertSame($before, $this->snapshot($target), 'Non-interactive import changed the target.');

            $import = $this->runCommand($source, 'bundle:import', $archive, $target->path(), '--yes');
            self::assertSame(0, $import->getExitCode(), $this->output($import));
            self::assertSame('portable asset', file_get_contents($target->path('Assets/logo.txt')));
            self::assertFileExists($target->path('Project/Routes/Web.php'));
            self::assertFileExists($target->path('Database/Seeders/TrapSeeder.php'));
            self::assertFileDoesNotExist($target->path('.env'));
            self::assertFileDoesNotExist($marker, 'Bundle import must not include a Seeder.');
        } finally {
            $target->remove();
            $archiveRoot->remove();
            $source->remove();
        }
    }

    public function testInspectionRejectsCorruptionWithoutExposingArchiveContent(): void
    {
        $source = new TemporaryProject();
        $archiveRoot = new TemporaryProject();
        try {
            $this->prepareRunner($source);
            $source->write('Project/Private.txt', 'SQUEHUB_BUNDLE_SECRET_DO_NOT_LEAK');
            $archive = $archiveRoot->path('portable.sqhb');
            $export = $this->runCommand($source, 'bundle:export', $archive);
            self::assertSame(0, $export->getExitCode(), $this->output($export));
            $bytes = file_get_contents($archive);
            self::assertIsString($bytes);
            self::assertNotSame('', $bytes);
            $bytes[strlen($bytes) - 1] = $bytes[strlen($bytes) - 1] === 'X' ? 'Y' : 'X';
            file_put_contents($archive, $bytes);

            $inspect = $this->runCommand($source, 'bundle:inspect', $archive);
            self::assertNotSame(0, $inspect->getExitCode());
            self::assertStringNotContainsString('SQUEHUB_BUNDLE_SECRET_DO_NOT_LEAK',
                $this->output($inspect));
        } finally {
            $archiveRoot->remove();
            $source->remove();
        }
    }

    public function testBundleCommandsAppearInGeneralAndCommandHelp(): void
    {
        $source = new TemporaryProject();
        try {
            $this->prepareRunner($source);
            $help = $this->runCommand($source, 'help', '--raw');
            self::assertSame(0, $help->getExitCode(), $this->output($help));
            foreach (['bundle:export', 'bundle:inspect', 'bundle:import'] as $name) {
                self::assertStringContainsString($name, $help->getOutput());
                $commandHelp = $this->runCommand($source, $name, '--help');
                self::assertSame(0, $commandHelp->getExitCode(), $this->output($commandHelp));
                self::assertStringContainsString('Usage:', $commandHelp->getOutput());
            }
        } finally {
            $source->remove();
        }
    }

    private function prepareRunner(TemporaryProject $source): void
    {
        $framework = dirname(__DIR__, 2);
        self::assertTrue(@rmdir($source->path('config')));
        $source->write('Config/App.php', "<?php return ['env' => 'testing', 'debug' => false];");
        $source->write('composer.json', json_encode([
            'name' => 'squehub/bundle-cli-fixture',
            'require' => ['php' => '^8.2'],
        ], JSON_THROW_ON_ERROR));
        $source->write('CliRunner.php', '<?php declare(strict_types=1); require '
            . var_export($framework . '/vendor/autoload.php', true) . '; '
            . '$squehubApp = new \\App\\Foundation\\Application(__DIR__); '
            . '$squehubApp->inspectPackagesOnly(); $squehubApp->bootstrap(); require '
            . var_export($framework . '/App/Clis/Clis.php', true) . ';');
    }

    private function runCommand(TemporaryProject $project, string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, $project->path('CliRunner.php'), ...$arguments, '--no-ansi'],
            $project->path());
        $process->run();
        return $process;
    }

    /** @return array{array<string,string>,list<string>} */
    private function snapshot(TemporaryProject $project): array
    {
        return [PackageFiles::fingerprints($project->path()), PackageFiles::directories($project->path())];
    }

    private function output(Process $process): string
    {
        return $process->getOutput() . $process->getErrorOutput();
    }
}
