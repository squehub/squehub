<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Kits\KitException;
use App\Kits\KitManager;
use App\Packages\PackageManager;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises Kit ownership against real isolated Project files, without loading Kit PHP on reads. */
final class KitLifecycleTest extends TestCase
{
    public function testInstallEnableDisableAndRemoveKeepDefinitionSeparateFromPublishedCode(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            self::assertTrue(@rmdir($project->path('config')));
            $this->writeKit($sources, 'Checkout', '1.0.0', [
                ['source' => 'Templates/Routes/Checkout.php', 'target' => 'Project/Routes/Checkout.php'],
                ['source' => 'Config/Checkout.php', 'target' => 'Config/Checkout.php'],
                ['source' => 'Assets/checkout.css', 'target' => 'public/assets/Kits/Checkout/checkout.css'],
            ]);
            $sources->write('Checkout/Templates/Routes/Checkout.php', '<?php // Checkout route.');
            $sources->write('Checkout/Config/Checkout.php', '<?php return [];');
            $sources->write('Checkout/Assets/checkout.css', 'body { color: blue; }');
            $manager = $this->manager($project);

            $install = $manager->planInstall($sources->path('Checkout'));
            self::assertFalse($install->hasConflicts());
            self::assertDirectoryDoesNotExist($project->path('Project/Kits/Checkout'));
            self::assertTrue($manager->apply($install)->complete());
            self::assertSame('disabled', $manager->inspect('Checkout')['descriptor']->status());
            self::assertFileDoesNotExist($project->path('Project/Routes/Checkout.php'));

            $enable = $manager->planEnable('Checkout');
            self::assertFalse($enable->hasConflicts());
            self::assertFileDoesNotExist($project->path('Config/Checkout.php'));
            self::assertTrue($manager->apply($enable)->complete());
            self::assertSame('enabled', $manager->inspect('Checkout')['descriptor']->status());
            self::assertSame(3, count($manager->inspect('Checkout')['owned_files']));
            self::assertFileExists($project->path('Project/Routes/Checkout.php'));
            self::assertFileExists($project->path('Config/Checkout.php'));
            self::assertFileExists($project->path('public/assets/Kits/Checkout/checkout.css'));

            self::assertTrue($manager->apply($manager->planDisable('Checkout'))->complete());
            self::assertSame('disabled', $manager->inspect('Checkout')['descriptor']->status());
            self::assertFileExists($project->path('Project/Routes/Checkout.php'));
            self::assertFileExists($project->path('public/assets/Kits/Checkout/checkout.css'));

            self::assertTrue($manager->apply($manager->planRemove('Checkout'))->complete());
            self::assertDirectoryDoesNotExist($project->path('Project/Kits/Checkout'));
            self::assertFileDoesNotExist($project->path('Project/Routes/Checkout.php'));
            self::assertFileDoesNotExist($project->path('Config/Checkout.php'));
            self::assertFileDoesNotExist($project->path('public/assets/Kits/Checkout/checkout.css'));
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testDisabledUpgradeDefersPublicationUntilReviewedEnable(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $mapping = [['source' => 'Templates/Routes/Store.php', 'target' => 'Project/Routes/Store.php']];
            $this->writeKit($sources, 'Store', '1.0.0', $mapping);
            $sources->write('Store/Templates/Routes/Store.php', '<?php // v1');
            $manager = $this->manager($project);
            self::assertTrue($manager->apply($manager->planInstall($sources->path('Store')))->complete());
            self::assertTrue($manager->apply($manager->planEnable('Store'))->complete());
            self::assertTrue($manager->apply($manager->planDisable('Store'))->complete());

            $this->writeKit($sources, 'Store', '2.0.0', $mapping);
            $sources->write('Store/Templates/Routes/Store.php', '<?php // v2');
            $upgrade = $manager->planUpgrade('Store', $sources->path('Store'));
            self::assertFalse($upgrade->hasConflicts());
            self::assertTrue($manager->apply($upgrade)->complete());
            self::assertSame('<?php // v1', file_get_contents($project->path('Project/Routes/Store.php')));
            self::assertSame('2.0.0', $manager->inspect('Store')['descriptor']->version());

            $enable = $manager->planEnable('Store');
            self::assertFalse($enable->hasConflicts());
            self::assertTrue($manager->apply($enable)->complete());
            self::assertSame('<?php // v2', file_get_contents($project->path('Project/Routes/Store.php')));
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testModifiedOwnedOutputAndMigrationHistoryBlockDestructiveChanges(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $this->writeKit($sources, 'Catalog', '1.0.0', [
                ['source' => 'Templates/Routes/Catalog.php', 'target' => 'Project/Routes/Catalog.php'],
                ['source' => 'Templates/Migrations/CreateCatalog.php',
                    'target' => 'Database/Migrations/CreateCatalog.php'],
            ]);
            $sources->write('Catalog/Templates/Routes/Catalog.php', '<?php // v1');
            $sources->write('Catalog/Templates/Migrations/CreateCatalog.php', '<?php return 1;');
            $manager = $this->manager($project);
            $manager->apply($manager->planInstall($sources->path('Catalog')));
            $manager->apply($manager->planEnable('Catalog'));

            $remove = $manager->planRemove('Catalog');
            self::assertTrue($remove->hasConflicts(), 'Kit-owned Migration deletion is blocked without running it.');
            $project->write('Project/Routes/Catalog.php', '<?php // application edit');
            $inspection = $manager->inspect('Catalog');
            self::assertTrue($inspection['owned_files'][1]['modified'] || $inspection['owned_files'][0]['modified']);
            self::assertTrue($manager->planRemove('Catalog')->hasConflicts());
            self::assertFileExists($project->path('Database/Migrations/CreateCatalog.php'));
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testStalePlanRejectsChangedTargetBeforeAnyPublication(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $this->writeKit($sources, 'Portal', '1.0.0', [
                ['source' => 'Templates/Routes/Portal.php', 'target' => 'Project/Routes/Portal.php'],
            ]);
            $sources->write('Portal/Templates/Routes/Portal.php', '<?php // Kit output');
            $manager = $this->manager($project);
            $manager->apply($manager->planInstall($sources->path('Portal')));
            $plan = $manager->planEnable('Portal');
            $project->write('Project/Routes/Portal.php', '<?php // existing app output');
            try {
                $manager->apply($plan);
                self::fail('A stale Kit plan must not overwrite application code.');
            } catch (KitException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertSame('<?php // existing app output',
                file_get_contents($project->path('Project/Routes/Portal.php')));
            self::assertSame('disabled', $manager->inspect('Portal')['descriptor']->status());
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testDisabledUpgradeRemovesObsoleteOwnedOutputOnlyAtEnable(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $mapping = [['source' => 'Templates/Routes/Old.php', 'target' => 'Project/Routes/Old.php']];
            $this->writeKit($sources, 'Refactor', '1.0.0', $mapping);
            $sources->write('Refactor/Templates/Routes/Old.php', '<?php // old route');
            $manager = $this->manager($project);
            $manager->apply($manager->planInstall($sources->path('Refactor')));
            $manager->apply($manager->planEnable('Refactor'));
            $manager->apply($manager->planDisable('Refactor'));

            $this->writeKit($sources, 'Refactor', '2.0.0');
            $manager->apply($manager->planUpgrade('Refactor', $sources->path('Refactor')));
            self::assertFileExists($project->path('Project/Routes/Old.php'));
            self::assertCount(1, $manager->inspect('Refactor')['owned_files']);
            $enable = $manager->planEnable('Refactor');
            self::assertFalse($enable->hasConflicts());
            self::assertTrue($manager->apply($enable)->complete());
            self::assertFileDoesNotExist($project->path('Project/Routes/Old.php'));
            self::assertSame([], $manager->inspect('Refactor')['owned_files']);
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testDisabledUpgradePreservesModifiedObsoleteFileAndBlocksReenable(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $mapping = [['source' => 'Templates/Routes/Owned.php', 'target' => 'Project/Routes/Owned.php']];
            $this->writeKit($sources, 'Ownership', '1.0.0', $mapping);
            $sources->write('Ownership/Templates/Routes/Owned.php', '<?php // initial');
            $manager = $this->manager($project);
            $manager->apply($manager->planInstall($sources->path('Ownership')));
            $manager->apply($manager->planEnable('Ownership'));
            $manager->apply($manager->planDisable('Ownership'));
            $project->write('Project/Routes/Owned.php', '<?php // application edit');
            $this->writeKit($sources, 'Ownership', '2.0.0');
            $manager->apply($manager->planUpgrade('Ownership', $sources->path('Ownership')));

            self::assertTrue($manager->planEnable('Ownership')->hasConflicts());
            self::assertCount(1, $manager->inspect('Ownership')['owned_files']);
            self::assertSame('<?php // application edit',
                file_get_contents($project->path('Project/Routes/Owned.php')));
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testDeclaredHooksRunOnlyDuringApplyAndNeverDuringStaticInspection(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $marker = $project->path('KitHooks.txt');
            $sources->write('Hooked/kit.json', json_encode([
                'format' => 1, 'name' => 'Hooked', 'version' => '1.0.0',
                'hooks' => ['beforeEnable', 'afterEnable'],
            ], JSON_THROW_ON_ERROR));
            $sources->write('Hooked/Hooked.php',
                '<?php namespace Project\\Kits\\Hooked; '
                . 'use App\\Plugins\\Kit; use App\\Plugins\\KitContext; '
                . 'final class Hooked extends Kit {'
                . ' public function beforeEnable(KitContext $context): void {'
                . ' file_put_contents(' . var_export($marker, true) . ', "before:" . $context->name . "\\n", FILE_APPEND); }'
                . ' public function afterEnable(KitContext $context): void {'
                . ' file_put_contents(' . var_export($marker, true) . ', "after:" . $context->name . "\\n", FILE_APPEND); }'
                . ' }');
            $manager = $this->manager($project);
            self::assertSame([], $manager->list());
            $manager->planInstall($sources->path('Hooked'));
            self::assertFileDoesNotExist($marker);
            $manager->apply($manager->planInstall($sources->path('Hooked')));
            $manager->inspect('Hooked');
            $enable = $manager->planEnable('Hooked');
            self::assertFileDoesNotExist($marker);
            self::assertFalse($enable->hasConflicts());
            self::assertTrue($manager->apply($enable)->complete());
            self::assertSame("before:Hooked\nafter:Hooked\n", file_get_contents($marker));
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testBrokenEnabledKitCanBeDisabledWithoutLoadingEntry(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $this->writeKit($sources, 'Recovery', '1.0.0');
            $manager = $this->manager($project);
            $manager->apply($manager->planInstall($sources->path('Recovery')));
            $manager->apply($manager->planEnable('Recovery'));
            $project->write('Project/Kits/Recovery/kit.json', '{broken');
            self::assertSame('broken', $manager->inspect('Recovery')['descriptor']->status());
            $disable = $manager->planDisable('Recovery');
            self::assertFalse($disable->hasConflicts());
            self::assertTrue($manager->apply($disable)->complete());
            self::assertSame('broken', $manager->inspect('Recovery')['descriptor']->status());
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testEnabledKitWithModifiedOutputIsBrokenAndCannotReportEnableSuccess(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $this->writeKit($sources, 'Drift', '1.0.0', [
                ['source' => 'Templates/Routes/Drift.php', 'target' => 'Project/Routes/Drift.php'],
            ]);
            $sources->write('Drift/Templates/Routes/Drift.php', '<?php // Kit version');
            $manager = $this->manager($project);
            $manager->apply($manager->planInstall($sources->path('Drift')));
            $manager->apply($manager->planEnable('Drift'));
            $project->write('Project/Routes/Drift.php', '<?php // application edit');

            self::assertSame('broken', $manager->inspect('Drift')['descriptor']->status());
            self::assertTrue($manager->inspect('Drift')['owned_files'][0]['modified']);
            self::assertTrue($manager->planEnable('Drift')->hasConflicts());
            self::assertTrue($manager->apply($manager->planDisable('Drift'))->complete());
            self::assertSame('disabled', $manager->inspect('Drift')['descriptor']->status());
            self::assertTrue($manager->planEnable('Drift')->hasConflicts(),
                'Disabled Kits still protect modified application-owned files on re-enable.');
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testEnabledKitWithDisabledRequiredPackageIsBroken(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $sources->write('Payments/Payments.php',
                '<?php namespace Packages\\Payments; final class Payments extends \\App\\Plugins\\ServiceProvider {}');
            $sources->write('Payments/composer.json', '{"name":"example/payments","version":"1.0.0"}');
            $this->writeKit($sources, 'Commerce', '1.0.0');
            $sources->write('Commerce/kit.json', json_encode([
                'format' => 1, 'name' => 'Commerce', 'version' => '1.0.0',
                'requires' => ['Payments'],
            ], JSON_THROW_ON_ERROR));
            $app = new Application($project->path());
            $packages = $app->container()->make(PackageManager::class);
            $manager = $app->container()->make(KitManager::class);
            $packages->apply($packages->planInstall($sources->path('Payments')));
            $packages->apply($packages->planEnable('Payments'));
            $manager->apply($manager->planInstall($sources->path('Commerce')));
            $manager->apply($manager->planEnable('Commerce'));
            self::assertSame('enabled', $manager->inspect('Commerce')['descriptor']->status());

            // Simulate an external state edit that bypassed PackageManager's
            // dependent protection; Kit inspection must still expose drift.
            $path = $project->path('Project/Activation.json');
            // Keep canonical empty maps as JSON objects while simulating an
            // external edit; associative decoding would turn them into [].
            $state = json_decode((string) file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
            $state->packages->Payments->enabled = false;
            file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            self::assertSame('broken', $manager->inspect('Commerce')['descriptor']->status());
            self::assertTrue($manager->planEnable('Commerce')->hasConflicts());
            self::assertTrue($manager->apply($manager->planDisable('Commerce'))->complete());
            self::assertSame('disabled', $manager->inspect('Commerce')['descriptor']->status());
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testLegacyKitStateIsReadWithoutWritingAndImportedOnExplicitDisable(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Kits/Legacy/kit.json',
                '{"format":1,"name":"Legacy","version":"1.0.0"}');
            $project->write('Project/Kits/Legacy/Legacy.php',
                '<?php namespace Project\\Kits\\Legacy; final class Legacy extends \\App\\Plugins\\Kit {}');
            $legacy = json_encode(['version' => 1, 'kits' => [
                'Legacy' => [
                    'enabled' => true,
                    'source_kind' => 'manual',
                    'source' => 'manual',
                    'definition' => (object) [],
                    'published' => (object) [],
                    'requires' => [],
                ],
            ]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $project->write('Project/Kits/State.json', $legacy);

            $manager = $this->manager($project);
            self::assertSame('enabled', $manager->inspect('Legacy')['descriptor']->status());
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            $plan = $manager->planDisable('Legacy');
            self::assertArrayHasKey('Project/Activation.json', $plan->preconditions);
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            self::assertTrue($manager->apply($plan)->complete());
            $state = json_decode((string) file_get_contents($project->path('Project/Activation.json')),
                true, 32, JSON_THROW_ON_ERROR);
            self::assertFalse($state['kits']['Legacy']['enabled']);
            self::assertSame($legacy, file_get_contents($project->path('Project/Kits/State.json')));
        } finally {
            $project->remove();
        }
    }

    public function testBeforeAndAfterRemoveHooksRunFromReviewedSourceWithoutBootingKit(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $marker = $project->path('RemovalHooks.txt');
            $sources->write('Removal/kit.json', json_encode([
                'format' => 1, 'name' => 'Removal', 'version' => '1.0.0',
                'hooks' => ['beforeRemove', 'afterRemove'],
            ], JSON_THROW_ON_ERROR));
            $sources->write('Removal/Removal.php',
                '<?php namespace Project\\Kits\\Removal; '
                . 'use App\\Plugins\\Kit; use App\\Plugins\\KitContext; '
                . 'final class Removal extends Kit {'
                . ' public function beforeRemove(KitContext $context): void {'
                . ' file_put_contents(' . var_export($marker, true) . ', "before\\n", FILE_APPEND); }'
                . ' public function afterRemove(KitContext $context): void {'
                . ' file_put_contents(' . var_export($marker, true) . ', "after\\n", FILE_APPEND); }'
                . ' }');
            $manager = $this->manager($project);
            $manager->apply($manager->planInstall($sources->path('Removal')));
            self::assertFileDoesNotExist($marker);
            $manager->list();
            $manager->planRemove('Removal');
            self::assertFileDoesNotExist($marker);
            self::assertTrue($manager->apply($manager->planRemove('Removal'))->complete());
            self::assertSame("before\nafter\n", file_get_contents($marker));
            self::assertDirectoryDoesNotExist($project->path('Project/Kits/Removal'));
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    /** @param list<array{source:string,target:string}> $files */
    private function writeKit(TemporaryProject $sources, string $name, string $version,
        array $files = []): void
    {
        $sources->write($name . '/kit.json', json_encode([
            'format' => 1, 'name' => $name, 'version' => $version, 'files' => $files,
        ], JSON_THROW_ON_ERROR));
        $sources->write($name . '/' . $name . '.php',
            '<?php namespace Project\\Kits\\' . $name . '; '
            . 'final class ' . $name . ' extends \\App\\Plugins\\Kit {}');
    }

    private function manager(TemporaryProject $project): KitManager
    {
        return (new Application($project->path()))->container()->make(KitManager::class);
    }
}
