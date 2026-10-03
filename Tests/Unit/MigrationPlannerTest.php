<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Changes\ChangePlan;
use App\Database\Migrations\MigrationPlanner;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Migration previews describe source evidence without loading PHP or a database. */
final class MigrationPlannerTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) $project->remove();
        $this->projects = [];
    }

    private function project(): TemporaryProject
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        return $project;
    }

    public function testStaticPlanUsesSharedRepresentationWithoutExecutingMigrationPhp(): void
    {
        $project = $this->project();
        $marker = $project->path('executed.txt');
        $body = '<?php file_put_contents(' . var_export($marker, true) . ', "executed"); '
            . 'class CreateAccounts { public function up(): void {} }';
        $project->write('Database/Migrations/2026_10_01_create_accounts.php', $body);

        $plan = (new MigrationPlanner())->plan($project->path());

        self::assertInstanceOf(ChangePlan::class, $plan);
        self::assertSame('migrate:plan', $plan->operation);
        self::assertNotNull($plan->metadata);
        self::assertSame('migration', $plan->metadata->source);
        self::assertSame([], $plan->conflicts);
        self::assertCount(1, $plan->actions);
        self::assertSame('state', $plan->actions[0]->kind);
        self::assertSame('migration', $plan->actions[0]->category);
        self::assertSame('review', $plan->actions[0]->risk);
        self::assertSame(hash('sha256', $body), $plan->actions[0]->after);
        self::assertContains('migration_pending', $plan->metadata->verification);
        self::assertNotNull($plan->metadata->sourceFingerprint);
        self::assertFileDoesNotExist($marker);
        self::assertStringNotContainsString($project->path(),
            json_encode($plan->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testDuplicateClassIdentityBlocksPlanWithoutLoadingEitherFile(): void
    {
        $project = $this->project();
        $project->write('Database/Migrations/2026_10_01_create_accounts.php',
            '<?php class CreateAccounts {}');
        $project->write('Database/Migrations/2026_10_02_create-accounts.php',
            '<?php class CreateAccountsAgain {}');

        $plan = (new MigrationPlanner())->plan($project->path());

        self::assertTrue($plan->hasConflicts());
        self::assertContains('Migration source contains a duplicate class identity.',
            $plan->conflicts);
        self::assertNull($plan->metadata?->sourceFingerprint);
        self::assertCount(2, $plan->actions);
    }

    public function testMissingDirectoryIsReadOnlyAndDoesNotClaimPendingState(): void
    {
        $project = $this->project();
        $plan = (new MigrationPlanner())->plan($project->path());

        self::assertSame([], $plan->actions);
        self::assertFalse($plan->hasConflicts());
        self::assertContains('No migration source directory was found.', $plan->warnings);
        self::assertContains('Migration history was not inspected; listed files are not confirmed pending.',
            $plan->warnings);
        self::assertNull($plan->metadata?->sourceFingerprint);
    }

    public function testUnsafeFilenameBlocksWithoutPublishingItAsAnAction(): void
    {
        $project = $this->project();
        $project->write('Database/Migrations/bad name.php', '<?php');

        $plan = (new MigrationPlanner())->plan($project->path());

        self::assertTrue($plan->hasConflicts());
        self::assertSame([], $plan->actions);
        self::assertStringNotContainsString('bad name.php',
            json_encode($plan->toArray(), JSON_THROW_ON_ERROR));
    }
}
