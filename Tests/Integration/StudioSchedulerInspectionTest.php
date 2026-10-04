<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Scheduler\ScheduleLoader;
use App\Scheduler\Scheduler;
use App\Scheduler\SchedulerException;
use App\Scheduler\SchedulerServiceProvider;
use App\Studio\StudioInspector;
use App\Studio\StudioHttp;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/** Studio reads the same registered Scheduler definitions as schedule:list. */
final class StudioSchedulerInspectionTest extends TestCase
{
    public function testSchedulerPanelLoadsDefinitionsWithoutExecutingCallbacks(): void
    {
        $project = TestApplication::temporary(['scheduler' => ['store' => 'array']]);
        try {
            $marker = $project->path('task-ran.txt');
            $project->write('Project/Scheduler/Nested/Report.php', '<?php '
                . '\\App\\Plugins\\Schedule::call(static function (): void {'
                . ' file_put_contents(' . var_export($marker, true) . ', "ran");'
                . '})->name("studio-report")->everyMinute();');
            $app = $this->inspectionApplication($project);
            $inspector = new StudioInspector($app);

            // An ordinary snapshot does not evaluate Scheduler source PHP.
            self::assertSame([], $inspector->snapshot()['scheduler']['items']);
            $first = $inspector->registeredSchedules();
            self::assertSame('observed', $first['state']);
            self::assertCount(1, $first['items']);
            self::assertSame('studio-report', $first['items'][0]['name']);
            self::assertSame('every minute', $first['items'][0]['schedule']);
            self::assertSame('call', $first['items'][0]['mode']);
            self::assertSame('-', $first['items'][0]['queue']);
            self::assertSame($first, $inspector->registeredSchedules());
            self::assertFileDoesNotExist($marker);
        } finally {
            $project->cleanup();
        }
    }

    public function testEnabledPackageDefinitionsLoadWithoutPackageEntryHooks(): void
    {
        $project = TestApplication::temporary(['scheduler' => ['store' => 'array']]);
        try {
            $marker = $project->path('package-entry-ran.txt');
            $project->write('Project/Packages/ScheduleStudio/ScheduleStudio.php',
                '<?php namespace Packages\\ScheduleStudio; '
                . 'file_put_contents(' . var_export($marker, true) . ', "entry"); '
                . 'final class ScheduleStudio extends \\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Packages/ScheduleStudio/Scheduler/Task.php',
                '<?php \\App\\Plugins\\Schedule::call(static function (): void {})'
                . '->name("package-schedule")->everyMinute();');
            $manager = new PackageManager(new Application($project->root()));
            $manager->apply($manager->planEnable('ScheduleStudio'));

            $inspector = new StudioInspector($this->inspectionApplication($project));
            $rows = $inspector->registeredSchedules()['items'];
            self::assertSame(['package-schedule'], array_column($rows, 'name'));
            self::assertFileDoesNotExist($marker);
        } finally {
            $project->cleanup();
        }
    }

    public function testSeparateApplicationsRetainTheirOwnScheduleMetadata(): void
    {
        $first = TestApplication::temporary(['scheduler' => ['store' => 'array']]);
        $second = TestApplication::temporary(['scheduler' => ['store' => 'array']]);
        try {
            $first->write('Project/Scheduler/First.php',
                '<?php \\App\\Plugins\\Schedule::call(static function (): void {})'
                . '->name("first-studio")->everyMinute();');
            $second->write('Project/Scheduler/Second.php',
                '<?php \\App\\Plugins\\Schedule::call(static function (): void {})'
                . '->name("second-studio")->everyMinute();');
            $firstInspector = new StudioInspector($this->inspectionApplication($first));
            $secondInspector = new StudioInspector($this->inspectionApplication($second));
            self::assertSame(['first-studio'],
                array_column($firstInspector->registeredSchedules()['items'], 'name'));
            self::assertSame(['second-studio'],
                array_column($secondInspector->registeredSchedules()['items'], 'name'));
        } finally {
            $first->cleanup();
            $second->cleanup();
        }
    }

    public function testSchedulerPanelReusesDefinitionsLoadedEarlierByTheNormalLoader(): void
    {
        $project = TestApplication::temporary(['scheduler' => ['store' => 'array']]);
        try {
            $project->write('Project/Scheduler/One.php',
                '<?php \\App\\Plugins\\Schedule::call(static function (): void {})'
                . '->name("loaded-once")->everyMinute();');
            $app = $this->inspectionApplication($project);
            $loader = new ScheduleLoader();
            $loader->load($app);
            (new ScheduleLoader())->load($app);
            $rows = (new StudioInspector($app))->registeredSchedules()['items'];
            self::assertSame(['loaded-once'], array_column($rows, 'name'));
        } finally {
            $project->cleanup();
        }
    }

    public function testStudioSchedulerHttpPageShowsRegisteredTask(): void
    {
        $project = TestApplication::temporary([
            'app' => ['env' => 'development'],
            'studio' => ['enabled' => true],
            'scheduler' => ['store' => 'array'],
        ]);
        try {
            $template = dirname(__DIR__, 2) . '/Project/Views/Studio/Dashboard.squehub.php';
            $project->write('Project/Views/Studio/Dashboard.squehub.php',
                (string) file_get_contents($template));
            $project->write('Project/Scheduler/Task.php',
                '<?php \\App\\Plugins\\Schedule::call(static function (): void {})'
                . '->name("http-studio-task")->everyMinute();');
            $app = $this->inspectionApplication($project);
            $page = (new StudioHttp($app))->handle('GET', '/studio/scheduler',
                '127.0.0.1', '127.0.0.1:8100', 8100);
            self::assertSame(200, $page->status(), $page->content());
            self::assertStringContainsString('http-studio-task', $page->content());
            self::assertStringNotContainsString('No records available', $page->content());
        } finally {
            $project->cleanup();
        }
    }

    public function testFailedDefinitionLoadCannotAppendASecondPartialCopy(): void
    {
        $project = TestApplication::temporary(['scheduler' => ['store' => 'array']]);
        try {
            $project->write('Project/Scheduler/A.php',
                '<?php \\App\\Plugins\\Schedule::call(static function (): void {})'
                . '->name("first-file")->everyMinute();');
            $project->write('Project/Scheduler/B.php',
                '<?php throw new \\RuntimeException("broken definition");');
            $app = $this->inspectionApplication($project);
            try {
                (new ScheduleLoader())->load($app);
                self::fail('The broken definition must fail its first load.');
            } catch (\RuntimeException $exception) {
                self::assertSame('broken definition', $exception->getMessage());
            }
            self::assertCount(1, $app->container()->make(Scheduler::class)->definitions());
            $this->expectException(SchedulerException::class);
            (new ScheduleLoader())->load($app);
        } finally {
            $project->cleanup();
        }
    }

    private function inspectionApplication(TestApplication $project): Application
    {
        $app = new Application($project->root());
        $app->inspectPackagesOnly();
        $app->register(SchedulerServiceProvider::class);
        $app->bootstrap();
        return $app;
    }
}
