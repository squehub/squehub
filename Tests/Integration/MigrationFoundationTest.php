<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Migrations\MigrationException;
use App\Database\Migrations\MigrationRepository;
use App\Database\Migrations\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class MigrationFoundationTest extends TestCase
{
    private TemporaryProject $project;
    private DatabaseManager $manager;
    private Migrator $migrator;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for migration integration tests.');
        }
        $this->project = new TemporaryProject();
        $this->manager = new DatabaseManager(new Repository([
            'database' => [
                'default' => 'test',
                'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']],
            ],
        ]));
        $this->migrator = new Migrator($this->manager, $this->project->path());
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->remove();
        }
    }

    public function testPendingRunBatchesStatusRollbackAndReset(): void
    {
        $first = '2026_01_01_create_phasefive_alpha.php';
        $second = '2026_01_02_create_phasefive_beta.php';
        $third = '2026_01_03_create_phasefive_gamma.php';
        $this->writeMigration($first, 'CreatePhasefiveAlpha', 'phasefive_alpha');
        $this->writeMigration($second, 'CreatePhasefiveBeta', 'phasefive_beta');
        $this->project->write('Database/Migrations/README.txt', 'Ignore this file.');

        self::assertSame([$first, $second], $this->migrator->files());
        self::assertFalse($this->manager->connection()->isConnected());
        self::assertSame([$first, $second], $this->migrator->pending());
        self::assertTrue($this->manager->connection()->isConnected());
        self::assertSame([$first, $second], $this->migrator->run());
        self::assertSame([], $this->migrator->pending());
        self::assertSame([], $this->migrator->run());

        $pdo = $this->manager->connection()->pdo();
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        self::assertSame([1, 1], array_column($this->migrator->status(), 'batch'));
        self::assertSame(['applied', 'applied'], array_column($this->migrator->status(), 'state'));

        $this->writeMigration($third, 'CreatePhasefiveGamma', 'phasefive_gamma');
        self::assertSame([$third], $this->migrator->pending());
        self::assertSame([$third], $this->migrator->run());
        self::assertSame([1, 1, 2], array_column($this->migrator->status(), 'batch'));
        self::assertSame([$third], $this->migrator->rollback());
        self::assertSame([$third], $this->migrator->pending());
        self::assertSame([$second, $first], $this->migrator->reset());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'phasefive_%'")->fetchColumn());
    }

    public function testExistingTrackingTableKeepsHistoryAndRollbackOrder(): void
    {
        $first = '2026_02_01_create_phasefive_legacyfirst.php';
        $second = '2026_02_02_create_phasefive_legacysecond.php';
        $this->writeMigration($first, 'CreatePhasefiveLegacyfirst', 'phasefive_legacyfirst');
        $this->writeMigration('2026_02_02_Create_phasefive_legacysecond.php',
            'CreatePhasefiveLegacysecond', 'phasefive_legacysecond');

        $pdo = $this->manager->connection()->pdo();
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(255) NOT NULL UNIQUE, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE phasefive_legacyfirst (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE phasefive_legacysecond (id INTEGER PRIMARY KEY)');
        $statement = $pdo->prepare('INSERT INTO migrations (migration) VALUES (?)');
        $statement->execute([$first]);
        $statement->execute([$second]);

        $repository = new MigrationRepository($pdo);
        $repository->ensure();
        self::assertSame([1, 2], array_column($repository->rows(), 'batch'));
        self::assertSame([], $this->migrator->pending());
        self::assertSame([$second], $this->migrator->rollback());
        self::assertSame([$first], array_column($repository->rows(), 'migration'));
    }

    public function testMissingMigrationClassDoesNotRecordExecution(): void
    {
        $this->project->write('Database/Migrations/2026_03_01_missing_phasefive_handler.php', '<?php // No migration class.');

        try {
            $this->migrator->run();
            self::fail('Expected the missing class to fail migration execution.');
        } catch (MigrationException $exception) {
            self::assertStringContainsString('MissingPhasefiveHandler', $exception->getMessage());
            self::assertSame(0, (int) $this->manager->connection()->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        }
    }

    public function testFailedUpIsNotMarkedAsApplied(): void
    {
        $this->project->write(
            'Database/Migrations/2026_04_01_failing_phasefive_migration.php',
            '<?php class FailingPhasefiveMigration {'
            . ' public function up(\\PDO $pdo): void { throw new \\RuntimeException("failure"); }'
            . ' public function down(\\PDO $pdo): void {}'
            . ' }'
        );

        try {
            $this->migrator->run();
            self::fail('Expected the migration body to fail.');
        } catch (MigrationException $exception) {
            self::assertStringContainsString('failed during up()', $exception->getMessage());
            self::assertSame(0, (int) $this->manager->connection()->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        }
    }

    private function writeMigration(string $file, string $class, string $table): void
    {
        $this->project->write(
            'Database/Migrations/' . $file,
            '<?php class ' . $class . ' {'
            . ' public function up(\\PDO $pdo): void { $pdo->exec("CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)"); }'
            . ' public function down(\\PDO $pdo): void { $pdo->exec("DROP TABLE ' . $table . '"); }'
            . ' }'
        );
    }
}
