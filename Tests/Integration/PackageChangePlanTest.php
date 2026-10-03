<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Activation\ActivationStore;
use App\Changes\ChangeApplyException;
use App\Changes\ChangePlan;
use App\Changes\ChangeRenderer;
use App\Foundation\Application;
use App\Packages\PackageException;
use App\Packages\PackageManager;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Shared, content-free plans retain Package ownership and reject stale apply. */
final class PackageChangePlanTest extends TestCase
{
    public function testInstallUpgradeAndRemoveUseTypedActionsWithoutExecutingPackageData(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        $marker = $project->path('package-executed.txt');
        try {
            $source = $sources->path('Reviewed');
            $sources->write('Reviewed/Reviewed.php',
                '<?php namespace Packages\\Reviewed; final class Reviewed extends \\App\\Plugins\\ServiceProvider {}');
            $sources->write('Reviewed/Migrations/Create.php',
                '<?php file_put_contents(' . var_export($marker, true) . ', "migration");');
            $sources->write('Reviewed/Seeders/Initial.php',
                '<?php file_put_contents(' . var_export($marker, true) . ', "seeder");');
            $manager = new PackageManager(new Application($project->path()));

            $install = $manager->planInstall($source);
            self::assertInstanceOf(ChangePlan::class, $install);
            self::assertSame('package:install', $install->operation);
            self::assertSame('package', $install->owner->type);
            self::assertSame('Reviewed', $install->owner->name);
            self::assertSame(['create', 'create', 'create', 'create', 'state'], $this->kinds($install));
            self::assertSame('Project/Packages/Reviewed', $install->actions[0]->subject);
            self::assertCount(2, $install->warnings);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D',
                (string) $install->preconditions['Project/Activation.json']);
            self::assertSame($install->fingerprint(), $manager->planInstall($source)->fingerprint());
            self::assertStringNotContainsString($marker, json_encode($install->toArray(), JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString($source, json_encode($install->toArray(), JSON_THROW_ON_ERROR));
            self::assertFileDoesNotExist($marker);

            $result = $manager->apply($install);
            self::assertTrue($result->complete());
            self::assertCount(5, $result->applied);
            self::assertFileDoesNotExist($marker, 'Source planning and copying must not run PHP data files.');

            $sources->write('Reviewed/Reviewed.php',
                '<?php namespace Packages\\Reviewed; final class Reviewed extends \\App\\Plugins\\ServiceProvider {} // v2');
            unlink($source . '/Migrations/Create.php');
            rmdir($source . '/Migrations');
            $sources->write('Reviewed/Services/NewService.php', '<?php namespace Packages\\Reviewed\\Services;');
            $upgrade = $manager->planUpgrade('Reviewed', $source);
            self::assertSame(['delete', 'modify', 'create', 'state'], $this->kinds($upgrade));
            self::assertSame('destructive', $upgrade->risk());
            self::assertArrayHasKey('Project/Packages/Reviewed#ownership', $upgrade->preconditions);
            self::assertTrue($manager->apply($upgrade)->complete());
            self::assertFileDoesNotExist($project->path('Project/Packages/Reviewed/Migrations/Create.php'));
            self::assertFileExists($project->path('Project/Packages/Reviewed/Services/NewService.php'));
            self::assertFileDoesNotExist($marker);

            $remove = $manager->planRemove('Reviewed');
            self::assertContains('delete', $this->kinds($remove));
            self::assertSame('destructive', $remove->risk());
            self::assertTrue($manager->apply($remove)->complete());
            self::assertDirectoryDoesNotExist($project->path('Project/Packages/Reviewed'));
            self::assertFileDoesNotExist($marker);
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testStateFileChangeAndOwnedFileEditRejectOldPlanBeforeMutation(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $project->write('Project/Packages/Manual/Manual.php',
                '<?php namespace Packages\\Manual; final class Manual extends \\App\\Plugins\\ServiceProvider {}');
            $manager = new PackageManager(new Application($project->path()));
            $enable = $manager->planEnable('Manual');
            $project->write('Project/Activation.json', json_encode([
                'format' => 1,
                'packages' => ['Intervening' => [
                    'enabled' => false, 'source_kind' => 'manual',
                    'source' => 'manual', 'files' => (object) [],
                ]],
                'kits' => (object) [],
            ], JSON_THROW_ON_ERROR) . "\n");
            try {
                $manager->apply($enable);
                self::fail('A changed Activation.json must invalidate the earlier plan.');
            } catch (PackageException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertFileDoesNotExist($project->path('Project/Activation.lock'));

            $sources->write('Owned/Owned.php',
                '<?php namespace Packages\\Owned; final class Owned extends \\App\\Plugins\\ServiceProvider {}');
            $manager->apply($manager->planInstall($sources->path('Owned')));
            $remove = $manager->planRemove('Owned');
            $target = $project->path('Project/Packages/Owned/Owned.php');
            file_put_contents($target, "\n// user edit\n", FILE_APPEND);
            $before = file_get_contents($target);
            try {
                $manager->apply($remove);
                self::fail('A changed owned file must not be removed.');
            } catch (PackageException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertSame($before, file_get_contents($target));
            self::assertFileExists($project->path('Project/Activation.json'));
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testKitStateChangeInvalidatesPendingPackageEnablePlan(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Packages/Manual/Manual.php',
                '<?php namespace Packages\\Manual; final class Manual extends \\App\\Plugins\\ServiceProvider {}');
            $app = new Application($project->path());
            $manager = new PackageManager($app);
            $plan = $manager->planEnable('Manual');

            // A Kit mutation changes the same registry fingerprint even when
            // this Package's own definition and activation record are untouched.
            $store = $app->container()->make(ActivationStore::class);
            $store->writeKits(['Configured' => [
                'enabled' => false, 'source_kind' => 'manual', 'source' => 'manual',
                'definition' => [], 'published' => [], 'requires' => [],
            ]]);
            try {
                $manager->apply($plan);
                self::fail('Cross-domain registry changes must invalidate a Package plan.');
            } catch (PackageException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertSame([], $store->packages());
            self::assertFileExists($project->path('Project/Activation.json'));
        } finally {
            $project->remove();
        }
    }

    public function testPackageEntryEditInvalidatesPendingEnableBeforeRegistryWrite(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Packages/Reviewed/Reviewed.php',
                '<?php namespace Packages\\Reviewed; final class Reviewed extends \\App\\Plugins\\ServiceProvider {}');
            $manager = new PackageManager(new Application($project->path()));
            $plan = $manager->planEnable('Reviewed');
            self::assertSame([], $plan->conflicts);
            self::assertArrayHasKey('Project/Packages/Reviewed#source', $plan->preconditions);

            file_put_contents($project->path('Project/Packages/Reviewed/Reviewed.php'),
                "\n// Changed after the reviewed preview.\n", FILE_APPEND);
            try {
                $manager->apply($plan);
                self::fail('A changed Package entry must invalidate its enable plan.');
            } catch (PackageException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertFalse($manager->isEnabled('Reviewed'));
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
        } finally {
            $project->remove();
        }
    }

    public function testSamePackageBytesAtDifferentPathsProduceSamePlanIdentityAndCannotCrossApplications(): void
    {
        $first = new TemporaryProject();
        $second = new TemporaryProject();
        $firstSource = new TemporaryProject();
        $secondSource = new TemporaryProject();
        try {
            $contents = '<?php namespace Packages\\Portable; final class Portable extends \\App\\Plugins\\ServiceProvider {}';
            $firstSource->write('Portable/Portable.php', $contents);
            $secondSource->write('Portable/Portable.php', $contents);
            $firstManager = new PackageManager(new Application($first->path()));
            $secondManager = new PackageManager(new Application($second->path()));
            $firstPlan = $firstManager->planInstall($firstSource->path('Portable'));
            $secondPlan = $secondManager->planInstall($secondSource->path('Portable'));
            self::assertSame($firstPlan->fingerprint(), $secondPlan->fingerprint());
            self::assertSame($firstPlan->toArray(), $secondPlan->toArray());
            try {
                $secondManager->apply($firstPlan);
                self::fail('An application must not apply another manager\'s private source context.');
            } catch (PackageException $exception) {
                self::assertStringContainsString('does not belong', $exception->getMessage());
            }
            self::assertDirectoryDoesNotExist($second->path('Project/Packages'));
        } finally {
            $secondSource->remove();
            $firstSource->remove();
            $second->remove();
            $first->remove();
        }
    }

    public function testAppearedAndDisappearedFilesInvalidateReviewedPackageActions(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $sources->write('Transient/Transient.php',
                '<?php namespace Packages\\Transient; final class Transient extends \\App\\Plugins\\ServiceProvider {}');
            $manager = new PackageManager(new Application($project->path()));
            $install = $manager->planInstall($sources->path('Transient'));
            $external = 'external application file';
            $project->write('Project/Packages/Transient/Transient.php', $external);
            try {
                $manager->apply($install);
                self::fail('An appeared target file must invalidate CREATE.');
            } catch (PackageException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertSame($external, file_get_contents($project->path('Project/Packages/Transient/Transient.php')));
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));

            unlink($project->path('Project/Packages/Transient/Transient.php'));
            rmdir($project->path('Project/Packages/Transient'));
            self::assertTrue($manager->apply($manager->planInstall($sources->path('Transient')))->complete());
            $remove = $manager->planRemove('Transient');
            unlink($project->path('Project/Packages/Transient/Transient.php'));
            try {
                $manager->apply($remove);
                self::fail('A disappeared owned file must invalidate DELETE.');
            } catch (PackageException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertFileExists($project->path('Project/Activation.json'));
            self::assertDirectoryExists($project->path('Project/Packages/Transient'));
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testInterruptedRemovalReportsObservedActionsAndRecoveryLocation(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $sources->write('Recoverable/Recoverable.php',
                '<?php namespace Packages\\Recoverable; final class Recoverable extends \\App\\Plugins\\ServiceProvider {}');
            $manager = new PackageManager(new Application($project->path()));
            self::assertTrue($manager->apply($manager->planInstall($sources->path('Recoverable')))->complete());
            $remove = $manager->planRemove('Recoverable');
            $target = $project->path('Project/Packages/Recoverable');
            $backup = $project->path('Project/Packages/.Recoverable.remove-aaaaaaaaaaaaaaaa');

            // Model the observable state after a rename and before the state
            // write; no arbitrary failure timing or filesystem permissions.
            self::assertTrue(rename($target, $backup));
            $observe = new \ReflectionMethod(PackageManager::class, 'observedResult');
            $result = $observe->invoke($manager, $remove,
                'Project/Packages/.Recoverable.remove-aaaaaaaaaaaaaaaa');
            self::assertFalse($result->complete());
            self::assertFalse($result->verified);
            self::assertSame('Project/Packages/.Recoverable.remove-aaaaaaaaaaaaaaaa', $result->recoveryPath);
            self::assertCount(2, $result->applied, 'Removed file and directory are absent from the public path.');
            self::assertCount(1, $result->unapplied, 'The Package state was not updated.');
            self::assertSame('state', $result->unapplied[0]->kind);
            self::assertFileExists($backup . '/Recoverable.php');
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testRegistryLockFailureLeavesRemovedTargetAndStateUntouched(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $sources->write('WriteFailure/WriteFailure.php',
                '<?php namespace Packages\\WriteFailure; final class WriteFailure extends \\App\\Plugins\\ServiceProvider {}');
            $manager = new PackageManager(new Application($project->path()));
            self::assertTrue($manager->apply($manager->planInstall($sources->path('WriteFailure')))->complete());
            $plan = $manager->planRemove('WriteFailure');
            $state = $project->path('Project/Activation.json');
            $beforeState = file_get_contents($state);
            $lock = $project->path('Project/Activation.lock');
            self::assertTrue(unlink($lock));
            self::assertTrue(mkdir($lock));

            try {
                $manager->apply($plan);
                self::fail('A blocked registry lock must stop Package removal.');
            } catch (PackageException $exception) {
                self::assertStringContainsString('registry lock', strtolower($exception->getMessage()));
            }
            self::assertSame($beforeState, file_get_contents($state));
            self::assertFileExists($project->path('Project/Packages/WriteFailure/WriteFailure.php'));
            self::assertSame([], glob($project->path('Project/Packages/.WriteFailure.remove-*')) ?: []);
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testPostWriteVerificationFailureCarriesObservedPartialResult(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $sources->write('VerifyFailure/VerifyFailure.php',
                '<?php namespace Packages\\VerifyFailure; final class VerifyFailure extends \\App\\Plugins\\ServiceProvider {}');
            $manager = new PackageManager(new Application($project->path()));
            self::assertTrue($manager->apply($manager->planInstall($sources->path('VerifyFailure')))->complete());
            $remove = $manager->planRemove('VerifyFailure');
            $target = $project->path('Project/Packages/VerifyFailure');
            $backup = $project->path('Project/Packages/.VerifyFailure.remove-bbbbbbbbbbbbbbbb');

            // A completed path rename followed by unreadable state models a
            // verification failure after a visible filesystem mutation.
            self::assertTrue(rename($target, $backup));
            file_put_contents($project->path('Project/Activation.json'), '{invalid');
            $finish = new \ReflectionMethod(PackageManager::class, 'verifyOrReport');
            try {
                $finish->invoke($manager, $remove,
                    'Project/Packages/.VerifyFailure.remove-bbbbbbbbbbbbbbbb');
                self::fail('Post-write verification must report observed partial effects.');
            } catch (ChangeApplyException $exception) {
                self::assertInstanceOf(PackageException::class, $exception->getPrevious());
                self::assertCount(2, $exception->result->applied);
                self::assertSame('state', $exception->result->failed?->kind);
                self::assertSame('Project/Packages/.VerifyFailure.remove-bbbbbbbbbbbbbbbb',
                    $exception->result->recoveryPath);
                self::assertFalse($exception->result->complete());
                self::assertFileExists($backup . '/VerifyFailure.php');
            }
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testPreviewShowsSatisfiedRequirementsAndVersionChangesWithoutOtherComposerFields(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            foreach (['Geo', 'Ledger'] as $dependency) {
                $project->write('Project/Packages/' . $dependency . '/' . $dependency . '.php',
                    '<?php namespace Packages\\' . $dependency . '; final class ' . $dependency
                    . ' extends \\App\\Plugins\\ServiceProvider {}');
            }
            $sources->write('Weather/Weather.php',
                '<?php namespace Packages\\Weather; final class Weather extends \\App\\Plugins\\ServiceProvider {}');
            $this->writePackageMetadata($sources, '1.0.0', ['Geo']);
            $manager = new PackageManager(new Application($project->path()));

            $install = $manager->planInstall($sources->path('Weather'));
            self::assertFalse($install->hasConflicts());
            self::assertContains('Required Package: Geo.', $install->warnings);
            $installPreview = ChangeRenderer::render($install);
            self::assertStringContainsString('Required Package: Geo.', $installPreview);
            self::assertStringNotContainsString('PRIVATE_COMPOSER_FIELD', $installPreview);
            self::assertTrue($manager->apply($install)->complete());

            $this->writePackageMetadata($sources, '2.0.0', ['Ledger']);
            $upgrade = $manager->planUpgrade('Weather', $sources->path('Weather'));
            self::assertFalse($upgrade->hasConflicts());
            foreach ([
                'Required Package: Ledger.',
                'Added Package requirement: Ledger.',
                'Removed Package requirement: Geo.',
                'Package version: 1.0.0 to 2.0.0.',
            ] as $expected) {
                self::assertContains($expected, $upgrade->warnings);
                self::assertStringContainsString($expected, ChangeRenderer::render($upgrade));
            }
            self::assertStringNotContainsString('PRIVATE_COMPOSER_FIELD',
                json_encode($upgrade->toArray(), JSON_THROW_ON_ERROR));
            self::assertTrue($manager->apply($upgrade)->complete());

            $this->writePackageMetadata($sources, 'PRIVATE_COMPOSER_FIELD', ['Ledger']);
            $nonNumericVersion = $manager->planUpgrade('Weather', $sources->path('Weather'));
            self::assertContains('Package version metadata changes; inspect composer.json.',
                $nonNumericVersion->warnings);
            self::assertStringNotContainsString('PRIVATE_COMPOSER_FIELD', ChangeRenderer::render($nonNumericVersion));
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testUnchangedStateFingerprintIsNotReportedAsAppliedAfterFailure(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $sources->write('NoOp/NoOp.php',
                '<?php namespace Packages\\NoOp; final class NoOp extends \\App\\Plugins\\ServiceProvider {}');
            $manager = new PackageManager(new Application($project->path()));
            self::assertTrue($manager->apply($manager->planInstall($sources->path('NoOp')))->complete());
            $upgrade = $manager->planUpgrade('NoOp', $sources->path('NoOp'));
            self::assertCount(1, $upgrade->actions);
            self::assertSame('state', $upgrade->actions[0]->kind);
            self::assertSame($upgrade->actions[0]->before, $upgrade->actions[0]->after);

            $observe = new \ReflectionMethod(PackageManager::class, 'observedResult');
            $result = $observe->invoke($manager, $upgrade, null);
            self::assertSame([], $result->applied);
            self::assertCount(1, $result->unapplied);
            self::assertFalse($result->complete());
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    /** @param list<string> $requirements */
    private function writePackageMetadata(TemporaryProject $source, string $version, array $requirements): void
    {
        $source->write('Weather/composer.json', json_encode([
            'name' => 'example/weather',
            'version' => $version,
            'description' => 'PRIVATE_COMPOSER_FIELD',
            'extra' => ['squehub' => ['name' => 'Weather', 'requires' => $requirements]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** @return list<string> */
    private function kinds(ChangePlan $plan): array
    {
        return array_map(static fn ($action): string => $action->kind, $plan->actions);
    }
}
