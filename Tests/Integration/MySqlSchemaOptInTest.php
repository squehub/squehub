<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Migrations\MigrationException;
use App\Database\Migrations\Migrator;
use App\Database\Model;
use App\Database\Schema\Table;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/**
 * Opt-in real-server verification, confined to an explicitly confirmed empty
 * squehub_test_* database. Normal application database settings are never read.
 *
 * @group mysql
 */
final class MySqlSchemaOptInTest extends TestCase
{
    public function testSchemaForeignKeysTransactionsAndMigrationPartialFailure(): void
    {
        if (getenv('SQUEHUB_TEST_MYSQL_ENABLED') !== '1') {
            self::markTestSkipped('Set SQUEHUB_TEST_MYSQL_ENABLED=1 and explicit disposable test database settings.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_mysql is required for the opt-in MySQL integration test.');
        }

        $host = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_HOST');
        $port = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_PORT');
        $database = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_DATABASE');
        $user = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_USER');
        $password = getenv('SQUEHUB_TEST_MYSQL_PASSWORD');
        $confirmation = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_CONFIRM_DATABASE');
        self::assertNotFalse($password, 'Set SQUEHUB_TEST_MYSQL_PASSWORD explicitly, even when empty.');
        self::assertMatchesRegularExpression('/\Asquehub_test_[A-Za-z0-9_]+\z/D', $database);
        self::assertSame($database, $confirmation, 'Test database confirmation must match exactly.');

        $manager = new DatabaseManager(new Repository([
            'database' => [
                'default' => 'disposable',
                'connections' => [
                    'disposable' => [
                        'driver' => 'mysql',
                        'host' => $host,
                        'port' => $port,
                        'database' => $database,
                        'username' => $user,
                        'password' => $password,
                        'charset' => 'utf8mb4',
                    ],
                ],
            ],
        ]));
        $pdo = $manager->connection()->pdo();
        self::assertSame($database, $pdo->query('SELECT DATABASE()')->fetchColumn());
        $version = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        $comment = strtolower((string) $pdo->query('SELECT @@version_comment')->fetchColumn());
        self::assertStringNotContainsString('mariadb', $version . $comment, 'This test verifies MySQL semantics.');
        self::assertSame('innodb', strtolower((string) $pdo->query('SELECT @@default_storage_engine')->fetchColumn()));
        // Serialize cooperating test runs before checking emptiness; the
        // shared legacy migrations table has a fixed name.
        $lockName = 'squehub_phase6a_' . substr(hash('sha256', $database), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        self::assertSame(1, (int) $lock->fetchColumn(), 'Another Phase 6A MySQL test is using this database.');
        try {
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM),
                'The confirmed test database must be empty before this test may create tables.');
            fwrite(STDERR, "Opt-in MySQL server: {$version} ({$comment}), default engine InnoDB.\n");

            $project = new TemporaryProject();
            try {
                $schema = $manager->schema();
                $schema->create('phase6b_mysql_models', static function (Table $table): void {
                    $table->id();
                    $table->string('name', 80);
                    $table->boolean('active');
                    $table->text('prefs');
                });
                Database::setResolver(static fn (): DatabaseManager => $manager);
                try {
                    $model = Phase6bMysqlModel::create([
                        'name' => 'first', 'active' => true, 'prefs' => ['theme' => 'dark'],
                    ]);
                    self::assertTrue($model->exists());
                    $loaded = Phase6bMysqlModel::find($model->getAttribute('id'));
                    self::assertInstanceOf(Phase6bMysqlModel::class, $loaded);
                    self::assertTrue($loaded->getAttribute('active'));
                    self::assertSame(['theme' => 'dark'], $loaded->getAttribute('prefs'));

                    // MySQL can count zero changed rows for a matched UPDATE.
                    $loaded->setAttribute('name', 'second');
                    $manager->table('phase6b_mysql_models')->filter('id', $model->getAttribute('id'))
                        ->update(['name' => 'second']);
                    self::assertTrue($loaded->save());
                    self::assertFalse($loaded->changed());
                    self::assertTrue($loaded->delete());
                } finally {
                    Database::setResolver(null);
                }

                $schema->create('phase6a_mysql_parents', static function (Table $table): void {
                    $table->id();
                    $table->string('name', 80);
                    $table->unique('name');
                });
                $schema->create('phase6a_mysql_children', static function (Table $table): void {
                    $table->id();
                    $table->foreignId('parent_id');
                    $table->string('label', 80);
                    $table->foreign('parent_id', 'phase6a_mysql_parents', 'id')
                        ->onDelete('restrict')->onUpdate('cascade');
                });
                $schema->create('phase6a_mysql_nullable', static function (Table $table): void {
                    $table->id();
                    $table->foreignId('parent_id')->nullable();
                    $table->foreign('parent_id', 'phase6a_mysql_parents', 'id')
                        ->onDelete('set null')->onUpdate('cascade');
                });
                $schema->create('phase6a_mysql_cascade', static function (Table $table): void {
                    $table->id();
                    $table->foreignId('parent_id');
                    $table->foreign('parent_id', 'phase6a_mysql_parents', 'id')
                        ->onDelete('cascade')->onUpdate('cascade');
                });
                self::assertTrue($schema->hasTable('phase6a_mysql_children'));
                self::assertTrue($schema->hasColumn('phase6a_mysql_children', 'parent_id'));
                $parentIndexes = $schema->indexes('phase6a_mysql_parents');
                self::assertContains(['name'], array_column(
                    array_filter($parentIndexes, static fn (array $index): bool => $index['unique']),
                    'columns'
                ));
                $childForeignKeys = $schema->foreignKeys('phase6a_mysql_children');
                self::assertCount(1, $childForeignKeys);
                self::assertSame(['parent_id'], $childForeignKeys[0]['columns']);
                self::assertSame('phase6a_mysql_parents', $childForeignKeys[0]['referenced_table']);
                self::assertSame(['id'], $childForeignKeys[0]['referenced_columns']);
                $engines = $pdo->query(
                    "SELECT table_name, engine FROM information_schema.tables
                     WHERE table_schema = DATABASE()
                       AND table_name IN ('phase6a_mysql_parents', 'phase6a_mysql_children',
                                          'phase6a_mysql_nullable', 'phase6a_mysql_cascade')"
                )->fetchAll(PDO::FETCH_KEY_PAIR);
                self::assertCount(4, $engines);
                foreach ($engines as $engine) {
                    self::assertSame('innodb', strtolower((string) $engine));
                }
                $pdo->exec("INSERT INTO phase6a_mysql_parents (name) VALUES ('one')");
                $pdo->exec("INSERT INTO phase6a_mysql_children (parent_id, label) VALUES (1, 'valid')");
                $pdo->exec('INSERT INTO phase6a_mysql_nullable (parent_id) VALUES (1)');
                $pdo->exec('INSERT INTO phase6a_mysql_cascade (parent_id) VALUES (1)');
                try {
                    $pdo->exec("INSERT INTO phase6a_mysql_children (parent_id, label) VALUES (999, 'invalid')");
                    self::fail('MySQL must enforce the foreign key.');
                } catch (PDOException $exception) {
                    self::assertSame('23000', $exception->getCode());
                }
                try {
                    $pdo->exec('DELETE FROM phase6a_mysql_parents WHERE id = 1');
                    self::fail('RESTRICT must prevent deletion of a referenced parent.');
                } catch (PDOException $exception) {
                    self::assertSame('23000', $exception->getCode());
                }
                $pdo->exec('UPDATE phase6a_mysql_parents SET id = 5 WHERE id = 1');
                foreach (['phase6a_mysql_children', 'phase6a_mysql_nullable', 'phase6a_mysql_cascade'] as $table) {
                    self::assertSame(5, (int) $pdo->query('SELECT parent_id FROM `' . $table . '`')->fetchColumn());
                }
                try {
                    $manager->transaction(static function () use ($manager): void {
                        $manager->raw('INSERT INTO phase6a_mysql_parents (name) VALUES (?)', ['rolled back']);
                        throw new RuntimeException('intentional transaction failure');
                    });
                    self::fail('The transaction callback must fail.');
                } catch (RuntimeException $exception) {
                    self::assertSame('intentional transaction failure', $exception->getMessage());
                }
                self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM phase6a_mysql_parents')->fetchColumn());
                $pdo->exec('DELETE FROM phase6a_mysql_children WHERE parent_id = 5');
                $pdo->exec('DELETE FROM phase6a_mysql_parents WHERE id = 5');
                self::assertNull($pdo->query('SELECT parent_id FROM phase6a_mysql_nullable')->fetchColumn());
                self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM phase6a_mysql_cascade')->fetchColumn());

                $project->write('Database/Migrations/2026_09_01_create_phase6a_mysql_migrated.php', <<<'PHP'
    <?php
    class CreatePhase6aMysqlMigrated
    {
        public function up(\PDO $pdo, \App\Database\Schema\Schema $schema): void
        {
            $schema->create('phase6a_mysql_migrated', function (\App\Database\Schema\Table $table): void {
                $table->id();
                $table->string('name', 40);
            });
        }

        public function down(\PDO $pdo, \App\Database\Schema\Schema $schema): void
        {
            $schema->drop('phase6a_mysql_migrated');
        }
    }
    PHP);
                $migrator = new Migrator($manager, $project->path());
                self::assertSame(['2026_09_01_create_phase6a_mysql_migrated.php'], $migrator->run());
                self::assertTrue($schema->hasTable('phase6a_mysql_migrated'));
                self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());

                $project->write('Database/Migrations/2026_09_02_fail_phase6a_mysql_partial.php', <<<'PHP'
    <?php
    class FailPhase6aMysqlPartial
    {
        public function up(\PDO $pdo, \App\Database\Schema\Schema $schema): void
        {
            $schema->create('phase6a_mysql_partial', function (\App\Database\Schema\Table $table): void {
                $table->id();
            });
            throw new \RuntimeException('intentional MySQL migration failure');
        }

        public function down(\PDO $pdo, \App\Database\Schema\Schema $schema): void
        {
            $schema->dropIfExists('phase6a_mysql_partial');
        }
    }
    PHP);
                try {
                    $migrator->run();
                    self::fail('The MySQL migration should fail after its DDL.');
                } catch (MigrationException $exception) {
                    self::assertTrue($exception->stateUncertain());
                    self::assertSame('up', $exception->phase());
                    self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
                }
                self::assertTrue($schema->hasTable('phase6a_mysql_partial'));
                self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
                self::assertSame(['2026_09_01_create_phase6a_mysql_migrated.php'], $migrator->rollback());
                self::assertFalse($schema->hasTable('phase6a_mysql_migrated'));
                self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
            } finally {
                // Emptiness plus the advisory lock makes these test-owned tables.
                foreach (['phase6a_mysql_children', 'phase6a_mysql_nullable',
                    'phase6a_mysql_cascade', 'phase6a_mysql_parents',
                    'phase6a_mysql_partial', 'phase6a_mysql_migrated',
                    'phase6b_mysql_models', 'migrations'] as $table) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
                }
                $project->remove();
            }
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (\Throwable $releaseException) {
                // Closing this PDO also releases its advisory lock; do not mask
                // a schema or migration failure with a secondary cleanup error.
            }
        }
    }

    private function requiredEnvironment(string $key): string
    {
        $value = getenv($key);
        self::assertIsString($value, "Set {$key} for the disposable MySQL test.");
        self::assertNotSame('', $value, "Set {$key} for the disposable MySQL test.");
        return $value;
    }
}

final class Phase6bMysqlModel extends Model
{
    protected string $table = 'phase6b_mysql_models';
    protected array $fillable = ['name', 'active', 'prefs'];
    protected array $casts = ['active' => 'boolean', 'prefs' => 'array'];
}
