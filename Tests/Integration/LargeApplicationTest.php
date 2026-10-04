<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Core\View;
use App\Health\HealthManager;
use App\Packages\PackageDescriptor;
use App\Packages\PackageException;
use App\Packages\PackageManager;
use App\Packages\PackageStateStore;
use App\Plugins\TestCase;
use App\Scheduler\ScheduleLoader;
use App\Scheduler\Scheduler;
use App\Scheduler\SchedulerServiceProvider;
use App\Testing\TestApplication;
use App\Testing\TestClient;
use Symfony\Component\Process\Process;

/** Proves normal Project code and larger capability graphs in disposable roots. */
final class LargeApplicationTest extends TestCase
{
    public function testThirtyPackagesDiscoverActivateAndContributeDeterministically(): void
    {
        $testing = $this->testApplication();
        $trace = $testing->path('scale-trace.txt');
        $requirements = [];
        for ($number = 1; $number <= 19; $number++) {
            $requirements[sprintf('ScaleCapability%02d', $number)] = [];
        }
        $requirements += [
            'ScaleChainA' => ['ScaleChainB'],
            'ScaleChainB' => ['ScaleChainC'],
            'ScaleChainC' => ['ScaleChainD'],
            'ScaleChainD' => ['ScaleChainE'],
            'ScaleChainE' => [],
            'ScaleDormantOne' => [],
            'ScaleDormantTwo' => [],
            'ScaleGraphAlpha' => ['ScaleGraphCore'],
            'ScaleGraphBeta' => ['ScaleGraphCore'],
            'ScaleGraphCore' => [],
            'ScaleGraphShop' => ['ScaleGraphBeta', 'ScaleGraphAlpha'],
        ];
        self::assertCount(30, $requirements);
        foreach (array_reverse($requirements, true) as $name => $dependencies) {
            $this->writePackage($testing, $name, $dependencies, $trace,
                $name === 'ScaleGraphCore' ? 'commerce-platform' : null);
        }
        $this->writeRoute($testing, 'ScaleGraphAlpha', '/scale-alpha', 'alpha');
        $this->writeRoute($testing, 'ScaleGraphBeta', '/scale-beta', 'beta');
        $this->writeRoute($testing, 'ScaleDormantOne', '/scale-dormant', 'dormant');

        $capabilities = [];
        for ($number = 1; $number <= 19; $number++) {
            $capabilities[] = sprintf('ScaleCapability%02d', $number);
        }
        $activation = [...$capabilities, 'ScaleChainE', 'ScaleChainD', 'ScaleChainC',
            'ScaleChainB', 'ScaleChainA', 'ScaleGraphCore', 'ScaleGraphAlpha',
            'ScaleGraphBeta', 'ScaleGraphShop'];
        $planner = new PackageManager(new Application($testing->root()));
        foreach ($activation as $name) {
            $plan = $planner->planEnable($name);
            self::assertSame([], $plan->conflicts, $name);
            $planner->apply($plan);
        }

        $list = $planner->list();
        $discovered = $this->names($list);
        $sorted = array_keys($requirements);
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $discovered);
        self::assertCount(30, $list);
        self::assertSame('disabled', $planner->inspect('ScaleDormantOne')['descriptor']->status());
        self::assertSame('commerce-platform',
            $planner->inspect('ScaleGraphCore')['descriptor']->owner());
        self::assertSame(['ScaleGraphAlpha', 'ScaleGraphBeta'],
            $planner->graph()->dependentsOf('ScaleGraphCore'));
        self::assertSame($activation, $this->names($planner->active()));
        self::assertFileDoesNotExist($trace, 'Static graph inspection executed Package PHP.');
        $stateBeforeInspection = file_get_contents($testing->path('Project/Activation.json'));
        $staticList = $this->runCli($testing, 'package:list');
        self::assertSame(0, $staticList->getExitCode(),
            $staticList->getOutput() . $staticList->getErrorOutput());
        foreach ($requirements as $name => $_dependencies) {
            self::assertSame(1, substr_count($staticList->getOutput(), $name), $name);
        }
        $staticInspect = $this->runCli($testing, 'package:inspect', 'ScaleGraphCore');
        self::assertSame(0, $staticInspect->getExitCode(),
            $staticInspect->getOutput() . $staticInspect->getErrorOutput());
        self::assertStringContainsString('Owner: commerce-platform', $staticInspect->getOutput());
        self::assertStringContainsString('Required by: ScaleGraphAlpha, ScaleGraphBeta',
            $staticInspect->getOutput());
        self::assertSame($stateBeforeInspection,
            file_get_contents($testing->path('Project/Activation.json')));
        self::assertFileDoesNotExist($trace, 'package:list executed one of 30 Package entries.');

        $app = $this->app();
        $app->bootstrap();
        $events = file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($events);
        self::assertCount(84, $events);
        foreach (['included', 'register', 'boot'] as $phase) {
            $observed = array_values(array_map(
                static fn (string $event): string => substr($event, strlen($phase) + 1),
                array_filter($events,
                    static fn (string $event): bool => str_starts_with($event, $phase . ':'))
            ));
            self::assertSame($activation, $observed, $phase);
        }
        self::assertFalse($app->container()->has('scale.ScaleDormantOne'));
        self::assertTrue($app->container()->has('scale.ScaleGraphCore'));
        self::assertSame(2, count($planner->planDisable('ScaleGraphCore')->conflicts));

        $this->get('/scale-alpha')->assertOk()->assertContains('alpha');
        $this->get('/scale-beta')->assertOk()->assertContains('beta');
        $this->getJson('/scale-dormant')->assertStatus(404);
        self::assertSame('package:ScaleGraphAlpha',
            $app->contributions()->ownerOf('route', 'GET /scale-alpha')?->key());
        self::assertSame('package:ScaleGraphBeta',
            $app->contributions()->ownerOf('route', 'GET /scale-beta')?->key());
        self::assertSame('package:ScaleGraphCore',
            $app->contributions()->ownerOf('service', 'scale.ScaleGraphCore')?->key());
        foreach ($activation as $name) {
            self::assertSame('package:' . $name,
                $app->contributions()->ownerOf('service', 'scale.' . $name)?->key(), $name);
        }
        self::assertCount(2, $app->contributions()->byOwner(
            new \App\Contributions\ContributionOwner('package', 'ScaleGraphCore')));
        self::assertSame('package:ScaleGraphCore',
            $app->contributions()->ownerOf('view_namespace', 'ScaleGraphCore')?->key());
        self::assertNull($app->contributions()->ownerOf('route', 'GET /scale-dormant'));
        self::assertSame($events, file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            'Repeated bootstrap or requests registered a Package twice.');
    }

    public function testZeroPackageApplicationUsesProjectControllerModelViewAndConfig(): void
    {
        $testing = $this->testApplication();
        $testing->configure(['app' => ['name' => 'Small Phase 13F']]);
        $testing->write('Project/Models/SmallNote13F.php', <<<'PHP'
<?php
namespace Project\Models;
final class SmallNote13F extends \App\Database\Model {
    public function label(): string { return 'small-model'; }
}
PHP);
        $testing->write('Project/Controllers/SmallController13F.php', <<<'PHP'
<?php
namespace Project\Controllers;
final class SmallController13F {
    public function show(): string {
        $note = new \Project\Models\SmallNote13F();
        ob_start();
        \App\Core\View::render('Small13F', ['label' => $note->label()]);
        return (string) ob_get_clean();
    }
}
PHP);
        $testing->write('Project/Views/Small13F.squehub.php',
            '<h1>Small: <?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></h1>');
        $testing->write('Project/Routes/Web.php', <<<'PHP'
<?php
require_once __DIR__ . '/../Models/SmallNote13F.php';
require_once __DIR__ . '/../Controllers/SmallController13F.php';
\App\Routing\Route::path('/small')->get(
    [\Project\Controllers\SmallController13F::class, 'show']
)->named('small.show');
PHP);

        $this->get('/small')->assertOk()->assertContains('Small: small-model');
        $app = $this->app();
        self::assertSame('Small Phase 13F', $app->config()->get('app.name'));
        self::assertSame([], $app->container()->make(PackageManager::class)->list());
        self::assertSame([], $app->container()->make(PackageManager::class)->graph()->activationOrder());
        self::assertSame('application:Project',
            $app->contributions()->ownerOf('route', 'GET /small')?->key());
        $doctor = $app->container()->make(HealthManager::class)->doctor();
        $packages = array_values(array_filter($doctor->results(),
            static fn ($result): bool => $result->name() === 'packages'));
        self::assertCount(1, $packages);
        self::assertSame('pass', $packages[0]->status());
        self::assertStringContainsString('0 installed', $packages[0]->summary());
    }

    public function testOrdinaryProjectRoutesAndEnabledPackageRoutesCoexist(): void
    {
        $testing = $this->testApplication();
        $testing->configure(['packages' => ['MixedPayments13F' => ['mode' => 'application']]]);
        $testing->write('Project/Controllers/MixedOrdersController13F.php', <<<'PHP'
<?php
namespace Project\Controllers;
final class MixedOrdersController13F {
    public function index(): string { return 'project orders'; }
}
PHP);
        $testing->write('Project/Routes/Web.php', <<<'PHP'
<?php
require_once __DIR__ . '/../Controllers/MixedOrdersController13F.php';
\App\Routing\Route::path('/mixed-orders')->get(
    [\Project\Controllers\MixedOrdersController13F::class, 'index']
)->named('orders.index');
PHP);
        $this->writePackage($testing, 'MixedPayments13F');
        $testing->write('Project/Packages/MixedPayments13F/MixedPayments13F.php', <<<'PHP'
<?php
namespace Packages\MixedPayments13F;
final class MixedPayments13F extends \App\Plugins\ServiceProvider {
    public function register(): void {
        $config = $this->app->config();
        if (!$config->has('packages.MixedPayments13F.mode')) {
            $config->set('packages.MixedPayments13F.mode', 'package');
        }
        $config->set('packages.MixedPayments13F.currency', 'NGN');
    }
}
PHP);
        $this->writeRoute($testing, 'MixedPayments13F', '/mixed-payments', 'package payments');
        $planner = new PackageManager(new Application($testing->root()));
        $planner->apply($planner->planEnable('MixedPayments13F'));

        $this->get('/mixed-orders')->assertOk()->assertContains('project orders');
        $this->get('/mixed-payments')->assertOk()->assertContains('package payments');
        $app = $this->app();
        self::assertSame('application', $app->config()->get('packages.MixedPayments13F.mode'));
        self::assertSame('NGN', $app->config()->get('packages.MixedPayments13F.currency'));
        self::assertSame('application:Project',
            $app->contributions()->ownerOf('route', 'GET /mixed-orders')?->key());
        self::assertSame('package:MixedPayments13F',
            $app->contributions()->ownerOf('route', 'GET /mixed-payments')?->key());
        self::assertSame('package:MixedPayments13F',
            $app->contributions()->ownerOf('config', 'packages.MixedPayments13F.currency')?->key());
    }

    public function testDisabledDeepPrerequisiteBreaksEveryEnabledDependent(): void
    {
        $testing = $this->testApplication();
        $trace = $testing->path('disabled-chain-trace.txt');
        $this->writePackage($testing, 'BrokenChainA13F', ['BrokenChainB13F'], $trace);
        $this->writePackage($testing, 'BrokenChainB13F', ['BrokenChainC13F'], $trace);
        $this->writePackage($testing, 'BrokenChainC13F', [], $trace);
        $planner = new PackageManager(new Application($testing->root()));
        foreach (['BrokenChainC13F', 'BrokenChainB13F', 'BrokenChainA13F'] as $name) {
            $planner->apply($planner->planEnable($name));
        }
        self::assertNotEmpty($planner->planDisable('BrokenChainC13F')->conflicts,
            'A normal lifecycle plan must protect an enabled prerequisite.');

        // Simulate an externally changed but well-formed state file. The
        // manager must not infer that the two dependents are still runnable.
        $state = new PackageStateStore($testing->path('Project/Packages'));
        $records = $state->read();
        $records['BrokenChainC13F']['enabled'] = false;
        $state->write($records);
        $inspector = new PackageManager(new Application($testing->root()));
        self::assertSame('disabled', $inspector->inspect('BrokenChainC13F')['descriptor']->status());
        foreach (['BrokenChainB13F', 'BrokenChainA13F'] as $name) {
            self::assertSame('broken', $inspector->inspect($name)['descriptor']->status(), $name);
            // A deliberate enable plan may repair the disabled prerequisite;
            // inspection and runtime still reject the broken current state.
            $repair = $inspector->planEnable($name);
            self::assertSame([], $repair->conflicts, $name);
            self::assertSame(['BrokenChainC13F'], array_map(
                static fn ($action): string => $action->owner->name, $repair->actions));
        }
        self::assertSame([], $inspector->active());
        self::assertFileDoesNotExist($trace);
        try {
            $this->app();
            self::fail('An enabled dependent with a disabled prerequisite booted.');
        } catch (PackageException) {
            self::assertFileDoesNotExist($trace);
        }
    }

    public function testCyclePathsAreStaticAndDeterministic(): void
    {
        $testing = $this->testApplication();
        $trace = $testing->path('cycle-trace.txt');
        $this->writePackage($testing, 'CycleA13F', ['CycleB13F'], $trace);
        $this->writePackage($testing, 'CycleB13F', ['CycleC13F'], $trace);
        $this->writePackage($testing, 'CycleC13F', ['CycleA13F'], $trace);
        $this->writePackage($testing, 'CycleIndependent13F', [], $trace);
        $manager = new PackageManager(new Application($testing->root()));
        foreach (['CycleA13F', 'CycleB13F', 'CycleC13F'] as $name) {
            $descriptor = $manager->inspect($name)['descriptor'];
            self::assertSame('broken', $descriptor->status());
            self::assertStringContainsString(implode(' → ', $manager->graph()->cyclePathFor($name)),
                implode(' ', $descriptor->errors()));
            self::assertNotEmpty($manager->planEnable($name)->conflicts);
        }
        self::assertSame('disabled', $manager->inspect('CycleIndependent13F')['descriptor']->status());
        self::assertFileDoesNotExist($trace);
    }

    public function testOwnerMetadataAndCliInspectionAreStaticAndBounded(): void
    {
        $testing = $this->testApplication();
        $marker = $testing->path('owner-entry-ran.txt');
        $this->writePackage($testing, 'OwnedCommerce13F', [], $marker, 'Équipe Commerce-2');
        $this->writePackage($testing, 'Ownerless13F', [], $marker);
        $invalid = [
            'OwnerTooLong13F' => str_repeat('a', 129),
            'OwnerUtf8TooLong13F' => str_repeat('é', 65),
            'OwnerControl13F' => "bad\nowner",
            'OwnerUrl13F' => 'https://user:PRIVATE_OWNER_TOKEN@example.test/team',
        ];
        foreach ($invalid as $name => $owner) {
            $this->writePackage($testing, $name, [], $marker, $owner);
        }
        $manager = new PackageManager(new Application($testing->root()));
        self::assertSame('Équipe Commerce-2',
            $manager->inspect('OwnedCommerce13F')['descriptor']->owner());
        self::assertNull($manager->inspect('Ownerless13F')['descriptor']->owner());
        self::assertSame('disabled', $manager->inspect('OwnedCommerce13F')['descriptor']->status());
        self::assertSame([], $manager->planEnable('OwnedCommerce13F')->conflicts);
        self::assertSame([], $manager->planEnable('Ownerless13F')->conflicts);
        foreach (array_keys($invalid) as $name) {
            $descriptor = $manager->inspect($name)['descriptor'];
            self::assertSame('broken', $descriptor->status(), $name);
            self::assertNotEmpty($descriptor->errors(), $name);
        }
        self::assertFileDoesNotExist($marker);

        $list = $this->runCli($testing, 'package:list');
        $inspect = $this->runCli($testing, 'package:inspect', 'OwnedCommerce13F');
        self::assertSame(0, $list->getExitCode(), $list->getOutput() . $list->getErrorOutput());
        self::assertSame(0, $inspect->getExitCode(),
            $inspect->getOutput() . $inspect->getErrorOutput());
        self::assertStringContainsString('Owner', $list->getOutput());
        self::assertStringContainsString('Équipe Commerce-2', $list->getOutput());
        self::assertStringContainsString('Owner: Équipe Commerce-2', $inspect->getOutput());
        self::assertStringContainsString('Version: 1.0.0', $inspect->getOutput());
        self::assertStringNotContainsString('PRIVATE_OWNER_TOKEN',
            $list->getOutput() . $list->getErrorOutput() . $inspect->getOutput());
        self::assertFileDoesNotExist($marker,
            'Owner list/inspect must not execute entry PHP.');
        self::assertFileDoesNotExist($testing->path('Project/Activation.json'));
    }

    public function testPackageCannotSetAnotherPackagesConfigNamespace(): void
    {
        $testing = $this->testApplication();
        $this->writePackage($testing, 'ConfigOwner13F');
        $this->writePackage($testing, 'ConfigIntruder13F', ['ConfigOwner13F']);
        $testing->write('Project/Packages/ConfigIntruder13F/ConfigIntruder13F.php', <<<'PHP'
<?php
namespace Packages\ConfigIntruder13F;
final class ConfigIntruder13F extends \App\Plugins\ServiceProvider {
    public function register(): void {
        $this->app->config()->set('packages.ConfigOwner13F.hijacked', true);
    }
}
PHP);
        $planner = new PackageManager(new Application($testing->root()));
        $planner->apply($planner->planEnable('ConfigOwner13F'));
        $planner->apply($planner->planEnable('ConfigIntruder13F'));

        try {
            $this->app();
            self::fail('Cross-Package config mutation was accepted.');
        } catch (PackageException) {
            self::assertFalse($this->testApplication()->isBooted());
        }
    }

    public function testDeepManagedPrerequisiteCannotBeDisabledOrRemoved(): void
    {
        $testing = $this->testApplication();
        $testing->write('Sources/ManagedBase13F/ManagedBase13F.php',
            '<?php namespace Packages\\ManagedBase13F; final class ManagedBase13F '
            . 'extends \\App\\Plugins\\ServiceProvider {}');
        $source = $testing->path('Sources/ManagedBase13F');
        $planner = new PackageManager(new Application($testing->root()));
        $install = $planner->planInstall($source);
        self::assertSame([], $install->conflicts);
        $planner->apply($install);
        $this->writePackage($testing, 'ManagedMiddle13F', ['ManagedBase13F']);
        $this->writePackage($testing, 'ManagedTop13F', ['ManagedMiddle13F']);
        foreach (['ManagedBase13F', 'ManagedMiddle13F', 'ManagedTop13F'] as $name) {
            $planner->apply($planner->planEnable($name));
        }
        self::assertSame(['ManagedBase13F', 'ManagedMiddle13F', 'ManagedTop13F'],
            $this->names($planner->active()));
        self::assertNotEmpty($planner->planDisable('ManagedBase13F')->conflicts);
        self::assertNotEmpty($planner->planRemove('ManagedBase13F')->conflicts);
        self::assertTrue($planner->isEnabled('ManagedBase13F'));
        self::assertDirectoryExists($testing->path('Project/Packages/ManagedBase13F'));
    }

    public function testPackageRouteNameCollisionIsRejected(): void
    {
        $testing = $this->testApplication();
        foreach (['RouteAlpha13F', 'RouteBeta13F'] as $name) {
            $this->writePackage($testing, $name);
        }
        $testing->write('Project/Packages/RouteAlpha13F/Routes/Web.php',
            '<?php \\App\\Routing\\Route::path("/route-alpha")'
            . '->get(static fn (): string => "alpha")->named("shared.route");');
        $testing->write('Project/Packages/RouteBeta13F/Routes/Web.php',
            '<?php \\App\\Routing\\Route::path("/route-beta")'
            . '->get(static fn (): string => "beta")->named("shared.route");');
        $planner = new PackageManager(new Application($testing->root()));
        foreach (['RouteAlpha13F', 'RouteBeta13F'] as $name) {
            $planner->apply($planner->planEnable($name));
        }

        try {
            $this->get('/route-alpha');
            self::fail('A duplicate Package route name was accepted.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Duplicate route name', $exception->getMessage());
        }
    }

    public function testPackageMiddlewareAliasCollisionIsRejected(): void
    {
        $testing = $this->testApplication();
        foreach (['MiddlewareAlpha13F', 'MiddlewareBeta13F'] as $name) {
            $testing->write('Project/Packages/' . $name . '/' . $name . '.php',
                '<?php namespace Packages\\' . $name . '; final class ' . $name
                . ' extends \\App\\Plugins\\ServiceProvider {'
                . 'public function register(): void {'
                . '$this->app->container()->make(\\App\\Routing\\MiddlewareRegistry::class)'
                . '->alias("shared.middleware", \\stdClass::class); }}');
        }
        $planner = new PackageManager(new Application($testing->root()));
        foreach (['MiddlewareAlpha13F', 'MiddlewareBeta13F'] as $name) {
            $planner->apply($planner->planEnable($name));
        }

        try {
            $this->app();
            self::fail('A duplicate Package middleware alias was accepted.');
        } catch (PackageException) {
            self::assertFalse($testing->isBooted());
        }
    }

    public function testTwoApplicationsKeepGraphsAndContributionsSeparate(): void
    {
        $first = TestApplication::temporary();
        $second = TestApplication::temporary();
        try {
            $this->writePackage($first, 'IsolationOrders13F');
            $this->writePackage($second, 'IsolationBlog13F');
            $this->writeIsolationEntry($first, 'IsolationOrders13F', 'orders');
            $this->writeIsolationEntry($second, 'IsolationBlog13F', 'blog');
            $this->writeIsolationRoute($first, 'IsolationOrders13F', '/isolated-orders',
                'Orders', 'orders');
            $this->writeIsolationRoute($second, 'IsolationBlog13F', '/isolated-blog',
                'Blog', 'blog');
            foreach ([[$first, 'IsolationOrders13F'], [$second, 'IsolationBlog13F']] as [$testing, $name]) {
                $planner = new PackageManager(new Application($testing->root()));
                $planner->apply($planner->planEnable($name));
            }
            $appA = $first->application();
            $clientA = new TestClient($first);
            $clientA->get('/isolated-orders')->assertOk()
                ->assertContains('orders|package:IsolationOrders13F');
            $firstView = realpath($first->path('Project/Packages/IsolationOrders13F/Views')) . '/';
            self::assertContains($firstView, View::getViewPaths());
            $appB = $second->application();
            $clientB = new TestClient($second);
            $clientB->get('/isolated-blog')->assertOk()
                ->assertContains('blog|package:IsolationBlog13F');
            $secondView = realpath($second->path('Project/Packages/IsolationBlog13F/Views')) . '/';
            self::assertContains($secondView, View::getViewPaths());
            self::assertNotContains($firstView, View::getViewPaths());
            $clientA->get('/isolated-orders')->assertOk()
                ->assertContains('orders|package:IsolationOrders13F');
            $clientB->get('/isolated-blog')->assertOk()
                ->assertContains('blog|package:IsolationBlog13F');
            $packagesA = $appA->container()->make(PackageManager::class);
            $packagesB = $appB->container()->make(PackageManager::class);
            self::assertSame(['IsolationOrders13F'], $this->names($packagesA->active()));
            self::assertSame(['IsolationBlog13F'], $this->names($packagesB->active()));
            self::assertSame([], $packagesA->graph()->dependentsOf('IsolationBlog13F'));
            self::assertSame([], $packagesB->graph()->dependentsOf('IsolationOrders13F'));
            self::assertSame('package:IsolationOrders13F',
                $appA->contributions()->ownerOf('service', 'scale.IsolationOrders13F')?->key());
            self::assertNull($appA->contributions()->ownerOf('service', 'scale.IsolationBlog13F'));
            self::assertSame('package:IsolationBlog13F',
                $appB->contributions()->ownerOf('service', 'scale.IsolationBlog13F')?->key());
            self::assertNull($appB->contributions()->ownerOf('service', 'scale.IsolationOrders13F'));
            self::assertSame('orders', $appA->config()->get('packages.IsolationOrders13F.label'));
            self::assertNull($appA->config()->get('packages.IsolationBlog13F.label'));
            self::assertSame('blog', $appB->config()->get('packages.IsolationBlog13F.label'));
            self::assertNull($appB->config()->get('packages.IsolationOrders13F.label'));
            $middlewareA = $appA->container()->make(\App\Routing\MiddlewareRegistry::class);
            $middlewareB = $appB->container()->make(\App\Routing\MiddlewareRegistry::class);
            self::assertTrue($middlewareA->has('isolation.orders'));
            self::assertFalse($middlewareA->has('isolation.blog'));
            self::assertTrue($middlewareB->has('isolation.blog'));
            self::assertFalse($middlewareB->has('isolation.orders'));
            self::assertSame('package:IsolationOrders13F',
                $appA->contributions()->ownerOf('route', 'GET /isolated-orders')?->key());
            self::assertNull($appA->contributions()->ownerOf('route', 'GET /isolated-blog'));
            self::assertSame('package:IsolationBlog13F',
                $appB->contributions()->ownerOf('route', 'GET /isolated-blog')?->key());
            self::assertNull($appB->contributions()->ownerOf('route', 'GET /isolated-orders'));
        } finally {
            $second->cleanup();
            $first->cleanup();
        }
    }

    public function testRoutesLoadedAfterAnotherApplicationBootUseTheirOwnRegistry(): void
    {
        $first = TestApplication::temporary();
        $second = TestApplication::temporary();
        try {
            $this->writePackage($first, 'RouteOrderAlpha13F');
            $this->writePackage($second, 'RouteOrderBeta13F');
            $this->writeRoute($first, 'RouteOrderAlpha13F', '/late-alpha', 'late alpha');
            $this->writeRoute($second, 'RouteOrderBeta13F', '/late-beta', 'late beta');
            foreach ([[$first, 'RouteOrderAlpha13F'], [$second, 'RouteOrderBeta13F']] as [$testing, $name]) {
                $planner = new PackageManager(new Application($testing->root()));
                $planner->apply($planner->planEnable($name));
            }

            // Neither application's routes are loaded during boot. Route
            // loading in A must still target A after B has booted.
            $appA = $first->application();
            $appB = $second->application();
            (new TestClient($first))->get('/late-alpha')->assertOk()->assertContains('late alpha');
            (new TestClient($second))->get('/late-beta')->assertOk()->assertContains('late beta');
            self::assertSame('package:RouteOrderAlpha13F',
                $appA->contributions()->ownerOf('route', 'GET /late-alpha')?->key());
            self::assertNull($appA->contributions()->ownerOf('route', 'GET /late-beta'));
            self::assertSame('package:RouteOrderBeta13F',
                $appB->contributions()->ownerOf('route', 'GET /late-beta')?->key());
            self::assertNull($appB->contributions()->ownerOf('route', 'GET /late-alpha'));
        } finally {
            $second->cleanup();
            $first->cleanup();
        }
    }

    public function testInterleavedRequestsResolveCacheAndSessionFromTheirOwnApplication(): void
    {
        $first = TestApplication::temporary();
        $second = TestApplication::temporary();
        try {
            $this->writeFacadeRoutes($first, 'A');
            $this->writeFacadeRoutes($second, 'B');
            $appA = $first->application();
            $clientA = new TestClient($first);
            $clientA->get('/facade-write')->assertOk()->assertContains('written A');
            self::assertSame('A', $appA->container()->make(\App\Cache\CacheStore::class)
                ->read('phase13f.shared'));

            $appB = $second->application();
            $clientB = new TestClient($second);
            $clientB->get('/facade-write')->assertOk()->assertContains('written B');
            self::assertSame('B', $appB->container()->make(\App\Cache\CacheStore::class)
                ->read('phase13f.shared'));
            self::assertSame('A', $appA->container()->make(\App\Cache\CacheStore::class)
                ->read('phase13f.shared'));

            $clientA->get('/facade-read')->assertOk()->assertContains('A|A');
            $clientB->get('/facade-read')->assertOk()->assertContains('B|B');
            $clientA->get('/facade-read')->assertOk()->assertContains('A|A');
        } finally {
            $second->cleanup();
            $first->cleanup();
        }
    }

    public function testScheduleLoaderUsesTheSelectedApplicationAfterAnotherBoot(): void
    {
        $first = TestApplication::temporary(['scheduler' => ['store' => 'array', 'timezone' => 'UTC']]);
        $second = TestApplication::temporary(['scheduler' => ['store' => 'array', 'timezone' => 'UTC']]);
        try {
            $first->write('Project/Scheduler/Alpha.php',
                '<?php \\App\\Plugins\\Schedule::call(static function (): void {})'
                . '->name("isolated-alpha")->everyMinute();');
            $second->write('Project/Scheduler/Beta.php',
                '<?php \\App\\Plugins\\Schedule::call(static function (): void {})'
                . '->name("isolated-beta")->everyMinute();');
            $appA = new Application($first->root());
            $appA->register(SchedulerServiceProvider::class);
            $appA->bootstrap();
            $appB = new Application($second->root());
            $appB->register(SchedulerServiceProvider::class);
            $appB->bootstrap();

            $loader = new ScheduleLoader();
            $loader->load($appA);
            $schedulerA = $appA->container()->make(Scheduler::class);
            $schedulerB = $appB->container()->make(Scheduler::class);
            self::assertSame(['isolated-alpha'], array_map(
                static fn ($task): string => $task->taskName(), $schedulerA->definitions()));
            self::assertSame([], $schedulerB->definitions());
            $loader->load($appB);
            self::assertSame(['isolated-beta'], array_map(
                static fn ($task): string => $task->taskName(), $schedulerB->definitions()));
            self::assertSame(['isolated-alpha'], array_map(
                static fn ($task): string => $task->taskName(), $schedulerA->definitions()));
            self::assertSame('application:Project',
                $appA->contributions()->ownerOf('scheduler', 'isolated-alpha')?->key());
            self::assertNull($appA->contributions()->ownerOf('scheduler', 'isolated-beta'));
            self::assertSame('application:Project',
                $appB->contributions()->ownerOf('scheduler', 'isolated-beta')?->key());
            self::assertNull($appB->contributions()->ownerOf('scheduler', 'isolated-alpha'));
        } finally {
            $second->cleanup();
            $first->cleanup();
        }
    }

    public function testLaterPackageRegisterHookUsesItsOwnApplicationsCache(): void
    {
        $first = TestApplication::temporary();
        $second = TestApplication::temporary();
        try {
            $this->writePackage($first, 'HookCacheAlpha13F');
            $this->writePackage($second, 'HookCacheBeta13F');
            $this->writeCacheHook($first, 'HookCacheAlpha13F', 'A');
            $this->writeCacheHook($second, 'HookCacheBeta13F', 'B');
            foreach ([[$first, 'HookCacheAlpha13F'], [$second, 'HookCacheBeta13F']] as [$testing, $name]) {
                $planner = new PackageManager(new Application($testing->root()));
                $planner->apply($planner->planEnable($name));
            }

            $appA = $first->application();
            $cacheA = $appA->container()->make(\App\Cache\CacheStore::class);
            self::assertSame('A', $cacheA->read('phase13f.hook'));
            $appB = $second->application();
            $cacheB = $appB->container()->make(\App\Cache\CacheStore::class);
            self::assertSame('B', $cacheB->read('phase13f.hook'));
            self::assertSame('A', $cacheA->read('phase13f.hook'));
        } finally {
            $second->cleanup();
            $first->cleanup();
        }
    }

    /** @param list<string> $requires */
    private function writePackage(TestApplication $testing, string $name, array $requires = [],
        ?string $trace = null, ?string $owner = null): void
    {
        $literal = $trace === null ? null : var_export($trace, true);
        $include = $literal === null ? '' : 'file_put_contents(' . $literal . ', '
            . var_export('included:' . $name . "\n", true) . ', FILE_APPEND); ';
        $register = $literal === null ? '' : 'file_put_contents(' . $literal . ', '
            . var_export('register:' . $name . "\n", true) . ', FILE_APPEND); ';
        $boot = $literal === null ? '' : 'file_put_contents(' . $literal . ', '
            . var_export('boot:' . $name . "\n", true) . ', FILE_APPEND); ';
        $source = '<?php namespace Packages\\' . $name . '; ' . $include
            . 'final class ' . $name . ' extends \\App\\Plugins\\ServiceProvider {'
            . 'public function register(): void { ' . $register
            . '$this->app->container()->singleton(' . var_export('scale.' . $name, true)
            . ', \\stdClass::class); }'
            . 'public function boot(): void { ' . $boot . '} }';
        $testing->write('Project/Packages/' . $name . '/' . $name . '.php', $source);
        $squehub = ['requires' => $requires];
        if ($owner !== null) {
            $squehub['owner'] = $owner;
        }
        $testing->write('Project/Packages/' . $name . '/composer.json',
            json_encode(['name' => 'example/' . strtolower($name), 'version' => '1.0.0',
                'extra' => ['squehub' => $squehub]], JSON_THROW_ON_ERROR));
    }

    private function writeRoute(TestApplication $testing, string $name, string $path, string $body): void
    {
        $testing->write('Project/Packages/' . $name . '/Routes/Web.php',
            '<?php \\App\\Routing\\Route::path(' . var_export($path, true)
            . ')->get(static fn (): string => ' . var_export($body, true)
            . ')->named(' . var_export(strtolower($name) . '.index', true) . ');');
    }

    private function writeIsolationEntry(TestApplication $testing, string $name, string $label): void
    {
        $testing->write('Project/Packages/' . $name . '/' . $name . '.php',
            '<?php namespace Packages\\' . $name . '; final class ' . $name
            . ' extends \\App\\Plugins\\ServiceProvider {'
            . 'public function register(): void {'
            . '$this->app->container()->singleton(' . var_export('scale.' . $name, true)
            . ', \\stdClass::class);'
            . '$this->app->config()->set('
            . var_export('packages.' . $name . '.label', true) . ', '
            . var_export($label, true) . ');'
            . '$this->app->container()->make(\\App\\Routing\\MiddlewareRegistry::class)'
            . '->alias(' . var_export('isolation.' . $label, true)
            . ', \\stdClass::class); }}');
    }

    private function writeIsolationRoute(TestApplication $testing, string $name,
        string $path, string $view, string $body): void
    {
        $testing->write('Project/Packages/' . $name . '/Routes/Web.php',
            '<?php \\App\\Routing\\Route::path(' . var_export($path, true)
            . ')->get(static function (): string {'
            . '$source = \\App\\Core\\View::sourceOf(' . var_export($view, true) . ');'
            . 'ob_start(); \\App\\Core\\View::render(' . var_export($view, true) . ');'
            . 'return (string) ob_get_clean() . "|"'
            . ' . ($source?->owner->key() ?? "none");'
            . '})->named(' . var_export(strtolower($name) . '.index', true) . ');');
        $testing->write('Project/Packages/' . $name . '/Views/' . $view . '.squehub.php',
            $body);
    }

    private function writeFacadeRoutes(TestApplication $testing, string $label): void
    {
        $testing->write('Project/Routes/Web.php',
            '<?php \\App\\Routing\\Route::path("/facade-write")'
            . '->get(static function (): string {'
            . '\\App\\Cache\\Cache::store()->store("phase13f.shared", '
            . var_export($label, true) . ');'
            . '\\App\\Session\\Session::manager()->store()->put("phase13f.shared", '
            . var_export($label, true) . ');'
            . 'return ' . var_export('written ' . $label, true) . '; });'
            . '\\App\\Routing\\Route::path("/facade-read")'
            . '->get(static fn (): string => (string) \\App\\Cache\\Cache::store()'
            . '->read("phase13f.shared", "missing") . "|"'
            . ' . (string) \\App\\Session\\Session::manager()->store()'
            . '->get("phase13f.shared", "missing"));');
    }

    private function writeCacheHook(TestApplication $testing, string $name, string $label): void
    {
        $testing->write('Project/Packages/' . $name . '/' . $name . '.php',
            '<?php namespace Packages\\' . $name . '; final class ' . $name
            . ' extends \\App\\Plugins\\ServiceProvider {'
            . 'public function register(): void {'
            . '\\App\\Cache\\Cache::store()->store("phase13f.hook", '
            . var_export($label, true) . '); }}');
    }

    private function runCli(TestApplication $testing, string ...$args): Process
    {
        $runner = 'CliRunner13F.php';
        if (!is_file($testing->path($runner))) {
            $root = dirname(__DIR__, 2);
            $testing->write($runner, '<?php declare(strict_types=1); require '
                . var_export($root . '/vendor/autoload.php', true) . '; '
                . '$squehubApp = new \\App\\Foundation\\Application(__DIR__); '
                . '$squehubApp->inspectPackagesOnly(); $squehubApp->bootstrap(); require '
                . var_export($root . '/App/Clis/Clis.php', true) . ';');
        }
        $process = new Process([PHP_BINARY, $testing->path($runner), ...$args, '--no-ansi'],
            $testing->root());
        $process->run();
        return $process;
    }

    /** @param list<PackageDescriptor> $descriptors
     *  @return list<string>
     */
    private function names(array $descriptors): array
    {
        return array_map(static fn (PackageDescriptor $descriptor): string => $descriptor->name(),
            $descriptors);
    }
}
