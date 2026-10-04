<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Clis\FileLookup;
use App\Clis\Make\Generator;
use App\Changes\ChangePlan;
use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Migrations\Migrator;
use App\Database\Seeding\SeederRunner;
use App\Foundation\Application;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Checks generated declarations against the framework's current runtime contracts. */
final class GeneratorTest extends TestCase
{
    private TemporaryProject $project;
    private Generator $generator;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->generator = new Generator(
            $this->project->path(),
            new DateTimeImmutable('2026-09-27T12:34:56+00:00')
        );
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testPlansAreCanonicalDeterministicAndDoNotWrite(): void
    {
        $cases = [
            ['controller', 'GeneratedWelcomeController', 'Project/Controllers/GeneratedWelcomeController.php'],
            ['model', 'GeneratedAccount', 'Project/Models/GeneratedAccount.php'],
            ['middleware', 'GeneratedGate', 'Project/Middleware/GeneratedGate.php'],
            ['seeder', 'GeneratedUserSeeder', 'Database/Seeders/GeneratedUserSeeder.php'],
        ];

        foreach ($cases as [$kind, $name, $relativePath]) {
            $plan = $this->generator->plan($kind, $name);
            self::assertInstanceOf(ChangePlan::class, $plan);
            self::assertSame('make:' . $kind, $plan->operation);
            self::assertSame($relativePath, $plan->actions[0]->subject);
            self::assertSame('create', $plan->actions[0]->kind);
            self::assertNull($plan->actions[0]->before);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', (string) $plan->actions[0]->after);
            self::assertSame($plan->fingerprint(), $this->generator->plan($kind, $name)->fingerprint());
            self::assertStringNotContainsString('declare(strict_types=1);',
                (string) json_encode($plan), 'Source contents must stay out of the portable plan.');
            self::assertFileDoesNotExist($this->project->path($relativePath));
        }
        self::assertDirectoryDoesNotExist($this->project->path('Project/Controllers'));
        self::assertDirectoryDoesNotExist($this->project->path('Database/Seeders'));
    }

    public function testMigrationFilenameAndClassFollowMigratorDiscovery(): void
    {
        $plan = $this->generator->plan('migration', 'create_generated_accounts_table');
        $relativePath = $plan->actions[0]->subject;
        self::assertMatchesRegularExpression(
            '~\ADatabase/Migrations/2026_09_27_(?:123456_)?create_generated_accounts_table\.php\z~',
            $relativePath
        );
        $this->generator->apply($plan);
        $contents = (string) file_get_contents($this->project->path($relativePath));
        self::assertStringContainsString('class CreateGeneratedAccountsTable', $contents);
        self::assertStringContainsString('function up(PDO $pdo, Schema $schema): void', $contents);
        self::assertStringContainsString('function down(PDO $pdo, Schema $schema): void', $contents);
        self::assertStringNotContainsString('$schema->table(', $contents);
        self::assertSame(
            realpath($this->project->path($relativePath)),
            FileLookup::migration($this->project->path(), basename($relativePath))
        );
        $this->assertPhpLints($this->project->path($relativePath));
    }

    public function testGeneratedMigrationRunsAndRollsBackOnIsolatedSqlite(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required to execute generated migrations.');
        }

        $plan = $this->generator->plan('migration', 'create_generated_accounts_table');
        $this->generator->apply($plan);
        $database = new DatabaseManager(new Repository(['database' => [
            'default' => 'isolated',
            'connections' => ['isolated' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        $connection = $database->connection();
        self::assertFalse($connection->isConnected(), 'Generating must not open a database connection.');

        $migrator = new Migrator($database, $this->project->path());
        self::assertSame([basename($plan->actions[0]->subject)], $migrator->files());
        self::assertFalse($connection->isConnected(), 'Migration discovery must remain read-only.');
        self::assertSame([basename($plan->actions[0]->subject)], $migrator->run());
        self::assertTrue($connection->schema()->hasTable('generated_accounts'));
        self::assertSame([basename($plan->actions[0]->subject)], $migrator->rollback());
        self::assertFalse($connection->schema()->hasTable('generated_accounts'));
    }

    public function testGeneratedModelControllerMiddlewareAndSeederLoadAndRun(): void
    {
        $plans = [
            $this->generator->plan('controller', 'GeneratedWelcomeController'),
            $this->generator->plan('model', 'GeneratedAccount'),
            $this->generator->plan('middleware', 'GeneratedGate'),
            $this->generator->plan('seeder', 'GeneratedUserSeeder'),
        ];
        foreach ($plans as $plan) {
            $this->generator->apply($plan);
            $this->assertPhpLints($this->project->path($plan->actions[0]->subject));
        }

        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('Project\\', $this->project->path('Project'));
        $loader->addPsr4('Database\\Seeders\\', $this->project->path('Database/Seeders'));
        $loader->register(true);
        try {
            $class = static fn (string $namespace, string $name): string => $namespace . '\\' . $name;
            $controller = $class('Project\\Controllers', 'GeneratedWelcomeController');
            $model = $class('Project\\Models', 'GeneratedAccount');
            $middleware = $class('Project\\Middleware', 'GeneratedGate');
            $seeder = $class('Database\\Seeders', 'GeneratedUserSeeder');
            self::assertTrue(class_exists($controller));
            self::assertInstanceOf(\App\Database\Model::class, new $model());

            $request = new \App\Http\Request();
            $response = new \App\Http\Response('continued');
            self::assertSame($response, (new $middleware())
                ->handle($request, static fn (\App\Http\Request $current): \App\Http\Response => $response));

            self::assertTrue(is_subclass_of(
                $seeder,
                \App\Database\Seeding\Seeder::class
            ));
            self::assertSame(
                realpath($this->project->path('Database/Seeders/GeneratedUserSeeder.php')),
                FileLookup::seeder($this->project->path(), 'GeneratedUserSeeder')
            );
            self::assertStringNotContainsString('Dumper',
                (string) file_get_contents($this->project->path('Database/Seeders/GeneratedUserSeeder.php')));

            $app = new Application($this->project->path());
            $app->bootstrap();
            self::assertSame([$seeder],
                (new SeederRunner($app))->run('GeneratedUserSeeder'));
        } finally {
            $loader->unregister();
        }
    }

    public function testPackageTargetUsesExistingCanonicalPackageWithoutCreatingOne(): void
    {
        $this->project->write('Project/Packages/Commerce/Commerce.php',
            '<?php namespace Packages\\Commerce; final class Commerce extends \\App\\Plugins\\ServiceProvider {}');
        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('Project\\', $this->project->path('Project'));
        $loader->register(true);
        try {
            foreach (['controller' => 'Controllers', 'model' => 'Models',
                'middleware' => 'Middleware'] as $kind => $directory) {
                $name = 'Commerce' . ucfirst($kind);
                $plan = $this->generator->plan($kind, $name, 'Commerce');
                self::assertSame("Project/Packages/Commerce/{$directory}/{$name}.php",
                    $plan->actions[0]->subject);
                self::assertSame('package', $plan->owner->type);
                self::assertSame('Commerce', $plan->owner->name);
                $this->generator->apply($plan);
                $this->assertPhpLints($this->project->path($plan->actions[0]->subject));
                self::assertTrue(class_exists("Project\\Packages\\Commerce\\{$directory}\\{$name}"));
            }
        } finally {
            $loader->unregister();
        }
        self::assertDirectoryDoesNotExist($this->project->path('Project/Packages/Missing'));
        foreach (['controller', 'migration', 'seeder'] as $kind) {
            $rejected = false;
            try {
                $this->generator->plan($kind, 'MissingTarget', 'Missing');
            } catch (Throwable $exception) {
                $rejected = true;
                self::assertNotSame('', $exception->getMessage());
            }
            self::assertTrue($rejected, 'A missing Package target must be rejected.');
        }
        foreach (['migration', 'seeder'] as $kind) {
            $rejected = false;
            try {
                $this->generator->plan($kind, 'CreatePackageData', 'Commerce');
            } catch (Throwable $exception) {
                $rejected = true;
                self::assertNotSame('', $exception->getMessage());
            }
            self::assertTrue($rejected, 'Package-local migrations and Seeders are not runtime-discovered.');
        }
    }

    public function testNestedClassNamesNormalizeSafelyForClassGenerators(): void
    {
        $slash = $this->generator->plan('controller', 'Admin/GeneratedUserController');
        $backslash = $this->generator->plan('controller', 'Admin\\GeneratedUserController');
        self::assertSame('Project/Controllers/Admin/GeneratedUserController.php', $slash->actions[0]->subject);
        self::assertSame($slash->fingerprint(), $backslash->fingerprint());

        $lowercase = $this->generator->plan('controller', 'admin/generatedUserController');
        self::assertSame($slash->actions[0]->subject, $lowercase->actions[0]->subject);
        self::assertSame($slash->actions[0]->after, $lowercase->actions[0]->after);
    }

    public function testBrokenPackageTargetIsRejectedBeforeWriting(): void
    {
        $this->project->write('Project/Packages/Broken/Readme.md', 'missing entry');
        try {
            $this->generator->plan('controller', 'ProbeController', 'Broken');
            self::fail('A structurally broken Package must not receive generated source.');
        } catch (\App\Clis\Make\GeneratorException $exception) {
            self::assertNotSame('', $exception->getMessage());
            self::assertFileDoesNotExist($this->project->path(
                'Project/Packages/Broken/Controllers/ProbeController.php'));
        }
    }

    public function testUnsafeNamesAndUnsupportedPackageTargetsNeverWrite(): void
    {
        $invalid = [
            '../Escape', '/Absolute', 'C:\\Escape', '\\\\server\\share',
            'Admin/../Escape', 'Admin\\..\\Escape', "Name\0Bad", "Name\nBad",
            '9Invalid', 'Bad..Name',
        ];
        foreach ($invalid as $name) {
            $rejected = false;
            try {
                $this->generator->plan('controller', $name);
            } catch (Throwable $exception) {
                $rejected = true;
                self::assertNotSame('', $exception->getMessage());
            }
            self::assertTrue($rejected, 'Invalid name was accepted: ' . bin2hex($name));
        }
        self::assertDirectoryDoesNotExist($this->project->path('Project/Controllers'));
    }

    public function testExistingTargetIsNeverOverwrittenAndApplicationsRemainIsolated(): void
    {
        $plan = $this->generator->plan('controller', 'GeneratedCollisionController');
        $this->generator->apply($plan);
        $path = $this->project->path($plan->actions[0]->subject);
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0666 & ~umask(), fileperms($path) & 0777,
                'Generated source should be readable under the active process umask.');
        }
        $this->project->write($plan->actions[0]->subject, 'user-owned contents');
        $rejected = false;
        try {
            $this->generator->apply($plan);
        } catch (Throwable $exception) {
            $rejected = true;
            self::assertSame('user-owned contents', file_get_contents($path));
        }
        self::assertTrue($rejected, 'Existing generated output was overwritten.');

        $other = new TemporaryProject();
        try {
            $generator = new Generator($other->path(), new DateTimeImmutable('2026-09-27T12:34:56+00:00'));
            $otherPlan = $generator->plan('controller', 'GeneratedCollisionController');
            $generator->apply($otherPlan);
            self::assertSame($plan->actions[0]->after,
                hash_file('sha256', $other->path($otherPlan->actions[0]->subject)));
            self::assertSame('user-owned contents', file_get_contents($path));
        } finally {
            $other->remove();
        }
    }

    public function testAppearingFileMakesTheReviewedPlanStaleWithoutOverwritingIt(): void
    {
        $plan = $this->generator->plan('model', 'GeneratedStaleModel');
        $relative = $plan->actions[0]->subject;
        self::assertSame([$relative => null], $plan->preconditions);
        $this->project->write($relative, 'external edit after review');

        try {
            $this->generator->apply($plan);
            self::fail('An appeared target must invalidate the reviewed plan.');
        } catch (\App\Clis\Make\GeneratorException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }
        self::assertSame('external edit after review', file_get_contents($this->project->path($relative)));

        $newPlan = $this->generator->plan('model', 'GeneratedStaleModel');
        self::assertTrue($newPlan->hasConflicts());
        self::assertStringContainsString('Target already exists', implode(' ', $newPlan->conflicts));
        self::assertNotSame($plan->fingerprint(), $newPlan->fingerprint());
    }

    public function testPackageOwnershipFingerprintCannotChangeAfterReview(): void
    {
        $entry = 'Project/Packages/Commerce/Commerce.php';
        $this->project->write($entry,
            '<?php namespace Packages\\Commerce; final class Commerce extends \\App\\Plugins\\ServiceProvider {}');
        $plan = $this->generator->plan('controller', 'GeneratedOrderController', 'Commerce');
        $this->project->write($entry, (string) file_get_contents($this->project->path($entry))
            . "\n// Package source changed after review.\n");

        try {
            $this->generator->apply($plan);
            self::fail('Changed Package-owned source must invalidate the reviewed plan.');
        } catch (\App\Clis\Make\GeneratorException $exception) {
            self::assertStringContainsString('ownership changed', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->project->path($plan->actions[0]->subject));
    }

    public function testApplyRevalidatesParentAndLeavesObstructionUntouched(): void
    {
        $plan = $this->generator->plan('controller', 'GeneratedBlockedController');
        $this->project->write('Project/Controllers', 'existing non-directory');
        $rejected = false;
        try {
            $this->generator->apply($plan);
        } catch (Throwable $exception) {
            $rejected = true;
        }
        self::assertTrue($rejected);
        self::assertSame('existing non-directory', file_get_contents($this->project->path('Project/Controllers')));
        self::assertFileDoesNotExist($this->project->path($plan->actions[0]->subject));
    }

    public function testDifferentCaseExistingRootCannotCreateAParallelCanonicalTree(): void
    {
        $this->project->write('project/Readme.md', 'Existing differently cased root.');
        $plan = $this->generator->plan('controller', 'GeneratedCasingController');
        self::assertTrue($plan->hasConflicts());
        self::assertStringContainsString('casing', implode(' ', $plan->conflicts));
        try {
            $this->generator->apply($plan);
            self::fail('A case-only collision must not apply.');
        } catch (\App\Clis\Make\GeneratorException $exception) {
            self::assertStringContainsString('invalid', $exception->getMessage());
        }
        self::assertFileExists($this->project->path('project/Readme.md'));
        self::assertDirectoryDoesNotExist($this->project->path('Project/Controllers'));
    }

    private function assertPhpLints(string $path): void
    {
        $lint = new Process([PHP_BINARY, '-l', $path]);
        $lint->run();
        self::assertTrue($lint->isSuccessful(), $path . ': ' . $lint->getOutput() . $lint->getErrorOutput());
    }
}
