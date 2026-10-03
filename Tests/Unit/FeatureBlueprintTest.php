<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Changes\ChangePlan;
use App\Clis\Make\FeatureBlueprintGenerator;
use App\Clis\Make\GeneratorException;
use App\Packages\PackageStateStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Checks one-feature planning, ownership, and guarded multi-file publication. */
final class FeatureBlueprintTest extends TestCase
{
    private TemporaryProject $project;
    private FeatureBlueprintGenerator $blueprints;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->blueprints = new FeatureBlueprintGenerator(
            $this->project->path(), new DateTimeImmutable('2026-09-28T12:34:56+00:00'));
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testApplicationPlanIsReviewableDeterministicAndReadOnly(): void
    {
        $first = $this->blueprints->plan('Post');
        $second = $this->blueprints->plan('Post');

        self::assertInstanceOf(ChangePlan::class, $first);
        self::assertSame('make:feature', $first->operation);
        self::assertSame('application', $first->owner->type);
        self::assertSame('Project', $first->owner->name);
        self::assertFalse($first->hasConflicts());
        self::assertSame($first->fingerprint(), $second->fingerprint());
        self::assertSame($first->toArray(), $second->toArray());

        $subjects = array_map(static fn ($action): string => $action->subject, $first->actions);
        self::assertContains('Project/Models/Post.php', $subjects);
        self::assertContains('Project/Controllers/PostController.php', $subjects);
        self::assertContains('Project/Api/Resources/PostResource.php', $subjects);
        self::assertContains('Project/Validation/PostValidation.php', $subjects);
        self::assertContains('Project/Routes/Features/Post.php', $subjects);
        self::assertContains('Tests/Integration/PostFeatureTest.php', $subjects);
        self::assertContains('Database/Migrations/2026_09_28_123456_create_post_table.php', $subjects);
        self::assertCount(count(array_unique($subjects)), $subjects);
        self::assertCount(1, array_filter($subjects,
            static fn (string $subject): bool => str_starts_with($subject, 'Database/Migrations/')));
        self::assertCount(1, array_filter($subjects,
            static fn (string $subject): bool => str_starts_with($subject, 'Tests/Integration/')));
        self::assertCount(1, array_filter($subjects,
            static fn (string $subject): bool => str_starts_with($subject, 'Project/Routes/')));
        self::assertCount(7, $subjects);
        foreach ($first->actions as $action) {
            self::assertSame('create', $action->kind);
            self::assertSame('application', $action->owner->type);
            self::assertNull($action->before);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', (string) $action->after);
            self::assertArrayHasKey($action->subject, $first->preconditions);
            self::assertNull($first->preconditions[$action->subject]);
            self::assertFileDoesNotExist($this->project->path($action->subject));
        }
        $json = json_encode($first->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('APP_KEY', $json);
        self::assertStringNotContainsString($this->project->path(), $json);
    }

    public function testApplyPublishesOnlyTheReviewedFilesAndRejectsRepeatedFeature(): void
    {
        $plan = $this->blueprints->plan('Post');
        $result = $this->blueprints->apply($plan);
        self::assertTrue($result->complete());
        self::assertCount(count($plan->actions), $result->applied);
        foreach ($plan->actions as $action) {
            $file = $this->project->path($action->subject);
            self::assertFileExists($file);
            self::assertSame($action->after, hash_file('sha256', $file));
            $this->assertPhpLints($file);
        }
        self::assertStringContainsString('App\Plugins\Model',
            (string) file_get_contents($this->project->path('Project/Models/Post.php')));
        self::assertStringContainsString('App\Plugins\ApiResource',
            (string) file_get_contents($this->project->path('Project/Api/Resources/PostResource.php')));
        self::assertStringContainsString('App\Plugins\TestCase',
            (string) file_get_contents($this->project->path('Tests/Integration/PostFeatureTest.php')));
        self::assertStringContainsString('function rules()',
            (string) file_get_contents($this->project->path('Project/Validation/PostValidation.php')));
        self::assertStringContainsString("Route::path('/post')",
            (string) file_get_contents($this->project->path('Project/Routes/Features/Post.php')));

        $model = $this->project->path('Project/Models/Post.php');
        $bytes = (string) file_get_contents($model);
        $repeat = $this->blueprints->plan('Post');
        self::assertTrue($repeat->hasConflicts());
        self::assertSame($bytes, file_get_contents($model));
    }

    public function testPreflightBlocksPartialFeaturesAndStaleTargets(): void
    {
        $this->project->write('Project/Models/Post.php', 'application-owned model');
        $conflict = $this->blueprints->plan('Post');
        self::assertTrue($conflict->hasConflicts());
        try {
            $this->blueprints->apply($conflict);
            self::fail('A partial existing feature cannot be adopted implicitly.');
        } catch (GeneratorException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertSame('application-owned model',
            file_get_contents($this->project->path('Project/Models/Post.php')));
        self::assertFileDoesNotExist($this->project->path('Project/Controllers/PostController.php'));

        $fresh = $this->blueprints->plan('Invoice');
        $target = $fresh->actions[count($fresh->actions) - 1]->subject;
        $this->project->write($target, 'external edit');
        try {
            $this->blueprints->apply($fresh);
            self::fail('An appeared target must invalidate the reviewed plan.');
        } catch (GeneratorException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertSame('external edit', file_get_contents($this->project->path($target)));
        self::assertFileDoesNotExist($this->project->path('Project/Models/Invoice.php'));
    }

    public function testExistingLiteralRouteCollisionBlocksEveryFeatureFile(): void
    {
        $this->project->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/post')
    ->get(static fn (): string => 'existing')
    ->named('post.index');
PHP);
        $plan = $this->blueprints->plan('Post');
        self::assertTrue($plan->hasConflicts());
        self::assertStringContainsString('route', strtolower(implode(' ', $plan->conflicts)));
        try {
            $this->blueprints->apply($plan);
            self::fail('An existing literal route cannot be silently shadowed.');
        } catch (GeneratorException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->project->path('Project/Models/Post.php'));
    }

    public function testRouteChangedAfterReviewCannotIntroduceAHiddenCollision(): void
    {
        $routeFile = 'Project/Routes/Web.php';
        $this->project->write($routeFile,
            "<?php \\App\\Routing\\Route::path('/welcome')->get(static fn (): string => 'ok');");
        $plan = $this->blueprints->plan('Post');
        self::assertFalse($plan->hasConflicts());
        $this->project->write($routeFile,
            "<?php \\App\\Routing\\Route::path('/post')->get(static fn (): string => 'existing')"
            . "->named('post.index');");

        try {
            $this->blueprints->apply($plan);
            self::fail('A reviewed route set cannot change to a collision before apply.');
        } catch (GeneratorException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->project->path('Project/Models/Post.php'));
        self::assertStringContainsString("Route::path('/post')",
            (string) file_get_contents($this->project->path($routeFile)));
    }

    public function testNewLiteralRouteAfterReviewRejectsTheWholeBlueprint(): void
    {
        $plan = $this->blueprints->plan('Post');
        self::assertFalse($plan->hasConflicts());
        $this->project->write('Project/Routes/Web.php',
            "<?php \\App\\Routing\\Route::path('/post')->get(static fn (): string => 'existing');");

        try {
            $this->blueprints->apply($plan);
            self::fail('A newly introduced route must be checked before publication.');
        } catch (GeneratorException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->project->path('Project/Models/Post.php'));
        self::assertFileDoesNotExist($this->project->path('Project/Routes/Features/Post.php'));
    }

    public function testDifferentTimestampMigrationClassAfterReviewRejectsTheWholeBlueprint(): void
    {
        $plan = $this->blueprints->plan('Post');
        self::assertFalse($plan->hasConflicts());
        $this->project->write('Database/Migrations/2026_01_01_000000_create_post_table.php',
            '<?php namespace Database\\Migrations; final class CreatePostTable {}');

        try {
            $this->blueprints->apply($plan);
            self::fail('Migration class ownership must be rechecked after review.');
        } catch (GeneratorException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->project->path('Project/Models/Post.php'));
        self::assertFileDoesNotExist($this->project->path('Project/Routes/Features/Post.php'));
    }

    public function testUnsafeNamesAndCaseOnlyRootsFailWithoutWriting(): void
    {
        foreach (['../Escape', '/Absolute', 'Admin/../Escape', 'C:\\Escape', '9Invalid',
            "Bad\0Name", 'Bad..Name'] as $name) {
            try {
                $this->blueprints->plan($name);
                self::fail('Unsafe Feature name accepted: ' . bin2hex($name));
            } catch (GeneratorException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        self::assertDirectoryDoesNotExist($this->project->path('Project/Models'));

        $this->project->write('project/Readme.md', 'differently cased root');
        $plan = $this->blueprints->plan('Post');
        self::assertTrue($plan->hasConflicts());
        self::assertStringContainsString('casing', strtolower(implode(' ', $plan->conflicts)));
        self::assertDirectoryDoesNotExist($this->project->path('Project/Models'));
    }

    public function testPackagePlanRetainsOwnershipAndDoesNotChangePackageState(): void
    {
        $entry = 'Project/Packages/Commerce/Commerce.php';
        $this->project->write($entry,
            '<?php namespace Packages\\Commerce; final class Commerce extends \\App\\Plugins\\ServiceProvider {}');
        $plan = $this->blueprints->plan('Order', 'Commerce');
        self::assertFalse($plan->hasConflicts());
        self::assertSame('package', $plan->owner->type);
        self::assertSame('Commerce', $plan->owner->name);

        $subjects = array_map(static fn ($action): string => $action->subject, $plan->actions);
        self::assertContains('Project/Packages/Commerce/Models/Order.php', $subjects);
        self::assertContains('Project/Packages/Commerce/Controllers/OrderController.php', $subjects);
        self::assertContains('Project/Packages/Commerce/Api/Resources/OrderResource.php', $subjects);
        self::assertContains('Project/Packages/Commerce/Validation/OrderValidation.php', $subjects);
        self::assertContains('Project/Packages/Commerce/Routes/Features/Order.php', $subjects);
        self::assertContains('Tests/Integration/CommerceOrderFeatureTest.php', $subjects);
        self::assertCount(1, array_filter($subjects,
            static fn (string $subject): bool => str_starts_with($subject, 'Database/Migrations/')));
        self::assertCount(1, array_filter($subjects,
            static fn (string $subject): bool => str_starts_with($subject, 'Tests/Integration/')));
        self::assertCount(7, $subjects);
        foreach ($plan->actions as $action) {
            self::assertSame('package', $action->owner->type);
            self::assertSame('Commerce', $action->owner->name);
        }
        $state = $this->project->path('Project/Activation.json');
        self::assertFileDoesNotExist($state);
        self::assertTrue($this->blueprints->apply($plan)->complete());
        self::assertFileDoesNotExist($state);
        self::assertFileExists($this->project->path($entry));
    }

    public function testPackageSourceChangeInvalidatesReviewedBlueprint(): void
    {
        $entry = 'Project/Packages/Commerce/Commerce.php';
        $this->project->write($entry,
            '<?php namespace Packages\\Commerce; final class Commerce extends \\App\\Plugins\\ServiceProvider {}');
        $plan = $this->blueprints->plan('Order', 'Commerce');
        $this->project->write($entry, (string) file_get_contents($this->project->path($entry))
            . "\n// Reviewed Package changed.\n");
        try {
            $this->blueprints->apply($plan);
            self::fail('Package ownership changed after review.');
        } catch (GeneratorException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->project->path('Project/Packages/Commerce/Models/Order.php'));
    }

    public function testPackageStateChangeInvalidatesReviewedBlueprint(): void
    {
        $entry = 'Project/Packages/Commerce/Commerce.php';
        $this->project->write($entry,
            '<?php namespace Packages\\Commerce; final class Commerce extends \\App\\Plugins\\ServiceProvider {}');
        $plan = $this->blueprints->plan('Order', 'Commerce');
        (new PackageStateStore($this->project->path('Project/Packages')))->write([
            'Commerce' => [
                'enabled' => false,
                'source_kind' => 'manual',
                'source' => 'manual',
                'files' => (object) [],
            ],
        ]);
        try {
            $this->blueprints->apply($plan);
            self::fail('Package lifecycle state changed after review.');
        } catch (GeneratorException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->project->path('Project/Packages/Commerce/Models/Order.php'));
    }

    public function testEquivalentApplicationsHavePortablePlansButPrivateApplyContext(): void
    {
        $other = new TemporaryProject();
        try {
            $second = new FeatureBlueprintGenerator(
                $other->path(), new DateTimeImmutable('2026-09-28T12:34:56+00:00'));
            $firstPlan = $this->blueprints->plan('Post');
            $secondPlan = $second->plan('Post');
            self::assertSame($firstPlan->fingerprint(), $secondPlan->fingerprint());
            self::assertSame($firstPlan->toArray(), $secondPlan->toArray());
            try {
                $second->apply($firstPlan);
                self::fail('A Blueprint plan must belong to its generator/application.');
            } catch (GeneratorException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
            self::assertFileDoesNotExist($other->path('Project/Models/Post.php'));
            self::assertTrue($this->blueprints->apply($firstPlan)->complete());
            self::assertTrue($second->apply($secondPlan)->complete());
            self::assertFileExists($other->path('Project/Models/Post.php'));
        } finally {
            $other->remove();
        }
    }

    private function assertPhpLints(string $path): void
    {
        $lint = new Process([PHP_BINARY, '-l', $path]);
        $lint->run();
        self::assertTrue($lint->isSuccessful(), $path . ': ' . $lint->getOutput() . $lint->getErrorOutput());
    }
}
