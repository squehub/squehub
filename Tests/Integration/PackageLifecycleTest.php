<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Core\View;
use App\Clis\PackageRemoval;
use App\Packages\PackageException;
use App\Packages\PackageFiles;
use App\Packages\PackageManager;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Package discovery, activation, and dependency behavior in isolated roots. */
final class PackageLifecycleTest extends TestCase
{
    private ?PackageManager $previousViewManager = null;
    private ?string $previousViewRoot = null;
    private array $previousViewPaths = [];

    protected function setUp(): void
    {
        $this->previousViewManager = (new \ReflectionProperty(View::class, 'packageManager'))->getValue();
        $this->previousViewRoot = (new \ReflectionProperty(View::class, 'applicationRoot'))->getValue();
        $this->previousViewPaths = (new \ReflectionProperty(View::class, 'viewPaths'))->getValue();
    }

    protected function tearDown(): void
    {
        View::setPackageManager($this->previousViewManager, $this->previousViewRoot);
        (new \ReflectionProperty(View::class, 'viewPaths'))->setValue($this->previousViewPaths);
    }

    public function testManualImportRemainsDisabledUntilExplicitlyEnabledAndStatePersists(): void
    {
        $project = new TemporaryProject();
        $marker = $project->path('marker.txt');
        try {
            $this->entry($project, 'Nimbus', $marker);
            $packages = new PackageManager(new Application($project->path()));
            $discovered = $packages->list();

            self::assertCount(1, $discovered);
            self::assertSame('Nimbus', $discovered[0]->name());
            self::assertSame('disabled', $discovered[0]->status());
            self::assertSame([], $discovered[0]->errors());
            self::assertFileDoesNotExist($marker, 'Static discovery included Package PHP.');

            $plan = $packages->planEnable('Nimbus');
            self::assertSame('package:enable', $plan->operation);
            self::assertSame('Nimbus', $plan->target);
            self::assertSame([], $plan->conflicts);
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            self::assertFileDoesNotExist($marker, 'Planning included Package PHP.');

            $packages->apply($plan);
            self::assertFileExists($project->path('Project/Activation.json'));
            self::assertFileDoesNotExist($marker, 'Enabling should not boot Package PHP.');

            $fresh = new PackageManager(new Application($project->path()));
            self::assertTrue($fresh->isEnabled('Nimbus'));
            self::assertSame('enabled', $fresh->list()[0]->status());

            $app = new Application($project->path());
            $app->bootstrap();
            self::assertSame("included:Nimbus\nregister:Nimbus\nboot:Nimbus\n", file_get_contents($marker));
            $app->bootstrap();
            self::assertSame("included:Nimbus\nregister:Nimbus\nboot:Nimbus\n", file_get_contents($marker));

            $disable = $fresh->planDisable('Nimbus');
            self::assertSame([], $disable->conflicts);
            $fresh->apply($disable);
            $disabledApp = new Application($project->path());
            $disabledApp->bootstrap();
            self::assertSame("included:Nimbus\nregister:Nimbus\nboot:Nimbus\n", file_get_contents($marker));
        } finally {
            $project->remove();
        }
    }

    public function testDependenciesRegisterAndBootBeforeDependents(): void
    {
        $project = new TemporaryProject();
        $marker = $project->path('order.txt');
        try {
            $this->entry($project, 'Ledger', $marker);
            $this->entry($project, 'Commerce', $marker, ['Ledger']);
            $packages = new PackageManager(new Application($project->path()));

            $reviewed = $packages->planEnable('Commerce');
            self::assertSame([], $reviewed->conflicts);
            self::assertSame(['Ledger', 'Commerce'], array_map(
                static fn ($action): string => $action->owner->name, $reviewed->actions));
            self::assertSame(['Project/Activation.json', 'Project/Activation.json'], array_map(
                static fn ($action): string => $action->subject, $reviewed->actions));
            self::assertSame($reviewed->actions[0]->after, $reviewed->actions[1]->after);
            self::assertFalse($packages->isEnabled('Ledger'), 'Preview must not enable dependencies.');
            self::assertTrue($packages->apply($reviewed)->complete());
            self::assertSame(['Ledger', 'Commerce'],
                array_map(static fn ($descriptor): string => $descriptor->name(), $packages->active()));

            $disable = $packages->planDisable('Ledger');
            self::assertNotEmpty($disable->conflicts, 'An enabled dependent must block disable.');
            self::assertTrue($packages->isEnabled('Ledger'));
            self::assertTrue($packages->isEnabled('Commerce'));

            (new Application($project->path()))->bootstrap();
            $events = array_values(array_filter(
                explode("\n", (string) file_get_contents($marker)),
                static fn (string $event): bool => $event !== '' && !str_starts_with($event, 'included:')
            ));
            self::assertSame(['register:Ledger', 'register:Commerce', 'boot:Ledger', 'boot:Commerce'], $events);
        } finally {
            $project->remove();
        }
    }

    public function testReviewedDiamondEnableCommitsAllPackageFlagsInOneRegistryWrite(): void
    {
        $project = new TemporaryProject();
        $marker = $project->path('diamond-order.txt');
        try {
            $this->entry($project, 'DiamondCore', $marker);
            $this->entry($project, 'DiamondFinance', $marker, ['DiamondCore']);
            $this->entry($project, 'DiamondShipping', $marker, ['DiamondCore']);
            $this->entry($project, 'DiamondCommerce', $marker,
                ['DiamondShipping', 'DiamondFinance']);
            $packages = new PackageManager(new Application($project->path()));

            $plan = $packages->planEnable('DiamondCommerce');
            self::assertSame([], $plan->conflicts);
            self::assertSame(['DiamondCore', 'DiamondFinance', 'DiamondShipping', 'DiamondCommerce'],
                array_map(static fn ($action): string => $action->owner->name, $plan->actions));
            self::assertCount(1, array_unique(array_map(
                static fn ($action): ?string => $action->after, $plan->actions)));
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            self::assertTrue($packages->apply($plan)->complete());

            $state = json_decode((string) file_get_contents($project->path('Project/Activation.json')),
                true, 64, JSON_THROW_ON_ERROR);
            self::assertSame(['DiamondCommerce', 'DiamondCore', 'DiamondFinance', 'DiamondShipping'],
                array_keys($state['packages']));
            foreach ($state['packages'] as $record) { self::assertTrue($record['enabled']); }
            self::assertSame([], $state['kits']);
            self::assertFileDoesNotExist($project->path('Project/Packages/State.json'));
            self::assertFileDoesNotExist($project->path('Project/Kits/State.json'));

            (new Application($project->path()))->bootstrap();
            $events = array_values(array_filter(explode("\n", (string) file_get_contents($marker)),
                static fn (string $event): bool => $event !== '' && !str_starts_with($event, 'included:')));
            self::assertSame([
                'register:DiamondCore', 'register:DiamondFinance',
                'register:DiamondShipping', 'register:DiamondCommerce',
                'boot:DiamondCore', 'boot:DiamondFinance',
                'boot:DiamondShipping', 'boot:DiamondCommerce',
            ], $events);
        } finally {
            $project->remove();
        }
    }

    public function testBrokenAndWrongCasePackagesAreReportedWithoutExecution(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Packages/Broken/readme.txt', 'missing entry');
            $project->write('Project/Packages/lowercase/lowercase.php',
                '<?php throw new \\RuntimeException("This must not execute.");');
            $packages = new PackageManager(new Application($project->path()));
            $byName = [];
            foreach ($packages->list() as $descriptor) {
                $byName[$descriptor->name()] = $descriptor;
            }
            self::assertSame('broken', $byName['Broken']->status());
            self::assertNotEmpty($byName['Broken']->errors());
            self::assertSame('broken', $byName['lowercase']->status());
            self::assertNotEmpty($byName['lowercase']->errors());
        } finally {
            $project->remove();
        }
    }

    public function testBrokenEnabledPackageStopsNormalBootButRemainsInspectable(): void
    {
        $project = new TemporaryProject();
        $marker = $project->path('broken-entry-executed.txt');
        try {
            $this->entry($project, 'NeedsRepair', $marker);
            $manager = new PackageManager(new Application($project->path()));
            $manager->apply($manager->planEnable('NeedsRepair'));

            // Damage the entry after activation was recorded. Static inspection
            // must report it, and normal boot must never execute this PHP.
            $project->write('Project/Packages/NeedsRepair/NeedsRepair.php',
                '<?php file_put_contents(' . var_export($marker, true) . ', "executed");');
            self::assertSame('broken', $manager->list()[0]->status());

            $app = new Application($project->path());
            try {
                $app->bootstrap();
                self::fail('A broken enabled Package must stop normal boot.');
            } catch (PackageException $exception) {
                self::assertStringContainsString('package:list', $exception->getMessage());
                self::assertFalse($app->isBooted());
            }
            self::assertFileDoesNotExist($marker);

            $inspector = new Application($project->path());
            $inspector->inspectPackagesOnly();
            $inspector->bootstrap();
            self::assertTrue($inspector->isBooted());
            self::assertSame('broken', $inspector->container()->make(PackageManager::class)->list()[0]->status());
            self::assertFileDoesNotExist($marker);
        } finally {
            $project->remove();
        }
    }

    public function testMissingRequirementSelfDependencyAndCycleAreBrokenWithoutBoot(): void
    {
        $project = new TemporaryProject();
        $marker = $project->path('cycle-log.txt');
        try {
            $this->entry($project, 'MissingDeps', $marker, ['Absent']);
            $this->entry($project, 'Selfish', $marker, ['Selfish']);
            $this->entry($project, 'CycleA', $marker, ['CycleB']);
            $this->entry($project, 'CycleB', $marker, ['CycleC']);
            $this->entry($project, 'CycleC', $marker, ['CycleA']);
            $packages = new PackageManager(new Application($project->path()));
            $statuses = [];
            foreach ($packages->list() as $descriptor) {
                $statuses[$descriptor->name()] = $descriptor;
            }
            foreach (['MissingDeps', 'Selfish', 'CycleA', 'CycleB', 'CycleC'] as $name) {
                self::assertSame('broken', $statuses[$name]->status(), $name);
                self::assertNotEmpty($statuses[$name]->errors(), $name);
                self::assertNotEmpty($packages->planEnable($name)->conflicts, $name);
            }
            self::assertSame([], $packages->active());
            self::assertFileDoesNotExist($marker, 'Dependency validation must not execute Package code.');
        } finally {
            $project->remove();
        }
    }

    public function testSharedDependencyCannotBeDisabledUntilAllDependentsAreDisabled(): void
    {
        $project = new TemporaryProject();
        $marker = $project->path('shared-log.txt');
        try {
            $this->entry($project, 'SharedCore', $marker);
            $this->entry($project, 'FirstFeature', $marker, ['SharedCore']);
            $this->entry($project, 'SecondFeature', $marker, ['SharedCore']);
            $packages = new PackageManager(new Application($project->path()));
            foreach (['SharedCore', 'FirstFeature', 'SecondFeature'] as $name) {
                $packages->apply($packages->planEnable($name));
            }
            self::assertCount(2, $packages->planDisable('SharedCore')->conflicts);
            $packages->apply($packages->planDisable('FirstFeature'));
            self::assertCount(1, $packages->planDisable('SharedCore')->conflicts);
            $packages->apply($packages->planDisable('SecondFeature'));
            self::assertSame([], $packages->planDisable('SharedCore')->conflicts);
            $packages->apply($packages->planDisable('SharedCore'));
            self::assertSame([], $packages->active());
        } finally {
            $project->remove();
        }
    }

    public function testLinkedPackageRootIsReportedBrokenWithoutFollowingIt(): void
    {
        $project = new TemporaryProject();
        $link = $project->path('Project/Packages/Linked');
        try {
            $project->write('Outside/Linked.php',
                '<?php throw new \\RuntimeException("linked source executed");');
            mkdir($project->path('Project/Packages'), 0777, true);
            if (!@symlink($project->path('Outside'), $link)) {
                self::markTestSkipped('This host cannot create a directory symlink.');
            }
            $packages = new PackageManager(new Application($project->path()));
            $descriptors = $packages->list();
            self::assertCount(1, $descriptors);
            self::assertSame('Linked', $descriptors[0]->name());
            self::assertSame('broken', $descriptors[0]->status());
            self::assertNotEmpty($descriptors[0]->errors());
            self::assertFileExists($project->path('Outside/Linked.php'));
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            $project->remove();
        }
    }

    public function testLocalInstallUpgradeAndRemoveProtectModifiedOwnedFiles(): void
    {
        $project = new TemporaryProject();
        $sourceRoot = new TemporaryProject();
        try {
            $source = $sourceRoot->path('Managed');
            $sourceRoot->write('Managed/Managed.php',
                '<?php namespace Packages\\Managed; final class Managed extends \\App\\Plugins\\ServiceProvider {}');
            $sourceRoot->write('Managed/composer.json',
                json_encode(['name' => 'example/managed', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
            $packages = new PackageManager(new Application($project->path()));

            $install = $packages->planInstall($source);
            self::assertSame('package:install', $install->operation);
            self::assertSame([], $install->conflicts);
            self::assertDirectoryDoesNotExist($project->path('Project/Packages/Managed'));
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            $packages->apply($install);

            $target = $project->path('Project/Packages/Managed/Managed.php');
            self::assertFileExists($target);
            self::assertFalse($packages->isEnabled('Managed'), 'Installation must leave Package disabled.');
            self::assertSame('1.0.0', $packages->list()[0]->version());
            $state = json_decode((string) file_get_contents($project->path('Project/Activation.json')),
                true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('local', $state['packages']['Managed']['source_kind']);
            self::assertMatchesRegularExpression('/\Alocal:Managed#[a-f0-9]{12}\z/',
                $state['packages']['Managed']['source']);
            self::assertStringNotContainsString($source, $state['packages']['Managed']['source']);
            self::assertNotEmpty($packages->planInstall($source)->conflicts,
                'A second installation must report a collision.');
            $original = (string) file_get_contents($target);

            file_put_contents($target, $original . "\n// user edit\n");
            $removeConflict = $packages->planRemove('Managed');
            self::assertNotEmpty($removeConflict->conflicts);
            $sourceRoot->write('Managed/composer.json',
                json_encode(['name' => 'example/managed', 'version' => '1.1.0'], JSON_THROW_ON_ERROR));
            $sourceRoot->write('Managed/readme.txt', 'new version');
            $upgradeConflict = $packages->planUpgrade('Managed', $source);
            self::assertNotEmpty($upgradeConflict->conflicts);
            try {
                $packages->apply($upgradeConflict);
                self::fail('Upgrade must not overwrite a modified Package file.');
            } catch (PackageException) {
                self::assertStringContainsString('user edit', (string) file_get_contents($target));
            }

            file_put_contents($target, $original);
            $upgrade = $packages->planUpgrade('Managed', $source);
            self::assertSame([], $upgrade->conflicts);
            $upgradeResult = $packages->apply($upgrade);
            self::assertTrue($upgradeResult->complete(),
                'Upgrade result failed verification at ' . ($upgradeResult->failed?->subject ?? 'cleanup') . '.');
            self::assertSame('1.1.0', $packages->list()[0]->version());
            self::assertFileExists($project->path('Project/Packages/Managed/readme.txt'));
            self::assertFalse($packages->isEnabled('Managed'));

            $custom = $project->path('Project/Packages/Managed/custom.txt');
            file_put_contents($custom, 'application customization');
            self::assertNotEmpty($packages->planRemove('Managed')->conflicts,
                'An untracked Package file must block destructive removal.');
            unlink($custom);
            $customDirectory = $project->path('Project/Packages/Managed/CustomEmpty');
            mkdir($customDirectory);
            self::assertNotEmpty($packages->planRemove('Managed')->conflicts,
                'An untracked empty directory must block destructive removal.');
            self::assertNotEmpty($packages->planUpgrade('Managed', $source)->conflicts,
                'An untracked empty directory must block upgrade replacement.');
            rmdir($customDirectory);

            $remove = $packages->planRemove('Managed');
            self::assertSame([], $remove->conflicts);
            $packages->apply($remove);
            self::assertDirectoryDoesNotExist($project->path('Project/Packages/Managed'));
        } finally {
            $sourceRoot->remove();
            $project->remove();
        }
    }

    public function testInvalidAndStaleLocalInstallPlansLeaveNoPartialPackage(): void
    {
        $project = new TemporaryProject();
        $sourceRoot = new TemporaryProject();
        try {
            $sourceRoot->write('InvalidSource/InvalidSource.php',
                '<?php throw new \\RuntimeException("Source inspection executed PHP.");');
            $packages = new PackageManager(new Application($project->path()));
            $invalid = $packages->planInstall($sourceRoot->path('InvalidSource'));
            self::assertNotEmpty($invalid->conflicts);
            try {
                $packages->apply($invalid);
                self::fail('An invalid source must not install.');
            } catch (PackageException) {
                self::assertDirectoryDoesNotExist($project->path('Project/Packages/InvalidSource'));
            }

            $sourceRoot->write('StaleSource/StaleSource.php',
                '<?php namespace Packages\\StaleSource; final class StaleSource extends \\App\\Plugins\\ServiceProvider {}');
            $source = $sourceRoot->path('StaleSource');
            $plan = $packages->planInstall($source);
            self::assertSame([], $plan->conflicts);
            $sourceRoot->write('StaleSource/new.txt', 'changed after preview');
            try {
                $packages->apply($plan);
                self::fail('A stale source plan must be re-inspected.');
            } catch (PackageException) {
                self::assertDirectoryDoesNotExist($project->path('Project/Packages/StaleSource'));
                self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            }
        } finally {
            $sourceRoot->remove();
            $project->remove();
        }
    }

    public function testPackageCopyRejectsBytesChangedAfterReview(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Source/Source.php',
                '<?php namespace Packages\\Source; final class Source extends \\App\\Plugins\\ServiceProvider {}');
            $source = $project->path('Source');
            $reviewed = PackageFiles::fingerprints($source, true);
            $project->write('Source/Source.php', '<?php throw new \\RuntimeException("changed");');

            $destination = $project->path('Staged');
            $this->expectException(PackageException::class);
            try {
                PackageFiles::copy($source, $destination, $reviewed);
            } finally {
                self::assertDirectoryDoesNotExist($destination);
            }
        } finally {
            $project->remove();
        }
    }

    public function testOwnershipRecheckDetectsEditsInRenamedPackageTree(): void
    {
        $project = new TemporaryProject();
        $sourceRoot = new TemporaryProject();
        try {
            $sourceRoot->write('Owned/Owned.php',
                '<?php namespace Packages\\Owned; final class Owned extends \\App\\Plugins\\ServiceProvider {}');
            $manager = new PackageManager(new Application($project->path()));
            $manager->apply($manager->planInstall($sourceRoot->path('Owned')));
            $descriptor = $manager->list()[0];
            $target = $project->path('Project/Packages/Owned');
            $backup = $project->path('Project/Packages/.Owned.backup-probe');
            self::assertTrue(rename($target, $backup));

            // Removal and upgrade inspect the renamed tree before deleting
            // owned files. Changes outside the lifecycle lock remain visible.
            $check = new \ReflectionMethod(PackageManager::class, 'ownershipConflicts');
            self::assertSame([], $check->invoke($manager, $descriptor, $backup));
            file_put_contents($backup . '/Owned.php', "\n// edited outside CLI\n", FILE_APPEND);
            self::assertNotEmpty($check->invoke($manager, $descriptor, $backup));
        } finally {
            $sourceRoot->remove();
            $project->remove();
        }
    }

    public function testCredentialBearingGitSourcesAreRejectedBeforeNetworkOrWrites(): void
    {
        $project = new TemporaryProject();
        try {
            $packages = new PackageManager(new Application($project->path()));
            foreach ([
                'https://user:secret@example.test/vendor/Weather.git',
                'https://example.test/vendor/Weather.git?token=secret',
                'https://example.test/vendor/Weather.git#branch',
                'http://example.test/vendor/Weather.git',
            ] as $source) {
                try {
                    $packages->planInstall($source);
                    self::fail('Unsafe source URL was accepted.');
                } catch (PackageException) {
                    self::assertDirectoryDoesNotExist($project->path('Project/Packages/Weather'));
                    self::assertFileDoesNotExist($project->path('Project/Activation.json'));
                }
            }
        } finally {
            $project->remove();
        }
    }

    public function testArchiveFileUrlsAndLowercaseRepositoryIdentityAreNotInstallSources(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $sources->write('Media.zip', 'not a Package directory');
            $packages = new PackageManager(new Application($project->path()));
            foreach ([
                $sources->path('Media.zip'),
                'file:///Media',
                'https://example.test/Media.zip',
                'https://github.com/squehub/media/releases/download/v1.0.0/squehub-media-1.0.0.zip',
                'https://github.com/squehub/media',
            ] as $source) {
                try {
                    $packages->planInstall($source);
                    self::fail('Unsupported source form was accepted: ' . $source);
                } catch (PackageException) {
                    self::assertDirectoryDoesNotExist($project->path('Project/Packages/Media'));
                }
            }
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testLinkedLocalSourceIsRejectedBeforePreviewWrites(): void
    {
        $project = new TemporaryProject();
        $sourceRoot = new TemporaryProject();
        $link = $project->path('LinkedSource');
        try {
            $sourceRoot->write('LinkedSource/LinkedSource.php',
                '<?php namespace Packages\\LinkedSource; final class LinkedSource extends \\App\\Plugins\\ServiceProvider {}');
            if (!@symlink($sourceRoot->path('LinkedSource'), $link)) {
                self::markTestSkipped('This host cannot create a directory symlink.');
            }
            $packages = new PackageManager(new Application($project->path()));
            try {
                $packages->planInstall($link);
                self::fail('A linked local source must be rejected.');
            } catch (PackageException) {
                self::assertDirectoryDoesNotExist($project->path('Project/Packages/LinkedSource'));
                self::assertFileDoesNotExist($project->path('Project/Activation.json'));
            }
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            $sourceRoot->remove();
            $project->remove();
        }
    }

    public function testMissingManagedPackageRootCanRemoveItsStaleStateRecord(): void
    {
        $project = new TemporaryProject();
        $sourceRoot = new TemporaryProject();
        try {
            $sourceRoot->write('Vanished/Vanished.php',
                '<?php namespace Packages\\Vanished; final class Vanished extends \\App\\Plugins\\ServiceProvider {}');
            $packages = new PackageManager(new Application($project->path()));
            $packages->apply($packages->planInstall($sourceRoot->path('Vanished')));
            PackageRemoval::remove($project->path('Project/Packages/Vanished'), $project->path());

            self::assertSame('broken', $packages->list()[0]->status());
            $cleanup = $packages->planRemove('Vanished');
            self::assertSame([], $cleanup->conflicts);
            self::assertSame(['state'], array_map(static fn ($action): string => $action->kind,
                $cleanup->actions));
            $packages->apply($cleanup);
            self::assertSame([], $packages->list());
        } finally {
            $sourceRoot->remove();
            $project->remove();
        }
    }

    public function testManualPackageCannotBeRemovedAsAnOwnedInstall(): void
    {
        $project = new TemporaryProject();
        try {
            $this->entry($project, 'Manual', $project->path('never-executed.txt'));
            $packages = new PackageManager(new Application($project->path()));
            $plan = $packages->planRemove('Manual');
            self::assertNotEmpty($plan->conflicts);
            self::assertFileExists($project->path('Project/Packages/Manual/Manual.php'));
        } finally {
            $project->remove();
        }
    }

    public function testPackageViewsAndActivationDoNotLeakBetweenApplications(): void
    {
        $first = new TemporaryProject();
        $second = new TemporaryProject();
        try {
            $this->entry($first, 'FirstOnly', $first->path('first-log.txt'));
            $this->entry($second, 'SecondOnly', $second->path('second-log.txt'));
            $first->write('Project/Packages/FirstOnly/Views/First.squehub.php', 'first');
            $second->write('Project/Packages/SecondOnly/Views/Second.squehub.php', 'second');
            $firstPackages = new PackageManager(new Application($first->path()));
            $firstPackages->apply($firstPackages->planEnable('FirstOnly'));

            (new Application($first->path()))->bootstrap();
            self::assertContains(realpath($first->path('Project/Packages/FirstOnly/Views')) . '/',
                View::getViewPaths());

            (new Application($second->path()))->bootstrap();
            self::assertNotContains(realpath($first->path('Project/Packages/FirstOnly/Views')) . '/',
                View::getViewPaths());
            self::assertNotContains(realpath($second->path('Project/Packages/SecondOnly/Views')) . '/',
                View::getViewPaths());
            self::assertFileDoesNotExist($second->path('second-log.txt'));
        } finally {
            $second->remove();
            $first->remove();
        }
    }

    public function testPackageConfigurationAddsOnlyOwnDefaultsAndPreservesApplicationValues(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Packages.php',
                '<?php return ["ConfigSafe" => ["mode" => "application"]];');
            $project->write('Project/Packages/ConfigSafe/ConfigSafe.php',
                '<?php namespace Packages\\ConfigSafe; final class ConfigSafe extends \\App\\Plugins\\ServiceProvider {'
                . 'public function register(): void {'
                . '$config = $this->app->config();'
                . 'if (!$config->has("packages.ConfigSafe.mode")) $config->set("packages.ConfigSafe.mode", "package");'
                . '$config->set("packages.ConfigSafe.feature", true);'
                . '}}');
            $manager = new PackageManager(new Application($project->path()));
            $manager->apply($manager->planEnable('ConfigSafe'));
            $app = new Application($project->path());
            $app->bootstrap();
            self::assertSame('application', $app->config()->get('packages.ConfigSafe.mode'));
            self::assertTrue($app->config()->get('packages.ConfigSafe.feature'));
        } finally {
            $project->remove();
        }

        $invalid = new TemporaryProject();
        try {
            $invalid->write('Project/Packages/ConfigBad/ConfigBad.php',
                '<?php namespace Packages\\ConfigBad; final class ConfigBad extends \\App\\Plugins\\ServiceProvider {'
                . 'public function register(): void { $this->app->config()->set("app.debug", true); }}');
            $manager = new PackageManager(new Application($invalid->path()));
            $manager->apply($manager->planEnable('ConfigBad'));
            $this->expectException(PackageException::class);
            (new Application($invalid->path()))->bootstrap();
        } finally {
            $invalid->remove();
        }
    }

    public function testOnlyEnabledPackageUtilitiesLoadBeforeBootHook(): void
    {
        $project = new TemporaryProject();
        $enabledMarker = $project->path('enabled-utils.txt');
        $disabledMarker = $project->path('disabled-utils.txt');
        try {
            $project->write('Project/Packages/UtilityEnabled/UtilityEnabled.php',
                '<?php namespace Packages\\UtilityEnabled; final class UtilityEnabled extends \\App\\Plugins\\ServiceProvider { '
                . 'public function boot(): void { \\phase13b_enabled_utility(); } }');
            $project->write('Project/Packages/UtilityEnabled/Utils/Probe.php',
                '<?php function phase13b_enabled_utility(): void { file_put_contents('
                . var_export($enabledMarker, true) . ', "used"); }');
            $project->write('Project/Packages/UtilityDisabled/UtilityDisabled.php',
                '<?php namespace Packages\\UtilityDisabled; final class UtilityDisabled extends \\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Packages/UtilityDisabled/Utils/Probe.php',
                '<?php file_put_contents(' . var_export($disabledMarker, true) . ', "executed");');

            $packages = new PackageManager(new Application($project->path()));
            $packages->apply($packages->planEnable('UtilityEnabled'));
            (new Application($project->path()))->bootstrap();

            self::assertSame('used', file_get_contents($enabledMarker));
            self::assertFileDoesNotExist($disabledMarker);
        } finally {
            $project->remove();
        }
    }

    /** @param list<string> $requires */
    private function entry(TemporaryProject $project, string $name, string $marker, array $requires = []): void
    {
        $literal = var_export($marker, true);
        $source = '<?php namespace Packages\\' . $name . '; '
            . 'file_put_contents(' . $literal . ', "included:' . $name . '\\n", FILE_APPEND); '
            . 'final class ' . $name . ' extends \\App\\Plugins\\ServiceProvider { '
            . 'public function register(): void { file_put_contents(' . $literal
            . ', "register:' . $name . '\\n", FILE_APPEND); } '
            . 'public function boot(): void { file_put_contents(' . $literal
            . ', "boot:' . $name . '\\n", FILE_APPEND); } }';
        $project->write('Project/Packages/' . $name . '/' . $name . '.php', $source);
        if ($requires !== []) {
            $project->write('Project/Packages/' . $name . '/composer.json',
                json_encode(['name' => 'example/' . strtolower($name),
                    'extra' => ['squehub' => ['requires' => $requires]]], JSON_THROW_ON_ERROR));
        }
    }
}
