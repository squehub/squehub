<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Packages\PackageFiles;
use App\Foundation\Application;
use App\Foundation\CliBootstrapMode;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Kit commands inspect and review isolated Projects before any trusted apply. */
final class KitCliTest extends TestCase
{
    public function testEveryKitCommandBootsInStaticPackageInspectionMode(): void
    {
        $project = new TemporaryProject();
        try {
            $marker = $project->path('package-provider-executed.txt');
            $project->write('Project/Packages/Guard/Guard.php',
                '<?php namespace Packages\\Guard; file_put_contents('
                . var_export($marker, true) . ', "executed"); '
                . 'final class Guard extends \\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Packages/State.json', json_encode([
                'version' => 1,
                'packages' => [
                    'Guard' => [
                        'enabled' => true,
                        'source_kind' => 'manual',
                        'source' => 'manual',
                        'files' => (object) [],
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            foreach (['list', 'inspect', 'install', 'enable', 'disable', 'upgrade', 'remove']
                as $operation) {
                $app = new Application($project->path());
                CliBootstrapMode::configure($app, ['squehub', 'kit:' . $operation]);
                $app->bootstrap();
                self::assertFileDoesNotExist($marker, 'kit:' . $operation . ' booted a Package provider.');
            }
        } finally {
            $project->remove();
        }
    }

    public function testListInspectAndPreviewNeverExecuteKitEntryPhp(): void
    {
        $project = new TemporaryProject();
        try {
            $this->prepareRunner($project);
            $marker = $project->path('kit-executed.txt');
            $project->write('Project/Kits/ManualKit/kit.json', json_encode([
                'format' => 1,
                'name' => 'ManualKit',
                'version' => '1.0.0',
            ], JSON_THROW_ON_ERROR));
            $project->write('Project/Kits/ManualKit/ManualKit.php',
                '<?php namespace Project\\Kits\\ManualKit; use App\\Plugins\\Kit; '
                . 'file_put_contents(' . var_export($marker, true) . ', "executed"); '
                . 'final class ManualKit extends Kit {}');

            $list = $this->runCommand($project, 'kit:list');
            self::assertSame(0, $list->getExitCode(), $list->getOutput() . $list->getErrorOutput());
            self::assertStringContainsString('ManualKit', $list->getOutput());
            self::assertFileDoesNotExist($marker);

            $inspect = $this->runCommand($project, 'kit:inspect', 'ManualKit');
            self::assertSame(0, $inspect->getExitCode(), $inspect->getOutput() . $inspect->getErrorOutput());
            self::assertStringContainsString('Version: 1.0.0', $inspect->getOutput());
            self::assertFileDoesNotExist($marker);

            $before = $this->snapshot($project);
            $preview = $this->runCommand($project, 'kit:enable', 'ManualKit', '--preview');
            self::assertSame(0, $preview->getExitCode(), $preview->getOutput() . $preview->getErrorOutput());
            self::assertStringContainsString('SqueHub Kit Change Plan', $preview->getOutput());
            self::assertSame($before, $this->snapshot($project));
            self::assertFileDoesNotExist($marker);

            $unconfirmed = $this->runCommand($project, 'kit:enable', 'ManualKit');
            self::assertSame(1, $unconfirmed->getExitCode());
            self::assertStringContainsString('Use --yes', $unconfirmed->getOutput());
            self::assertSame($before, $this->snapshot($project));
            self::assertFileDoesNotExist($marker);
        } finally {
            $project->remove();
        }
    }

    public function testBrokenKitRemainsInspectableWithoutPrintingManifestSecrets(): void
    {
        $project = new TemporaryProject();
        try {
            $this->prepareRunner($project);
            $marker = $project->path('broken-kit-executed.txt');
            $secret = 'SQUEHUB_KIT_SECRET_DO_NOT_LEAK';
            $project->write('Project/Kits/BrokenKit/kit.json', json_encode([
                'format' => 1,
                'name' => 'BrokenKit',
                'version' => '1.0.0',
                'password' => $secret,
            ], JSON_THROW_ON_ERROR));
            $project->write('Project/Kits/BrokenKit/BrokenKit.php',
                '<?php namespace Project\\Kits\\BrokenKit; use App\\Plugins\\Kit; '
                . 'file_put_contents(' . var_export($marker, true) . ', "executed"); '
                . 'final class BrokenKit extends Kit {}');

            foreach ([['kit:list'], ['kit:inspect', 'BrokenKit'],
                ['kit:enable', 'BrokenKit', '--preview']] as $arguments) {
                $process = $this->runCommand($project, ...$arguments);
                if ($arguments[0] === 'kit:enable') {
                    self::assertSame(1, $process->getExitCode());
                } else {
                    self::assertSame(0, $process->getExitCode(),
                        $process->getOutput() . $process->getErrorOutput());
                }
                self::assertStringNotContainsString($secret,
                    $process->getOutput() . $process->getErrorOutput());
                self::assertFileDoesNotExist($marker);
            }
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
        } finally {
            $project->remove();
        }
    }

    public function testManagedInstallEnableDisableAndRemoveUseReviewablePlans(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $this->prepareRunner($project);
            $sources->write('ShopKit/kit.json', json_encode([
                'format' => 1,
                'name' => 'ShopKit',
                'version' => '1.0.0',
                'files' => [[
                    'source' => 'Templates/Routes/Shop.php',
                    'target' => 'Project/Routes/Shop.php',
                ]],
            ], JSON_THROW_ON_ERROR));
            $sources->write('ShopKit/ShopKit.php',
                '<?php namespace Project\\Kits\\ShopKit; use App\\Plugins\\Kit; '
                . 'final class ShopKit extends Kit {}');
            $sources->write('ShopKit/Templates/Routes/Shop.php', '<?php // ShopKit route fixture.');
            $source = $sources->path('ShopKit');

            $before = $this->snapshot($project);
            $preview = $this->runCommand($project, 'kit:install', $source, '--preview');
            self::assertSame(0, $preview->getExitCode(), $preview->getOutput() . $preview->getErrorOutput());
            self::assertSame($before, $this->snapshot($project));
            self::assertDirectoryDoesNotExist($project->path('Project/Kits/ShopKit'));

            $install = $this->runCommand($project, 'kit:install', $source, '--yes');
            self::assertSame(0, $install->getExitCode(), $install->getOutput() . $install->getErrorOutput());
            self::assertFileExists($project->path('Project/Kits/ShopKit/kit.json'));
            self::assertFileDoesNotExist($project->path('Project/Routes/Shop.php'));

            $enablePreview = $this->runCommand($project, 'kit:enable', 'ShopKit', '--preview');
            self::assertSame(0, $enablePreview->getExitCode(),
                $enablePreview->getOutput() . $enablePreview->getErrorOutput());
            self::assertFileDoesNotExist($project->path('Project/Routes/Shop.php'));

            $enable = $this->runCommand($project, 'kit:enable', 'ShopKit', '--yes');
            self::assertSame(0, $enable->getExitCode(), $enable->getOutput() . $enable->getErrorOutput());
            self::assertFileExists($project->path('Project/Routes/Shop.php'));

            $sources->write('ShopKit/kit.json', json_encode([
                'format' => 1,
                'name' => 'ShopKit',
                'version' => '1.1.0',
                'files' => [[
                    'source' => 'Templates/Routes/Shop.php',
                    'target' => 'Project/Routes/Shop.php',
                ]],
            ], JSON_THROW_ON_ERROR));
            $sources->write('ShopKit/Templates/Routes/Shop.php', '<?php // ShopKit route fixture v2.');
            $beforeUpgrade = $this->snapshot($project);
            $upgradePreview = $this->runCommand($project, 'kit:upgrade', 'ShopKit', $source, '--preview');
            self::assertSame(0, $upgradePreview->getExitCode(),
                $upgradePreview->getOutput() . $upgradePreview->getErrorOutput());
            self::assertSame($beforeUpgrade, $this->snapshot($project));
            $upgrade = $this->runCommand($project, 'kit:upgrade', 'ShopKit', $source, '--yes');
            self::assertSame(0, $upgrade->getExitCode(), $upgrade->getOutput() . $upgrade->getErrorOutput());
            self::assertSame('<?php // ShopKit route fixture v2.',
                file_get_contents($project->path('Project/Routes/Shop.php')));

            $disable = $this->runCommand($project, 'kit:disable', 'ShopKit', '--yes');
            self::assertSame(0, $disable->getExitCode(), $disable->getOutput() . $disable->getErrorOutput());
            self::assertFileExists($project->path('Project/Routes/Shop.php'),
                'Disable must not remove published application code.');

            $removePreview = $this->runCommand($project, 'kit:remove', 'ShopKit', '--preview');
            self::assertSame(0, $removePreview->getExitCode(),
                $removePreview->getOutput() . $removePreview->getErrorOutput());
            self::assertFileExists($project->path('Project/Routes/Shop.php'));
            $remove = $this->runCommand($project, 'kit:remove', 'ShopKit', '--yes');
            self::assertSame(0, $remove->getExitCode(), $remove->getOutput() . $remove->getErrorOutput());
            self::assertDirectoryDoesNotExist($project->path('Project/Kits/ShopKit'));
            self::assertFileDoesNotExist($project->path('Project/Routes/Shop.php'));
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    private function prepareRunner(TemporaryProject $project): void
    {
        $root = dirname(__DIR__, 2);
        self::assertTrue(@rmdir($project->path('config')));
        $project->write('Config/App.php', "<?php return ['env' => 'testing', 'debug' => false];");
        $project->write('CliRunner.php', '<?php declare(strict_types=1); require '
            . var_export($root . '/vendor/autoload.php', true) . '; '
            . '$squehubApp = new \\App\\Foundation\\Application(__DIR__); '
            . 'if (str_starts_with((string) ($argv[1] ?? ""), "kit:")) '
            . '{ $squehubApp->inspectPackagesOnly(); } '
            . '$squehubApp->bootstrap(); require '
            . var_export($root . '/App/Clis/Clis.php', true) . ';');
    }

    private function runCommand(TemporaryProject $project, string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, $project->path('CliRunner.php'),
            ...$arguments, '--no-ansi'], $project->path());
        $process->run();
        return $process;
    }

    /** @return array{array<string,string>,list<string>} */
    private function snapshot(TemporaryProject $project): array
    {
        return [PackageFiles::fingerprints($project->path()),
            PackageFiles::directories($project->path())];
    }
}
