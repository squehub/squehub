<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Exception\QueryException;
use App\Database\Migrations\MigrationException;
use App\Database\Migrations\MigrationRepository;
use App\Database\Migrations\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class MigrationReliabilityTest extends TestCase
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
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                    'isolated' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        $this->migrator = new Migrator($this->manager, $this->project->path(), 'isolated');
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->remove();
        }
    }

    public function testTypedSchemaArgumentUsesSelectedConnectionAndSupportsRealBatchCycle(): void
    {
        $parent = '2026_07_01_create_phase6a_parent.php';
        $child = '2026_07_02_create_phase6a_child.php';
        $this->writeMigration($parent, 'CreatePhase6aParent',
            '$schema->create("phase6a_parent", function (\\App\\Database\\Schema\\Table $table): void {'
            . ' $table->id(); $table->string("name", 40); $table->unique("name"); });',
            '$schema->drop("phase6a_parent");', true);
        $this->writeMigration($child, 'CreatePhase6aChild',
            '$schema->create("phase6a_child", function (\\App\\Database\\Schema\\Table $table): void {'
            . ' $table->id(); $table->foreignId("parent_id"); $table->string("name", 40);'
            . ' $table->foreign("parent_id", "phase6a_parent", "id")->onDelete("restrict"); });',
            '$schema->drop("phase6a_child");', true);

        self::assertSame([$parent, $child], $this->migrator->pending());
        self::assertFalse($this->manager->connection('default')->isConnected());
        self::assertSame([$parent, $child], $this->migrator->run());
        self::assertFalse($this->manager->connection('default')->isConnected());
        $pdo = $this->manager->connection('isolated')->pdo();
        self::assertTrue($this->tableExists($pdo, 'phase6a_parent'));
        self::assertTrue($this->tableExists($pdo, 'phase6a_child'));
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame([1, 1], array_column($this->migrator->status(), 'batch'));
        self::assertSame(['applied', 'applied'], array_column($this->migrator->status(), 'state'));

        $id = $this->manager->table('phase6a_parent', 'isolated')->insertId(['name' => 'parent']);
        self::assertSame(1, $this->manager->table('phase6a_child', 'isolated')->insert(['parent_id' => $id, 'name' => 'child']));
        self::assertSame('child', $this->manager->table('phase6a_child', 'isolated')->first()['name']);
        try {
            $this->manager->table('phase6a_child', 'isolated')->insert(['parent_id' => 999, 'name' => 'invalid']);
            self::fail('Expected SQLite to enforce the foreign key.');
        } catch (QueryException $exception) {
            self::assertSame(1, $this->manager->table('phase6a_child', 'isolated')->count());
        }

        self::assertSame([$child, $parent], $this->migrator->rollback());
        self::assertFalse($this->tableExists($pdo, 'phase6a_parent'));
        self::assertFalse($this->tableExists($pdo, 'phase6a_child'));
        self::assertSame([$parent, $child], $this->migrator->run());
        self::assertTrue($this->tableExists($pdo, 'phase6a_parent'));
        self::assertSame([$child, $parent], $this->migrator->reset());
        self::assertFalse($this->tableExists($pdo, 'phase6a_parent'));
        self::assertFalse($this->tableExists($pdo, 'phase6a_child'));
    }

    public function testPendingAndStatusDoNotExecuteMigrationCode(): void
    {
        $file = '2026_08_01_inspect_phase6a_pending.php';
        $this->writeMigration($file, 'InspectPhase6aPending',
            'throw new \\RuntimeException("must not run");', '');

        self::assertSame([$file], $this->migrator->pending());
        self::assertSame([['migration' => $file, 'state' => 'pending', 'batch' => null]], $this->migrator->status());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function testFailureBeforeSchemaChangePreservesCauseAndNoHistory(): void
    {
        $file = '2026_08_02_fail_phase6a_early.php';
        $this->writeMigration($file, 'FailPhase6aEarly',
            'throw new \\RuntimeException("early marker");', '');

        $exception = $this->failure(static fn (Migrator $migrator): array => $migrator->run());
        self::assertSame('up', $exception->phase());
        self::assertFalse($exception->stateUncertain());
        self::assertSame('early marker', $exception->getPrevious()?->getMessage());
        self::assertStringContainsString('isolated', $exception->getMessage());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function testLaterFailedMigrationRollsBackOnlyItsOwnSqliteSchema(): void
    {
        $first = '2026_08_03_create_phase6a_success.php';
        $second = '2026_08_04_create_phase6a_failure.php';
        $this->writeMigration($first, 'CreatePhase6aSuccess',
            '$pdo->exec("CREATE TABLE phase6a_success (id INTEGER PRIMARY KEY)");',
            '$pdo->exec("DROP TABLE phase6a_success");');
        $this->writeMigration($second, 'CreatePhase6aFailure',
            '$pdo->exec("CREATE TABLE phase6a_failure (id INTEGER PRIMARY KEY)");'
            . ' throw new \\RuntimeException("later marker");', '');

        $exception = $this->failure(static fn (Migrator $migrator): array => $migrator->run());
        self::assertSame('up', $exception->phase());
        self::assertFalse($exception->stateUncertain());
        self::assertTrue($this->tableExists($this->pdo(), 'phase6a_success'));
        self::assertFalse($this->tableExists($this->pdo(), 'phase6a_failure'));
        self::assertSame([$first], array_column((new MigrationRepository($this->pdo()))->rows(), 'migration'));
    }

    public function testHistoryWriteFailureRollsBackSqliteSchema(): void
    {
        $file = '2026_08_05_create_phase6a_unrecorded.php';
        $this->writeMigration($file, 'CreatePhase6aUnrecorded',
            '$pdo->exec("CREATE TABLE phase6a_unrecorded (id INTEGER PRIMARY KEY)");',
            '$pdo->exec("DROP TABLE phase6a_unrecorded");');
        $this->migrator->pending();
        $this->pdo()->exec("CREATE TRIGGER phase6a_block_insert BEFORE INSERT ON migrations BEGIN SELECT RAISE(ABORT, 'blocked'); END");

        $exception = $this->failure(static fn (Migrator $migrator): array => $migrator->run());
        self::assertSame('record', $exception->phase());
        self::assertFalse($exception->stateUncertain());
        self::assertStringContainsString('rolled back', $exception->getMessage());
        self::assertInstanceOf(MigrationException::class, $exception->getPrevious());
        self::assertFalse($this->tableExists($this->pdo(), 'phase6a_unrecorded'));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function testDownFailurePreservesTableAndHistory(): void
    {
        $file = '2026_08_06_create_phase6a_downfail.php';
        $this->writeMigration($file, 'CreatePhase6aDownfail',
            '$pdo->exec("CREATE TABLE phase6a_downfail (id INTEGER PRIMARY KEY)");',
            '$pdo->exec("DROP TABLE phase6a_downfail"); throw new \\RuntimeException("down marker");');
        $this->migrator->run();

        $exception = $this->failure(static fn (Migrator $migrator): array => $migrator->rollback());
        self::assertSame('down', $exception->phase());
        self::assertFalse($exception->stateUncertain());
        self::assertSame('down marker', $exception->getPrevious()?->getMessage());
        self::assertTrue($this->tableExists($this->pdo(), 'phase6a_downfail'));
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function testHistoryDeleteFailureRestoresSqliteTableAndRecord(): void
    {
        $file = '2026_08_07_create_phase6a_nodelete.php';
        $this->writeMigration($file, 'CreatePhase6aNodelete',
            '$pdo->exec("CREATE TABLE phase6a_nodelete (id INTEGER PRIMARY KEY)");',
            '$pdo->exec("DROP TABLE phase6a_nodelete");');
        $this->migrator->run();
        $this->pdo()->exec("CREATE TRIGGER phase6a_block_delete BEFORE DELETE ON migrations BEGIN SELECT RAISE(ABORT, 'blocked'); END");

        $exception = $this->failure(static fn (Migrator $migrator): array => $migrator->rollback());
        self::assertSame('remove', $exception->phase());
        self::assertFalse($exception->stateUncertain());
        self::assertTrue($this->tableExists($this->pdo(), 'phase6a_nodelete'));
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function testAppliedMigrationWithMissingFileAppearsInStatusAndBlocksRollback(): void
    {
        $file = '2026_08_08_create_phase6a_missing.php';
        $this->writeMigration($file, 'CreatePhase6aMissing',
            '$pdo->exec("CREATE TABLE phase6a_missing (id INTEGER PRIMARY KEY)");',
            '$pdo->exec("DROP TABLE phase6a_missing");');
        $this->migrator->run();
        unlink($this->project->path('Database/Migrations/' . $file));

        self::assertSame([['migration' => $file, 'state' => 'missing', 'batch' => 1]], $this->migrator->status());
        $exception = $this->failure(static fn (Migrator $migrator): array => $migrator->rollback());
        self::assertStringContainsString('not found', $exception->getMessage());
        self::assertTrue($this->tableExists($this->pdo(), 'phase6a_missing'));
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function testStatusReportsMissingHistoryWhenEntireMigrationDirectoryIsGone(): void
    {
        $file = '2026_08_12_create_phase6a_missingdirectory.php';
        $this->writeMigration($file, 'CreatePhase6aMissingdirectory',
            '$pdo->exec("CREATE TABLE phase6a_missingdirectory (id INTEGER PRIMARY KEY)");',
            '$pdo->exec("DROP TABLE phase6a_missingdirectory");');
        $this->migrator->run();
        unlink($this->project->path('Database/Migrations/' . $file));
        rmdir($this->project->path('Database/Migrations'));

        self::assertSame([['migration' => $file, 'state' => 'missing', 'batch' => 1]], $this->migrator->status());
        self::assertTrue($this->tableExists($this->pdo(), 'phase6a_missingdirectory'));
    }

    public function testEndedManagedTransactionIsReportedAsUncertainWithoutRecordingHistory(): void
    {
        $file = '2026_08_13_end_phase6a_transaction.php';
        $this->writeMigration($file, 'EndPhase6aTransaction',
            '$pdo->exec("ROLLBACK"); throw new \\RuntimeException("original marker");', '');

        $exception = $this->failure(static fn (Migrator $migrator): array => $migrator->run());
        self::assertSame('up', $exception->phase());
        self::assertTrue($exception->stateUncertain());
        self::assertSame('original marker', $exception->getPrevious()?->getMessage());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function testReplacedManagedTransactionCannotClaimSqliteRollback(): void
    {
        $file = '2026_08_14_replace_phase6a_transaction.php';
        $this->writeMigration($file, 'ReplacePhase6aTransaction',
            '$pdo->exec("CREATE TABLE phase6a_committed (id INTEGER PRIMARY KEY)");'
            . ' $pdo->commit(); $pdo->beginTransaction();'
            . ' throw new \\RuntimeException("replacement marker");', '');

        $exception = $this->failure(static fn (Migrator $migrator): array => $migrator->run());
        self::assertSame('up', $exception->phase());
        self::assertTrue($exception->stateUncertain());
        self::assertSame('replacement marker', $exception->getPrevious()?->getMessage());
        self::assertTrue($this->tableExists($this->pdo(), 'phase6a_committed'));
        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function testStatusCannotPrepareHistoryInsideCallerTransaction(): void
    {
        $connection = $this->manager->connection('isolated');
        $connection->begin();
        try {
            $exception = $this->failure(static fn (Migrator $migrator): array => $migrator->status());
            self::assertStringContainsString('active transaction', $exception->getMessage());
            self::assertTrue($connection->pdo()->inTransaction());
        } finally {
            $connection->rollback();
        }
    }

    public function testDuplicateMigrationClassIsRejectedBeforeExecution(): void
    {
        $this->writeMigration('2026_08_09_create_phase6a_duplicate.php', 'CreatePhase6aDuplicate', '', '');
        $this->writeMigration('2026_08_10_create_phase6a_duplicate.php', 'CreatePhase6aDuplicate', '', '');

        $exception = $this->failure(static fn (Migrator $migrator): array => $migrator->pending());
        self::assertStringContainsString('Duplicate migration class', $exception->getMessage());
        $runFailure = $this->failure(static fn (Migrator $migrator): array => $migrator->run());
        self::assertStringContainsString('Duplicate migration class', $runFailure->getMessage());
        self::assertFalse($this->manager->connection('isolated')->isConnected());
    }

    public function testDuplicateHistoryAliasesBlockStatusAndRollbackWithoutDeletingRows(): void
    {
        $first = '2026_08_15_create_phase6a_alias.php';
        $alias = '2026_08_15_Create_phase6a_alias.php';
        $this->writeMigration($first, 'CreatePhase6aAlias',
            '$pdo->exec("CREATE TABLE phase6a_alias (id INTEGER PRIMARY KEY)");',
            '$pdo->exec("DROP TABLE phase6a_alias");');
        $this->migrator->run();
        $insert = $this->pdo()->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
        $insert->execute([$alias, 2]);

        foreach ([
            static fn (Migrator $migrator): array => $migrator->status(),
            static fn (Migrator $migrator): array => $migrator->pending(),
            static fn (Migrator $migrator): array => $migrator->rollback(),
        ] as $operation) {
            $exception = $this->failure($operation);
            self::assertStringContainsString('Duplicate migration history identity', $exception->getMessage());
        }
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        self::assertTrue($this->tableExists($this->pdo(), 'phase6a_alias'));
    }

    public function testRunRejectsAnExistingTransactionWithoutClosingIt(): void
    {
        $file = '2026_08_11_create_phase6a_nested.php';
        $this->writeMigration($file, 'CreatePhase6aNested', '', '');
        $connection = $this->manager->connection('isolated');
        $connection->begin();
        try {
            $exception = $this->failure(static fn (Migrator $migrator): array => $migrator->run());
            self::assertStringContainsString('active transaction', $exception->getMessage());
            self::assertTrue($connection->pdo()->inTransaction());
        } finally {
            $connection->rollback();
        }
    }

    private function writeMigration(string $file, string $class, string $up, string $down, bool $schema = false): void
    {
        $schemaParameter = $schema ? ', \\App\\Database\\Schema\\Schema $schema' : '';
        $this->project->write('Database/Migrations/' . $file,
            '<?php class ' . $class . ' {'
            . ' public function up(\\PDO $pdo' . $schemaParameter . '): void { ' . $up . ' }'
            . ' public function down(\\PDO $pdo' . $schemaParameter . '): void { ' . $down . ' }'
            . ' }');
    }

    private function pdo(): PDO
    {
        return $this->manager->connection('isolated')->pdo();
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }

    /** @param callable(Migrator):mixed $operation */
    private function failure(callable $operation): MigrationException
    {
        try {
            $operation($this->migrator);
            self::fail('Expected a migration failure.');
        } catch (MigrationException $exception) {
            return $exception;
        }
    }
}
