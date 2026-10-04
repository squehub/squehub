<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Foundation\Application;
use App\Packages\PackageManager;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real CLI subprocesses load definitions only for Scheduler commands. */
final class SchedulerCliTest extends TestCase
{
    private function runner(TemporaryProject $project, string $root, bool $database): string
    {
        $project->write('Config/Scheduler.php', $database
            ? '<?php return ["store"=>"database","database_connection"=>"test","prefix"=>"cli"];'
            : '<?php return ["store"=>"array","timezone"=>"UTC"];');
        if ($database) {
            $project->write('Config/Database.php', '<?php return ["default"=>"test","connections"=>'
                . '["test"=>["driver"=>"sqlite","database"=>__DIR__."/../schedule.sqlite"]]];');
        }
        $script = '<?php require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . '$squehubApp=new \\App\\Foundation\\Application(__DIR__); ';
        if ($database) $script .= '$squehubApp->register(\\App\\Database\\DatabaseServiceProvider::class); ';
        $script .= '$squehubApp->register(\\App\\Scheduler\\SchedulerServiceProvider::class); '
            . '$squehubApp->bootstrap(); require ' . var_export($root . '/squehub', true) . ';';
        $project->write('CliRunner.php', $script);
        return $project->path('CliRunner.php');
    }

    private function command(string $root, string $runner, string $command): Process
    {
        $process = new Process([PHP_BINARY, $runner, $command], $root);
        $process->run();
        return $process;
    }

    public function testEmptyProjectRunsWithoutDatabaseOrQueue(): void
    {
        $project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        try {
            $runner = $this->runner($project, $root, false);
            $list = $this->command($root, $runner, 'schedule:list');
            self::assertSame(0, $list->getExitCode(), $list->getErrorOutput());
            self::assertStringContainsString('No schedules registered.', $list->getOutput());
            $run = $this->command($root, $runner, 'schedule:run');
            self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
            self::assertStringContainsString('0 due', $run->getOutput());
        } finally { $project->remove(); }
    }

    public function testListDoesNotExecuteAndRunClaimsOnceWithPrivateFailure(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Scheduler CLI.');
        }
        $project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $done = $project->path('done.txt');
        $database = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => $project->path('schedule.sqlite'),
            ]],
        ]]));
        try {
            $runner = $this->runner($project, $root, true);
            require_once $root . '/Database/Migrations/2026_09_24_create_schedule_tables.php';
            $connection = $database->connection();
            (new \CreateScheduleTables())->up($connection->pdo(), $connection->schema());
            $definition = '<?php \\App\\Plugins\\Schedule::call(static function (): void {'
                . ' file_put_contents(' . var_export($done, true) . ', "done");'
                . '})->name("cli-task")->everyMinute();';
            $project->write('Project/Scheduler/Cleanup.php', $definition);
            $list = $this->command($root, $runner, 'schedule:list');
            self::assertSame(0, $list->getExitCode(), $list->getErrorOutput());
            self::assertStringContainsString('cli-task', $list->getOutput());
            self::assertFileDoesNotExist($done);
            $first = $this->command($root, $runner, 'schedule:run');
            self::assertSame(0, $first->getExitCode(), $first->getErrorOutput() . $first->getOutput());
            self::assertSame('done', file_get_contents($done));
            $second = $this->command($root, $runner, 'schedule:run');
            self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            self::assertStringContainsString('1 skipped', $second->getOutput());
            $project->write('Project/Scheduler/Cleanup.php', '<?php \\App\\Plugins\\Schedule::call('
                . 'static function (): void { throw new \\RuntimeException("secret-in-task-error"); }'
                . ')->name("failing-task")->everyMinute();');
            $failure = $this->command($root, $runner, 'schedule:run');
            self::assertNotSame(0, $failure->getExitCode());
            self::assertStringContainsString('1 failed', $failure->getOutput());
            self::assertStringNotContainsString('secret-in-task-error', $failure->getOutput() . $failure->getErrorOutput());
        } finally {
            $database->disconnect();
            $project->remove();
        }
    }

    public function testDirectoryDefinitionsLoadFromApplicationAndPackagesOnlyForScheduleCommands(): void
    {
        $project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $done = $project->path('direct-package-ran.txt');
        try {
            $runner = $this->runner($project, $root, false);
            $project->write('Project/Scheduler/AppTask.php', '<?php \\App\\Plugins\\Schedule::call('
                . 'static function (): void {})->name("app-task")->everyMinute();');
            $project->write('Project/Scheduler/Nested/AnotherTask.php', '<?php \\App\\Plugins\\Schedule::call('
                . 'static function (): void {})->name("nested-task")->everyMinute();');
            $project->write('Project/Packages/Example/Scheduler/PackageTask.php', '<?php \\App\\Plugins\\Schedule::call('
                . 'static function (): void {})->name("package-task")->everyMinute();');
            $project->write('Project/Packages/Example/Scheduler/Reports/NestedPackageTask.php', '<?php \\App\\Plugins\\Schedule::call('
                . 'static function (): void {})->name("nested-package-task")->everyMinute();');
            $project->write('Project/Packages/Example/Example.php',
                '<?php namespace Packages\\Example; final class Example extends \\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/DirectPackage/Scheduler/DirectTask.php', '<?php \\App\\Plugins\\Schedule::call('
                . 'static function (): void { file_put_contents(' . var_export($done, true) . ', "done"); }'
                . ')->name("direct-package-task")->everyMinute();');
            $project->write('Project/DirectPackage/Scheduler/Reports/NestedDirectTask.php', '<?php \\App\\Plugins\\Schedule::call('
                . 'static function (): void {})->name("nested-direct-task")->everyMinute();');
            $project->write('Project/Schedule.php', '<?php throw new \\RuntimeException("obsolete file was loaded");');
            $project->write('Project/Schedule/OldTask.php', '<?php throw new \\RuntimeException("obsolete directory was loaded");');
            $project->write('Project/Scheduler/NotPhp.ph', '<?php throw new \\RuntimeException("non-PHP extension was loaded");');

            $disabled = $this->command($root, $runner, 'schedule:list');
            self::assertSame(0, $disabled->getExitCode(), $disabled->getErrorOutput());
            self::assertDoesNotMatchRegularExpression('/\|\s*package-task\s*\|/', $disabled->getOutput());
            self::assertStringContainsString('direct-package-task', $disabled->getOutput());

            $app = new Application($project->path());
            $packages = new PackageManager($app);
            $packages->apply($packages->planEnable('Example'));
            self::assertTrue($packages->isEnabled('Example'));
            $packageStatus = $this->command($root, $runner, 'package:list');
            self::assertSame(0, $packageStatus->getExitCode(),
                $packageStatus->getOutput() . $packageStatus->getErrorOutput());
            self::assertStringContainsString('enabled', strtolower($packageStatus->getOutput()));

            $list = $this->command($root, $runner, 'schedule:list');
            self::assertSame(0, $list->getExitCode(), $list->getErrorOutput());
            self::assertStringContainsString('app-task', $list->getOutput());
            self::assertStringContainsString('nested-task', $list->getOutput());
            self::assertStringContainsString('package-task', $list->getOutput());
            self::assertStringContainsString('nested-package-task', $list->getOutput());
            self::assertStringContainsString('direct-package-task', $list->getOutput());
            self::assertStringContainsString('nested-direct-task', $list->getOutput());
            self::assertFileDoesNotExist($done);

            $run = $this->command($root, $runner, 'schedule:run');
            self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
            self::assertStringContainsString('6 due, 6 executed', $run->getOutput());
            self::assertSame('done', file_get_contents($done));

            $unrelated = $this->command($root, $runner, 'list');
            self::assertSame(0, $unrelated->getExitCode(), $unrelated->getErrorOutput());
        } finally {
            $project->remove();
        }
    }
}
