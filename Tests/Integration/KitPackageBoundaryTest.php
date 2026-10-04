<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Health\CoreHealthChecks;
use App\Kits\KitException;
use App\Kits\KitManager;
use App\Packages\PackageManager;
use App\Routing\RoutingServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Kit requirements participate in Package safety without booting Kit PHP. */
final class KitPackageBoundaryTest extends TestCase
{
    public function testInstalledAndDisabledKitProtectsRequiredPackageUntilRemoval(): void
    {
        $project = new TemporaryProject();
        $source = new TemporaryProject();
        try {
            $source->write('Payments/Payments.php',
                '<?php namespace Packages\\Payments; final class Payments extends \\App\\Plugins\\ServiceProvider {}');
            $source->write('Payments/composer.json',
                '{"name":"example/payments","version":"1.0.0"}');
            $source->write('Commerce/Commerce.php',
                '<?php namespace Project\\Kits\\Commerce; use App\\Plugins\\Kit; final class Commerce extends Kit {}');
            $source->write('Commerce/kit.json', json_encode([
                'format' => 1, 'name' => 'Commerce', 'version' => '1.0.0',
                'requires' => ['Payments'],
            ], JSON_THROW_ON_ERROR));

            $app = new Application($project->path());
            $packages = $app->container()->make(PackageManager::class);
            $kits = $app->container()->make(KitManager::class);
            $packages->apply($packages->planInstall($source->path('Payments')));
            $packages->apply($packages->planEnable('Payments'));
            $kits->apply($kits->planInstall($source->path('Commerce')));
            self::assertSame(['Commerce'], $kits->requiredByPackage('Payments'));
            self::assertSame(['Commerce'], $packages->inspect('Payments')['required_by_kits']);
            self::assertNotEmpty($packages->planDisable('Payments')->conflicts);
            self::assertNotEmpty($packages->planRemove('Payments')->conflicts);

            $kits->apply($kits->planEnable('Commerce'));
            $kits->apply($kits->planDisable('Commerce'));
            self::assertNotEmpty($packages->planDisable('Payments')->conflicts,
                'Published Project code may remain after Kit disable.');
            $kits->apply($kits->planRemove('Commerce'));
            self::assertSame([], $kits->requiredByPackage('Payments'));
            self::assertSame([], $packages->planDisable('Payments')->conflicts);
            self::assertSame([], $packages->planRemove('Payments')->conflicts);
        } finally {
            $source->remove();
            $project->remove();
        }
    }

    public function testDoctorReportsBrokenKitWithoutLoadingEntryPhp(): void
    {
        $project = new TemporaryProject();
        $marker = $project->path('kit-entry-executed.txt');
        try {
            $project->write('Project/Kits/Healthy/Healthy.php',
                '<?php namespace Project\\Kits\\Healthy; use App\\Plugins\\Kit; '
                . 'file_put_contents(' . var_export($marker, true) . ', "executed"); '
                . 'final class Healthy extends Kit {}');
            $project->write('Project/Kits/Healthy/kit.json',
                '{"format":1,"name":"Healthy","version":"1.0.0"}');
            $project->write('Project/Kits/Broken/kit.json', '{"format":99}');
            $app = new Application($project->path());
            $result = (new CoreHealthChecks($app))->kits();
            self::assertSame('fail', $result->status());
            self::assertSame('kits_broken', $result->code());
            self::assertSame('2 installed: 0 enabled, 1 disabled, 1 broken.', $result->summary());
            self::assertFileDoesNotExist($marker);
        } finally {
            $project->remove();
        }
    }

    public function testEnablePlanShowsAndAppliesRequiredPackageActivation(): void
    {
        $project = new TemporaryProject();
        $source = new TemporaryProject();
        try {
            $source->write('Payments/Payments.php',
                '<?php namespace Packages\\Payments; final class Payments extends \\App\\Plugins\\ServiceProvider {}');
            $source->write('Payments/composer.json',
                '{"name":"example/payments","version":"1.0.0"}');
            $source->write('Commerce/Commerce.php',
                '<?php namespace Project\\Kits\\Commerce; use App\\Plugins\\Kit; '
                . 'final class Commerce extends Kit {}');
            $source->write('Commerce/kit.json',
                '{"format":1,"name":"Commerce","version":"1.0.0","requires":["Payments"]}');
            $app = new Application($project->path());
            $packages = $app->container()->make(PackageManager::class);
            $kits = $app->container()->make(KitManager::class);
            $packages->apply($packages->planInstall($source->path('Payments')));
            $kits->apply($kits->planInstall($source->path('Commerce')));
            self::assertFalse($packages->isEnabled('Payments'));

            $plan = $kits->planEnable('Commerce');
            self::assertFalse($plan->hasConflicts());
            self::assertContains('Project/Packages/Payments#activation',
                array_map(static fn ($action): string => $action->subject, $plan->actions));
            self::assertArrayHasKey('Project/Activation.json', $plan->preconditions);
            self::assertFalse($packages->isEnabled('Payments'), 'Planning must not change Package state.');
            self::assertTrue($kits->apply($plan)->complete());
            $state = json_decode((string) file_get_contents($project->path('Project/Activation.json')),
                true, 32, JSON_THROW_ON_ERROR);
            self::assertTrue($state['packages']['Payments']['enabled']);
            self::assertTrue($state['kits']['Commerce']['enabled']);
            self::assertFileDoesNotExist($project->path('Project/Packages/State.json'));
            self::assertFileDoesNotExist($project->path('Project/Kits/State.json'));
            self::assertTrue($packages->isEnabled('Payments'));
            self::assertSame('enabled', $kits->inspect('Commerce')['descriptor']->status());
        } finally {
            $source->remove();
            $project->remove();
        }
    }

    public function testPackageActivationInvalidatesPendingKitPlanBeforeAnyKitWrite(): void
    {
        $project = new TemporaryProject();
        $source = new TemporaryProject();
        try {
            $source->write('Payments/Payments.php',
                '<?php namespace Packages\\Payments; final class Payments extends \\App\\Plugins\\ServiceProvider {}');
            $source->write('Payments/composer.json', '{"name":"example/payments","version":"1.0.0"}');
            $source->write('Commerce/Commerce.php',
                '<?php namespace Project\\Kits\\Commerce; final class Commerce extends \\App\\Plugins\\Kit {}');
            $source->write('Commerce/kit.json',
                '{"format":1,"name":"Commerce","version":"1.0.0","requires":["Payments"]}');

            $app = new Application($project->path());
            $packages = $app->container()->make(PackageManager::class);
            $kits = $app->container()->make(KitManager::class);
            $packages->apply($packages->planInstall($source->path('Payments')));
            $kits->apply($kits->planInstall($source->path('Commerce')));

            $pending = $kits->planEnable('Commerce');
            self::assertFalse($pending->hasConflicts());
            $packages->apply($packages->planEnable('Payments'));
            try {
                $kits->apply($pending);
                self::fail('A Package activation must stale an earlier Kit plan.');
            } catch (KitException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertSame('disabled', $kits->inspect('Commerce')['descriptor']->status());
        } finally {
            $source->remove();
            $project->remove();
        }
    }

    public function testKitEnablePlansDeepPackageRequirementsInDependencyOrder(): void
    {
        $project = new TemporaryProject();
        $source = new TemporaryProject();
        try {
            foreach (['Ledger', 'Commerce'] as $name) {
                $project->write('Project/Packages/' . $name . '/' . $name . '.php',
                    '<?php namespace Packages\\' . $name . '; final class ' . $name
                    . ' extends \\App\\Plugins\\ServiceProvider {}');
            }
            $project->write('Project/Packages/Ledger/composer.json',
                '{"name":"example/ledger","version":"1.0.0"}');
            $project->write('Project/Packages/Commerce/composer.json',
                '{"name":"example/commerce","version":"1.0.0",'
                . '"extra":{"squehub":{"requires":["Ledger"]}}}');
            $source->write('Storefront/Storefront.php',
                '<?php namespace Project\\Kits\\Storefront; '
                . 'final class Storefront extends \\App\\Plugins\\Kit {}');
            $source->write('Storefront/kit.json',
                '{"format":1,"name":"Storefront","version":"1.0.0",'
                . '"requires":["Commerce"]}');

            $app = new Application($project->path());
            $packages = $app->container()->make(PackageManager::class);
            $kits = $app->container()->make(KitManager::class);
            self::assertTrue($kits->apply($kits->planInstall($source->path('Storefront')))->complete());
            $plan = $kits->planEnable('Storefront');
            self::assertFalse($plan->hasConflicts());
            $subjects = array_values(array_map(
                static fn ($action): string => $action->subject,
                array_filter($plan->actions, static fn ($action): bool =>
                    str_starts_with($action->subject, 'Project/Packages/'))));
            self::assertSame([
                'Project/Packages/Ledger#activation',
                'Project/Packages/Commerce#activation',
            ], $subjects);
            self::assertFalse($packages->isEnabled('Ledger'));
            self::assertTrue($kits->apply($plan)->complete());
            self::assertTrue($packages->isEnabled('Ledger'));
            self::assertTrue($packages->isEnabled('Commerce'));
            self::assertSame('enabled', $kits->inspect('Storefront')['descriptor']->status());
        } finally {
            $source->remove();
            $project->remove();
        }
    }

    public function testPackageSourceEditInvalidatesPendingKitEnablePlan(): void
    {
        $project = new TemporaryProject();
        $source = new TemporaryProject();
        try {
            $entry = '<?php namespace Packages\\Payments; final class Payments '
                . 'extends \\App\\Plugins\\ServiceProvider {}';
            $project->write('Project/Packages/Payments/Payments.php', $entry);
            $source->write('Commerce/Commerce.php',
                '<?php namespace Project\\Kits\\Commerce; final class Commerce extends \\App\\Plugins\\Kit {}');
            $source->write('Commerce/kit.json',
                '{"format":1,"name":"Commerce","version":"1.0.0","requires":["Payments"]}');

            $app = new Application($project->path());
            $packages = $app->container()->make(PackageManager::class);
            $kits = $app->container()->make(KitManager::class);
            $kits->apply($kits->planInstall($source->path('Commerce')));
            $pending = $kits->planEnable('Commerce');
            self::assertFalse($pending->hasConflicts());
            self::assertArrayHasKey('Project/Packages/Payments#source', $pending->preconditions);
            $project->write('Project/Packages/Payments/Payments.php', $entry . "\n// changed after preview");

            try {
                $kits->apply($pending);
                self::fail('Changing required Package PHP must stale a Kit enable plan.');
            } catch (KitException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertFalse($packages->isEnabled('Payments'));
            self::assertSame('disabled', $kits->inspect('Commerce')['descriptor']->status());
        } finally {
            $source->remove();
            $project->remove();
        }
    }

    public function testTwoInstalledKitsKeepSharedPackageProtectedAfterOneRemoval(): void
    {
        $project = new TemporaryProject();
        $source = new TemporaryProject();
        try {
            $source->write('Payments/Payments.php',
                '<?php namespace Packages\\Payments; final class Payments extends \\App\\Plugins\\ServiceProvider {}');
            $source->write('Payments/composer.json',
                '{"name":"example/payments","version":"1.0.0"}');
            foreach (['Commerce', 'Subscriptions'] as $name) {
                $source->write($name . '/' . $name . '.php',
                    '<?php namespace Project\\Kits\\' . $name . '; use App\\Plugins\\Kit; '
                    . 'final class ' . $name . ' extends Kit {}');
                $source->write($name . '/kit.json', json_encode([
                    'format' => 1, 'name' => $name, 'version' => '1.0.0',
                    'requires' => ['Payments'],
                ], JSON_THROW_ON_ERROR));
            }
            $app = new Application($project->path());
            $packages = $app->container()->make(PackageManager::class);
            $kits = $app->container()->make(KitManager::class);
            $packages->apply($packages->planInstall($source->path('Payments')));
            $packages->apply($packages->planEnable('Payments'));
            foreach (['Commerce', 'Subscriptions'] as $name) {
                $kits->apply($kits->planInstall($source->path($name)));
            }
            self::assertSame(['Commerce', 'Subscriptions'], $kits->requiredByPackage('Payments'));
            $kits->apply($kits->planRemove('Commerce'));
            self::assertSame(['Subscriptions'], $kits->requiredByPackage('Payments'));
            self::assertTrue($packages->isEnabled('Payments'));
            self::assertNotEmpty($packages->planDisable('Payments')->conflicts);
        } finally {
            $source->remove();
            $project->remove();
        }
    }

    public function testKitManagersAreScopedToTheirApplicationRoots(): void
    {
        $first = new TemporaryProject();
        $second = new TemporaryProject();
        try {
            $first->write('Project/Kits/FirstKit/FirstKit.php',
                '<?php namespace Project\\Kits\\FirstKit; use App\\Plugins\\Kit; '
                . 'final class FirstKit extends Kit {}');
            $first->write('Project/Kits/FirstKit/kit.json',
                '{"format":1,"name":"FirstKit","version":"1.0.0"}');
            $managerA = (new Application($first->path()))->container()->make(KitManager::class);
            $managerB = (new Application($second->path()))->container()->make(KitManager::class);
            self::assertSame(['FirstKit'], array_map(static fn ($kit): string => $kit->name(),
                $managerA->list()));
            self::assertSame([], $managerB->list());
            self::assertNotSame($managerA, $managerB);
        } finally {
            $first->remove();
            $second->remove();
        }
    }

    public function testManualKitCanBeAdoptedWithoutGrantingManagedSourceOwnership(): void
    {
        $project = new TemporaryProject();
        $marker = $project->path('kit-entry-executed.txt');
        try {
            $project->write('Project/Kits/ManualShop/ManualShop.php',
                '<?php namespace Project\\Kits\\ManualShop; use App\\Plugins\\Kit; '
                . 'file_put_contents(' . var_export($marker, true) . ', "executed"); '
                . 'final class ManualShop extends Kit {}');
            $project->write('Project/Kits/ManualShop/kit.json', json_encode([
                'format' => 1, 'name' => 'ManualShop', 'version' => '1.0.0',
                'files' => [[
                    'source' => 'Templates/Routes/ManualShop.php',
                    'target' => 'Project/Routes/ManualShop.php',
                ]],
            ], JSON_THROW_ON_ERROR));
            $project->write('Project/Kits/ManualShop/Templates/Routes/ManualShop.php',
                '<?php // Published by a manually imported Kit.');
            $manager = (new Application($project->path()))->container()->make(KitManager::class);
            self::assertSame('manual', $manager->inspect('ManualShop')['descriptor']->sourceKind());
            self::assertSame('disabled', $manager->inspect('ManualShop')['descriptor']->status());
            self::assertFileDoesNotExist($marker);

            self::assertTrue($manager->apply($manager->planEnable('ManualShop'))->complete());
            self::assertFileExists($project->path('Project/Routes/ManualShop.php'));
            self::assertFileDoesNotExist($marker,
                'Enabling a Kit without declared hooks must not load its lifecycle PHP.');
            self::assertTrue($manager->planRemove('ManualShop')->hasConflicts(),
                'SqueHub cannot delete manually imported Kit source as managed files.');
            self::assertTrue($manager->apply($manager->planDisable('ManualShop'))->complete());
            self::assertFileExists($project->path('Project/Routes/ManualShop.php'));
        } finally {
            $project->remove();
        }
    }

    public function testPublishedProjectRouteHasKitArtifactProvenanceWithoutKitBoot(): void
    {
        $project = new TemporaryProject();
        $kitSource = new TemporaryProject();
        $marker = $project->path('kit-entry-executed.txt');
        try {
            $kitSource->write('Storefront/Storefront.php',
                '<?php namespace Project\\Kits\\Storefront; use App\\Plugins\\Kit; '
                . 'file_put_contents(' . var_export($marker, true) . ', "executed"); '
                . 'final class Storefront extends Kit {}');
            $kitSource->write('Storefront/kit.json', json_encode([
                'format' => 1, 'name' => 'Storefront', 'version' => '1.0.0',
                'files' => [[
                    'source' => 'Templates/Routes/storefront.php',
                    'target' => 'Project/Routes/storefront.php',
                ]],
            ], JSON_THROW_ON_ERROR));
            $kitSource->write('Storefront/Templates/Routes/storefront.php',
                '<?php \\App\\Routing\\Route::path("/storefront")->get(static fn (): string => "ok");');
            $app = new Application($project->path());
            $kits = $app->container()->make(KitManager::class);
            $kits->apply($kits->planInstall($kitSource->path('Storefront')));
            $kits->apply($kits->planEnable('Storefront'));

            $app->register(RoutingServiceProvider::class);
            $app->bootstrap();
            $squehubApp = $app;
            require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';
            self::assertSame('kit:Storefront',
                $app->contributions()->ownerOf('route', 'GET /storefront')?->key());
            self::assertFileDoesNotExist($marker, 'Normal route loading must not load Kit lifecycle PHP.');
        } finally {
            $kitSource->remove();
            $project->remove();
        }
    }
}
