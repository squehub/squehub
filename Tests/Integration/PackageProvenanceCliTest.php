<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Static inspection reads the persisted snapshot without loading Package PHP. */
final class PackageProvenanceCliTest extends TestCase
{
    public function testExplicitVerificationPersistsSafeSnapshotAndStaticInspectionDetectsStaleness(): void
    {
        $project = new TemporaryProject();
        $entryMarker = $project->path('entry-ran.txt');
        $routeMarker = $project->path('route-file-ran.txt');
        $scheduleMarker = $project->path('schedule-file-ran.txt');
        $taskMarker = $project->path('scheduled-task-ran.txt');
        try {
            $this->prepareRunner($project);
            $project->write('Project/Packages/SnapshotWeather/SnapshotWeather.php',
                '<?php namespace Packages\\SnapshotWeather; file_put_contents('
                . var_export($entryMarker, true) . ', "entry\\n", FILE_APPEND); '
                . 'final class SnapshotWeather extends \\App\\Plugins\\ServiceProvider {'
                . 'public function register(): void {'
                . '$this->app->config()->set("packages.SnapshotWeather.secret", '
                . '"PLANTED_PROVENANCE_SECRET_DO_NOT_LEAK");'
                . '$this->app->container()->singleton("snapshot.forecast", \\stdClass::class);'
                . '$this->app->container()->bind("snapshot.unresolved", '
                . '"PLANTED_FACTORY_TARGET_SECRET_DO_NOT_LEAK");'
                . '} }');
            $project->write('Project/Packages/SnapshotWeather/Routes/web.php',
                '<?php file_put_contents(' . var_export($routeMarker, true)
                . ', "route\\n", FILE_APPEND); '
                . '\\App\\Routing\\Route::path("/weather")->get('
                . 'static fn (): string => "sunny")->named("weather.index");');
            $project->write('Project/Packages/SnapshotWeather/Scheduler/Forecast.php',
                '<?php file_put_contents(' . var_export($scheduleMarker, true)
                . ', "schedule\\n", FILE_APPEND); '
                . '\\App\\Plugins\\Schedule::call(static function (): void {'
                . 'file_put_contents(' . var_export($taskMarker, true) . ', "task");'
                . '})->name("weather-refresh")->everyMinute();');
            $project->write('Project/Packages/SnapshotWeather/Views/Forecast.squehub.php',
                '<?php throw new \\RuntimeException("View must not render during verification.");');

            $before = $this->runCommand($project, 'package:inspect', 'SnapshotWeather');
            self::assertSame(0, $before->getExitCode(), $this->output($before));
            self::assertStringContainsString('Provenance: unavailable', $before->getOutput());
            self::assertStringContainsString('Activation: not enabled', $before->getOutput());
            self::assertFileDoesNotExist($entryMarker);
            self::assertFileDoesNotExist($routeMarker);
            self::assertFileDoesNotExist($scheduleMarker);

            $enable = $this->runCommand($project, 'package:enable', 'SnapshotWeather', '--yes');
            self::assertSame(0, $enable->getExitCode(), $this->output($enable));
            $enabled = $this->runCommand($project, 'package:inspect', 'SnapshotWeather');
            self::assertSame(0, $enabled->getExitCode(), $this->output($enabled));
            self::assertStringContainsString('Status: enabled', $enabled->getOutput());
            self::assertStringContainsString('Activation: explicitly enabled in Activation Registry',
                $enabled->getOutput());
            self::assertStringContainsString('Required by: none', $enabled->getOutput());
            self::assertFileDoesNotExist($entryMarker,
                'Neither enable nor static inspect may execute an entry class.');

            $verify = $this->runCommand($project, 'package:verify', 'SnapshotWeather');
            self::assertSame(0, $verify->getExitCode(), $this->output($verify));
            self::assertSame("entry\n", file_get_contents($entryMarker));
            self::assertSame("route\n", file_get_contents($routeMarker));
            self::assertSame("schedule\n", file_get_contents($scheduleMarker));
            self::assertFileDoesNotExist($taskMarker,
                'Verifying a schedule definition must never execute the task.');

            $state = (string) file_get_contents($project->path('Project/Activation.json'));
            self::assertStringNotContainsString('PLANTED_PROVENANCE_SECRET_DO_NOT_LEAK', $state);
            self::assertStringNotContainsString('PLANTED_FACTORY_TARGET_SECRET_DO_NOT_LEAK', $state);
            self::assertStringNotContainsString(str_replace('\\', '/', $project->path()), $state);
            $decoded = json_decode($state, true, 64, JSON_THROW_ON_ERROR);
            $items = $decoded['packages']['SnapshotWeather']['contribution_snapshot']['items'];
            self::assertContains('route', array_column($items, 'type'));
            self::assertContains('scheduler', array_column($items, 'type'));
            self::assertContains('view', array_column($items, 'type'));
            self::assertContains('config', array_column($items, 'type'));
            self::assertContains('service', array_column($items, 'type'));

            $entrySize = filesize($entryMarker);
            $routeSize = filesize($routeMarker);
            $scheduleSize = filesize($scheduleMarker);
            $inspected = $this->runCommand($project, 'package:inspect', 'SnapshotWeather', '--type=route');
            self::assertSame(0, $inspected->getExitCode(), $this->output($inspected));
            self::assertStringContainsString('Provenance: current', $inspected->getOutput());
            self::assertStringContainsString('GET /weather', $inspected->getOutput());
            self::assertStringContainsString('Project/Packages/SnapshotWeather/Routes/web.php',
                $inspected->getOutput());
            self::assertStringNotContainsString('PLANTED_PROVENANCE_SECRET_DO_NOT_LEAK',
                $this->output($inspected));
            self::assertSame($entrySize, filesize($entryMarker));
            self::assertSame($routeSize, filesize($routeMarker));
            self::assertSame($scheduleSize, filesize($scheduleMarker));

            $sameSourceVerification = $this->runCommand($project, 'package:verify', 'SnapshotWeather');
            self::assertSame(0, $sameSourceVerification->getExitCode(),
                $this->output($sameSourceVerification));
            self::assertSame($state,
                (string) file_get_contents($project->path('Project/Activation.json')),
                'Equivalent Package source and registrations must serialize identically.');
            $entrySize = filesize($entryMarker);
            $routeSize = filesize($routeMarker);

            $routeFile = $project->path('Project/Packages/SnapshotWeather/Routes/web.php');
            file_put_contents($routeFile, "\n// Source changed after verification.\n", FILE_APPEND);
            $stale = $this->runCommand($project, 'package:inspect', 'SnapshotWeather');
            self::assertSame(0, $stale->getExitCode(), $this->output($stale));
            self::assertStringContainsString('Provenance: stale', $stale->getOutput());
            self::assertSame($entrySize, filesize($entryMarker));
            self::assertSame($routeSize, filesize($routeMarker));

            $reverify = $this->runCommand($project, 'package:verify', 'SnapshotWeather');
            self::assertSame(0, $reverify->getExitCode(), $this->output($reverify));
            $fresh = $this->runCommand($project, 'package:inspect', 'SnapshotWeather');
            self::assertStringContainsString('Provenance: current', $fresh->getOutput());

            $disable = $this->runCommand($project, 'package:disable', 'SnapshotWeather', '--yes');
            self::assertSame(0, $disable->getExitCode(), $this->output($disable));
            $inactive = $this->runCommand($project, 'package:inspect', 'SnapshotWeather');
            self::assertSame(0, $inactive->getExitCode(), $this->output($inactive));
            self::assertStringContainsString('Status: disabled', $inactive->getOutput());
            self::assertStringContainsString('not confirmed active', $inactive->getOutput());
            self::assertFileDoesNotExist($taskMarker);
        } finally {
            $project->remove();
        }
    }

    public function testInspectionOfAnEnabledThrowingEntryDoesNotExecuteIt(): void
    {
        $project = new TemporaryProject();
        $marker = $project->path('untrusted-entry-ran.txt');
        try {
            $this->prepareRunner($project);
            $project->write('Project/Packages/ThrowingEntry/ThrowingEntry.php',
                '<?php namespace Packages\\ThrowingEntry; '
                . 'final class ThrowingEntry extends \\App\\Plugins\\ServiceProvider {} '
                . 'file_put_contents(' . var_export($marker, true)
                . ', "executed"); throw new \\RuntimeException("PLANTED_EXCEPTION_SECRET");');
            $project->write('Project/Packages/Dependent/Dependent.php',
                '<?php namespace Packages\\Dependent; final class Dependent '
                . 'extends \\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Packages/Dependent/composer.json',
                json_encode(['name' => 'example/dependent',
                    'extra' => ['squehub' => ['requires' => ['ThrowingEntry']]]], JSON_THROW_ON_ERROR));
            $enable = $this->runCommand($project, 'package:enable', 'ThrowingEntry', '--yes');
            self::assertSame(0, $enable->getExitCode(), $this->output($enable));

            $inspection = $this->runCommand($project, 'package:inspect', 'ThrowingEntry');
            self::assertSame(0, $inspection->getExitCode(), $this->output($inspection));
            self::assertStringContainsString('Provenance: unavailable', $inspection->getOutput());
            self::assertStringContainsString('Required by: Dependent', $inspection->getOutput());
            self::assertStringNotContainsString('PLANTED_EXCEPTION_SECRET', $this->output($inspection));
            self::assertFileDoesNotExist($marker);
        } finally {
            $project->remove();
        }
    }

    public function testManagedUpgradeLeavesOldSnapshotStaleUntilDeliberateVerification(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $this->prepareRunner($project);
            $sources->write('UpgradeWeather/UpgradeWeather.php',
                '<?php namespace Packages\\UpgradeWeather; final class UpgradeWeather '
                . 'extends \\App\\Plugins\\ServiceProvider {}');
            $sources->write('UpgradeWeather/Routes/web.php',
                '<?php \\App\\Routing\\Route::path("/weather-old")'
                . '->get(static fn (): string => "old");');
            $sources->write('UpgradeWeather/composer.json',
                json_encode(['name' => 'example/upgrade-weather', 'version' => '1.0.0'],
                    JSON_THROW_ON_ERROR));
            $source = $sources->path('UpgradeWeather');

            foreach ([
                ['package:install', $source, '--yes'],
                ['package:enable', 'UpgradeWeather', '--yes'],
                ['package:verify', 'UpgradeWeather'],
            ] as $arguments) {
                $process = $this->runCommand($project, ...$arguments);
                self::assertSame(0, $process->getExitCode(), $this->output($process));
            }
            $before = $this->runCommand($project, 'package:inspect', 'UpgradeWeather', '--type=route');
            self::assertStringContainsString('Provenance: current', $before->getOutput());
            self::assertStringContainsString('GET /weather-old', $before->getOutput());

            $sources->write('UpgradeWeather/Routes/web.php',
                '<?php \\App\\Routing\\Route::path("/weather-new")'
                . '->get(static fn (): string => "new");');
            $sources->write('UpgradeWeather/composer.json',
                json_encode(['name' => 'example/upgrade-weather', 'version' => '2.0.0'],
                    JSON_THROW_ON_ERROR));
            $upgrade = $this->runCommand($project, 'package:upgrade', 'UpgradeWeather', $source, '--yes');
            self::assertSame(0, $upgrade->getExitCode(), $this->output($upgrade));

            $stale = $this->runCommand($project, 'package:inspect', 'UpgradeWeather', '--type=route');
            self::assertSame(0, $stale->getExitCode(), $this->output($stale));
            self::assertStringContainsString('Provenance: stale', $stale->getOutput());
            self::assertStringContainsString('GET /weather-old', $stale->getOutput(),
                'Unverified records remain historical until a trusted verification.');

            $verify = $this->runCommand($project, 'package:verify', 'UpgradeWeather');
            self::assertSame(0, $verify->getExitCode(), $this->output($verify));
            $current = $this->runCommand($project, 'package:inspect', 'UpgradeWeather', '--type=route');
            self::assertSame(0, $current->getExitCode(), $this->output($current));
            self::assertStringContainsString('Provenance: current', $current->getOutput());
            self::assertStringContainsString('GET /weather-new', $current->getOutput());
            self::assertStringNotContainsString('GET /weather-old', $current->getOutput());
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testVerificationRejectsPackageSourceChangedByItsOwnHook(): void
    {
        $project = new TemporaryProject();
        try {
            $this->prepareRunner($project);
            $project->write('Project/Packages/SelfMutating/SelfMutating.php', <<<'PHP'
<?php namespace Packages\SelfMutating;
final class SelfMutating extends \App\Plugins\ServiceProvider {
    public function register(): void {
        file_put_contents($this->app->basePath('Project/Packages/SelfMutating/Changed.txt'), 'changed');
    }
}
PHP);
            $enable = $this->runCommand($project, 'package:enable', 'SelfMutating', '--yes');
            self::assertSame(0, $enable->getExitCode(), $this->output($enable));

            $verify = $this->runCommand($project, 'package:verify', 'SelfMutating');
            self::assertNotSame(0, $verify->getExitCode());
            self::assertStringContainsString('Package source changed during verification',
                $this->output($verify));
            self::assertFileExists($project->path('Project/Packages/SelfMutating/Changed.txt'));
            $state = json_decode((string) file_get_contents($project->path('Project/Activation.json')),
                true, 64, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('contribution_snapshot', $state['packages']['SelfMutating']);
        } finally {
            $project->remove();
        }
    }

    private function prepareRunner(TemporaryProject $project): void
    {
        $root = dirname(__DIR__, 2);
        $project->write('Config/App.php', "<?php return ['env' => 'testing', 'debug' => false];");
        $project->write('Config/Scheduler.php', "<?php return ['store' => 'array'];");
        $project->write('CliRunner.php', '<?php declare(strict_types=1); require '
            . var_export($root . '/vendor/autoload.php', true) . '; '
            . '$squehubApp = new \\App\\Foundation\\Application(__DIR__); '
            . '$command = (string) ($argv[1] ?? ""); '
            . 'if ($command === "package:verify") {'
            . ' $squehubApp->verifyPackagesOnly();'
            . ' $squehubApp->register(\\App\\Routing\\RoutingServiceProvider::class);'
            . ' $squehubApp->register(\\App\\Scheduler\\SchedulerServiceProvider::class);'
            . '} elseif (str_starts_with($command, "package:")) {'
            . ' $squehubApp->inspectPackagesOnly(); } '
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

    private function output(Process $process): string
    {
        return $process->getOutput() . $process->getErrorOutput();
    }
}
