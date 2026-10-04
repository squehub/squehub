<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Plugins\TestCase;
use App\Routing\MiddlewareRegistry;
use App\Scheduler\ScheduleLoader;
use LogicException;

/** Package definition files obey the same registration boundaries as entry hooks. */
final class PackageContributionBoundaryTest extends TestCase
{
    public function testRouteFileCannotSetAnotherPackagesConfiguration(): void
    {
        $this->entry('RouteConfigBoundaryAlpha');
        $this->testApplication()->write('Project/Packages/RouteConfigBoundaryAlpha/Routes/Web.php', <<<'PHP'
<?php
$squehubApp->config()->set('packages.ForeignPackage.mode', 'unexpected');
PHP);
        $this->enable('RouteConfigBoundaryAlpha');
        $app = $this->app();

        try {
            $this->testApplication()->loadRoutes();
            self::fail('A Package route file changed another configuration namespace.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('only set its own', $exception->getMessage());
        }

        self::assertFalse($app->config()->has('packages.ForeignPackage.mode'));
        self::assertNull($app->contributions()->currentOwner());
        $app->config()->set('app.after_failed_route', true);
        self::assertTrue($app->config()->get('app.after_failed_route'));
    }

    public function testRouteFileCannotReplaceAnotherPackagesMiddlewareAlias(): void
    {
        $this->entry('RouteAliasBoundaryAlpha');
        $this->entry('RouteAliasBoundaryBeta');
        $this->testApplication()->write('Project/Packages/RouteAliasBoundaryAlpha/Routes/Web.php', <<<'PHP'
<?php
$squehubApp->config()->set('packages.RouteAliasBoundaryAlpha.mode', 'safe');
$squehubApp->container()->make(\App\Routing\MiddlewareRegistry::class)
    ->alias('shared.route.alias', \stdClass::class);
\App\Routing\Route::path('/route-alpha')->get(static fn (): string => 'alpha')->named('alpha.show');
PHP);
        $this->testApplication()->write('Project/Packages/RouteAliasBoundaryBeta/Routes/Web.php', <<<'PHP'
<?php
$squehubApp->container()->make(\App\Routing\MiddlewareRegistry::class)
    ->alias('shared.route.alias', \ArrayObject::class);
PHP);
        $this->enable('RouteAliasBoundaryAlpha', 'RouteAliasBoundaryBeta');
        $app = $this->app();

        try {
            $this->testApplication()->loadRoutes();
            self::fail('The second Package replaced a middleware alias.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        self::assertSame('package:RouteAliasBoundaryAlpha',
            $app->contributions()->ownerOf('middleware', 'shared.route.alias')?->key());
        self::assertSame('package:RouteAliasBoundaryAlpha',
            $app->contributions()->ownerOf('route', 'GET /route-alpha')?->key());
        self::assertSame('safe', $app->config()->get('packages.RouteAliasBoundaryAlpha.mode'));
        self::assertSame('package:RouteAliasBoundaryAlpha',
            $app->contributions()->ownerOf('config', 'packages.RouteAliasBoundaryAlpha.mode')?->key());
        self::assertNull($app->contributions()->currentOwner());
        $app->container()->make(MiddlewareRegistry::class)
            ->alias('shared.route.alias', \ArrayObject::class);
    }

    public function testSchedulerFileCanSetOwnDefaultButNotAnotherPackagesConfiguration(): void
    {
        $this->entry('ScheduleConfigBoundaryAlpha');
        $this->entry('ScheduleConfigBoundaryBeta');
        $this->testApplication()->write('Project/Packages/ScheduleConfigBoundaryAlpha/Scheduler/One.php', <<<'PHP'
<?php
$application->config()->set('packages.ScheduleConfigBoundaryAlpha.mode', 'safe');
PHP);
        $this->testApplication()->write('Project/Packages/ScheduleConfigBoundaryBeta/Scheduler/Two.php', <<<'PHP'
<?php
$application->config()->set('packages.ScheduleConfigBoundaryAlpha.foreign', 'unexpected');
PHP);
        $this->enable('ScheduleConfigBoundaryAlpha', 'ScheduleConfigBoundaryBeta');
        $app = $this->app();

        try {
            (new ScheduleLoader())->load($app);
            self::fail('A Package Scheduler file changed another configuration namespace.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('only set its own', $exception->getMessage());
        }

        self::assertSame('safe', $app->config()->get('packages.ScheduleConfigBoundaryAlpha.mode'));
        self::assertFalse($app->config()->has('packages.ScheduleConfigBoundaryAlpha.foreign'));
        self::assertSame('package:ScheduleConfigBoundaryAlpha',
            $app->contributions()->ownerOf('config', 'packages.ScheduleConfigBoundaryAlpha.mode')?->key());
        self::assertNull($app->contributions()->currentOwner());
        $app->config()->set('app.after_failed_schedule', true);
        self::assertTrue($app->config()->get('app.after_failed_schedule'));
    }

    public function testSchedulerFileCannotReplaceAnotherPackagesMiddlewareAlias(): void
    {
        $this->entry('ScheduleAliasBoundaryAlpha');
        $this->entry('ScheduleAliasBoundaryBeta');
        $this->testApplication()->write('Project/Packages/ScheduleAliasBoundaryAlpha/Scheduler/One.php', <<<'PHP'
<?php
$application->container()->make(\App\Routing\MiddlewareRegistry::class)
    ->alias('shared.scheduler.alias', \stdClass::class);
PHP);
        $this->testApplication()->write('Project/Packages/ScheduleAliasBoundaryBeta/Scheduler/Two.php', <<<'PHP'
<?php
$application->container()->make(\App\Routing\MiddlewareRegistry::class)
    ->alias('shared.scheduler.alias', \ArrayObject::class);
PHP);
        $this->enable('ScheduleAliasBoundaryAlpha', 'ScheduleAliasBoundaryBeta');
        $app = $this->app();

        try {
            (new ScheduleLoader())->load($app);
            self::fail('The second Package replaced a middleware alias.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        self::assertSame('package:ScheduleAliasBoundaryAlpha',
            $app->contributions()->ownerOf('middleware', 'shared.scheduler.alias')?->key());
        self::assertNull($app->contributions()->currentOwner());
        $app->container()->make(MiddlewareRegistry::class)
            ->alias('shared.scheduler.alias', \ArrayObject::class);
    }

    private function entry(string $name): void
    {
        $this->testApplication()->write("Project/Packages/{$name}/{$name}.php",
            '<?php namespace Packages\\' . $name . '; final class ' . $name
            . ' extends \\App\\Plugins\\ServiceProvider {}');
    }

    private function enable(string ...$names): void
    {
        $packages = new PackageManager(new Application($this->testApplication()->root()));
        foreach ($names as $name) {
            $packages->apply($packages->planEnable($name));
        }
    }
}
