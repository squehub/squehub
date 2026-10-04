<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Packages\PackageFiles;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Package commands use an isolated application and no public network. */
final class PackageCliTest extends TestCase
{
    public function testListPreviewEnableAndDisableUsePersistentPackageState(): void
    {
        $project = new TemporaryProject();
        try {
            $this->prepareRunner($project);
            $marker = $project->path('executed.txt');
            $project->write('Project/Packages/CliWeather/CliWeather.php',
                '<?php namespace Packages\\CliWeather; file_put_contents('
                . var_export($marker, true)
                . ', "executed", FILE_APPEND); final class CliWeather extends \\App\\Plugins\\ServiceProvider {}');

            $list = $this->runCommand($project, 'package:list');
            self::assertSame(0, $list->getExitCode(), $list->getOutput() . $list->getErrorOutput());
            self::assertStringContainsString('CliWeather', $list->getOutput());
            self::assertStringContainsString('disabled', strtolower($list->getOutput()));
            self::assertFileDoesNotExist($marker, 'Package inspection must not include entry PHP.');

            $before = $this->snapshot($project);
            $preview = $this->runCommand($project, 'package:enable', 'CliWeather', '--preview');
            self::assertSame(0, $preview->getExitCode(), $preview->getOutput() . $preview->getErrorOutput());
            self::assertSame($before, $this->snapshot($project), 'Preview changed the application tree.');
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));

            $unconfirmed = $this->runCommand($project, 'package:enable', 'CliWeather');
            self::assertNotSame(0, $unconfirmed->getExitCode());
            self::assertStringContainsString('Use --yes', $unconfirmed->getOutput());
            self::assertSame($before, $this->snapshot($project), 'Non-interactive apply changed files.');

            $enable = $this->runCommand($project, 'package:enable', 'CliWeather', '--yes');
            self::assertSame(0, $enable->getExitCode(), $enable->getOutput() . $enable->getErrorOutput());
            self::assertFileExists($project->path('Project/Activation.json'));
            self::assertFileDoesNotExist($marker, 'Enabling state must not boot Package PHP.');
            $enabled = $this->runCommand($project, 'package:list');
            self::assertSame(0, $enabled->getExitCode(), $enabled->getOutput() . $enabled->getErrorOutput());
            self::assertStringContainsString('enabled', strtolower($enabled->getOutput()));
            self::assertFileDoesNotExist($marker, 'package:list must not boot enabled Packages.');

            $enabledSnapshot = $this->snapshot($project);
            $disablePreview = $this->runCommand($project, 'package:disable', 'CliWeather', '--preview');
            self::assertSame(0, $disablePreview->getExitCode());
            self::assertSame($enabledSnapshot, $this->snapshot($project));
            $disable = $this->runCommand($project, 'package:disable', 'CliWeather', '--yes');
            self::assertSame(0, $disable->getExitCode(), $disable->getOutput() . $disable->getErrorOutput());
            $disabled = $this->runCommand($project, 'package:list');
            self::assertSame(0, $disabled->getExitCode(), $disabled->getOutput() . $disabled->getErrorOutput());
            self::assertStringContainsString('disabled', strtolower($disabled->getOutput()));
        } finally {
            $project->remove();
        }
    }

    public function testInstallUpgradeAndRemoveUseLocalSourceAndWriteFreePreview(): void
    {
        $project = new TemporaryProject();
        $sourceRoot = new TemporaryProject();
        try {
            $this->prepareRunner($project);
            $sourceRoot->write('CliManaged/CliManaged.php',
                '<?php namespace Packages\\CliManaged; final class CliManaged extends \\App\\Plugins\\ServiceProvider {}');
            $sourceRoot->write('CliManaged/composer.json',
                json_encode(['name' => 'example/cli-managed', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
            $source = $sourceRoot->path('CliManaged');

            $beforeInstall = $this->snapshot($project);
            $previewInstall = $this->runCommand($project, 'package:install', $source, '--preview');
            self::assertSame(0, $previewInstall->getExitCode(),
                $previewInstall->getOutput() . $previewInstall->getErrorOutput());
            self::assertSame($beforeInstall, $this->snapshot($project));
            self::assertDirectoryDoesNotExist($project->path('Project/Packages/CliManaged'));
            $install = $this->runCommand($project, 'package:install', $source, '--yes');
            self::assertSame(0, $install->getExitCode(), $install->getOutput() . $install->getErrorOutput());
            self::assertFileExists($project->path('Project/Packages/CliManaged/CliManaged.php'));

            $sourceRoot->write('CliManaged/composer.json',
                json_encode(['name' => 'example/cli-managed', 'version' => '1.1.0'], JSON_THROW_ON_ERROR));
            $sourceRoot->write('CliManaged/new.txt', 'new version');
            $beforeUpgrade = $this->snapshot($project);
            $previewUpgrade = $this->runCommand($project, 'package:upgrade', 'CliManaged', $source, '--preview');
            self::assertSame(0, $previewUpgrade->getExitCode(),
                $previewUpgrade->getOutput() . $previewUpgrade->getErrorOutput());
            self::assertSame($beforeUpgrade, $this->snapshot($project));
            self::assertFileDoesNotExist($project->path('Project/Packages/CliManaged/new.txt'));
            $upgrade = $this->runCommand($project, 'package:upgrade', 'CliManaged', $source, '--yes');
            self::assertSame(0, $upgrade->getExitCode(), $upgrade->getOutput() . $upgrade->getErrorOutput());
            self::assertFileExists($project->path('Project/Packages/CliManaged/new.txt'));

            $beforeRemove = $this->snapshot($project);
            $previewRemove = $this->runCommand($project, 'package:remove', 'CliManaged', '--preview');
            self::assertSame(0, $previewRemove->getExitCode(),
                $previewRemove->getOutput() . $previewRemove->getErrorOutput());
            self::assertSame($beforeRemove, $this->snapshot($project));
            self::assertDirectoryExists($project->path('Project/Packages/CliManaged'));
            $remove = $this->runCommand($project, 'package:remove', 'CliManaged', '--yes');
            self::assertSame(0, $remove->getExitCode(), $remove->getOutput() . $remove->getErrorOutput());
            self::assertDirectoryDoesNotExist($project->path('Project/Packages/CliManaged'));
        } finally {
            $sourceRoot->remove();
            $project->remove();
        }
    }

    private function prepareRunner(TemporaryProject $project): void
    {
        $root = dirname(__DIR__, 2);
        // The shared fixture starts with legacy config/. This test uses the
        // canonical Config/ directory; keeping both would make its full-tree
        // snapshot invalid on a case-sensitive filesystem.
        self::assertTrue(@rmdir($project->path('config')), 'Fixture config/ must be empty.');
        $project->write('Config/App.php', "<?php return ['env' => 'testing', 'debug' => false];");
        $project->write('CliRunner.php', '<?php declare(strict_types=1); require '
            . var_export($root . '/vendor/autoload.php', true) . '; '
            . '$squehubApp = new \\App\\Foundation\\Application(__DIR__); '
            . 'if (str_starts_with((string) ($argv[1] ?? ""), "package:")) '
            . '{ $squehubApp->inspectPackagesOnly(); } '
            . '$squehubApp->bootstrap(); require '
            . var_export($root . '/App/Clis/Clis.php', true) . ';');
    }

    private function runCommand(TemporaryProject $project, string ...$args): Process
    {
        $process = new Process([PHP_BINARY, $project->path('CliRunner.php'), ...$args, '--no-ansi'],
            $project->path());
        $process->run();
        return $process;
    }

    /** @return array{array<string,string>,list<string>} */
    private function snapshot(TemporaryProject $project): array
    {
        return [PackageFiles::fingerprints($project->path()), PackageFiles::directories($project->path())];
    }
}
