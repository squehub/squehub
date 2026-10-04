<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Exception\QueryException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Runs the real console migration commands in a disposable SQLite project. */
final class MigrationCliSchemaTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for CLI migration integration tests.');
        }

        $this->project = new TemporaryProject();
        $this->project->write('Config/Database.php', <<<'PHP'
<?php
return [
    'default' => 'isolated',
    'connections' => [
        'isolated' => [
            'driver' => 'sqlite',
            'database' => dirname(__DIR__) . '/phase6a.sqlite',
        ],
    ],
];
PHP);
        $root = dirname(__DIR__, 2);
        $runner = <<<'PHP'
<?php
declare(strict_types=1);
require_once __AUTOLOAD__;
$squehubApp = new \App\Foundation\Application(__DIR__);
$squehubApp->register(\App\Database\DatabaseServiceProvider::class);
$squehubApp->bootstrap();
require __CLI__;
PHP;
        $this->project->write('CliRunner.php', str_replace(
            ['__AUTOLOAD__', '__CLI__'],
            [var_export($root . '/vendor/autoload.php', true), var_export($root . '/squehub', true)],
            $runner
        ));

        $this->project->write('Database/Migrations/2026_08_01_create_phase6a_cli_parents.php', <<<'PHP'
<?php
class CreatePhase6aCliParents
{
    public function up(\PDO $pdo, \App\Database\Schema\Schema $schema): void
    {
        if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new \RuntimeException('SQLite foreign keys are disabled.');
        }
        $schema->create('phase6a_cli_parents', function (\App\Database\Schema\Table $table): void {
            $table->id();
            $table->string('name', 80);
            $table->unique('name');
        });
    }

    public function down(\PDO $pdo, \App\Database\Schema\Schema $schema): void
    {
        $schema->drop('phase6a_cli_parents');
    }
}
PHP);
        $this->project->write('Database/Migrations/2026_08_02_create_phase6a_cli_children.php', <<<'PHP'
<?php
class CreatePhase6aCliChildren
{
    public function up(\PDO $pdo, \App\Database\Schema\Schema $schema): void
    {
        $schema->create('phase6a_cli_children', function (\App\Database\Schema\Table $table): void {
            $table->id();
            $table->foreignId('parent_id');
            $table->string('label', 80);
            $table->foreign('parent_id', 'phase6a_cli_parents', 'id')
                ->onDelete('restrict')
                ->onUpdate('cascade');
        });
    }

    public function down(\PDO $pdo, \App\Database\Schema\Schema $schema): void
    {
        $schema->drop('phase6a_cli_children');
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->remove();
        }
    }

    public function testConsoleCommandsManageActualSchemaAndForeignKeys(): void
    {
        self::assertFileDoesNotExist($this->project->path('phase6a.sqlite'));
        $status = $this->command('migrate:status');
        self::assertStringContainsString('pending', $status);
        self::assertStringContainsString('2026_08_01_create_phase6a_cli_parents.php', $status);
        self::assertStringContainsString('2026_08_02_create_phase6a_cli_children.php', $status);

        $migrated = $this->command('migrate');
        self::assertStringContainsString('2026_08_01_create_phase6a_cli_parents.php', $migrated);
        self::assertStringContainsString('2026_08_02_create_phase6a_cli_children.php', $migrated);
        self::assertStringContainsString('Nothing to migrate.', $this->command('migrate'));
        $appliedStatus = $this->command('migrate:status');
        self::assertSame(2, substr_count($appliedStatus, 'applied'));

        $manager = new DatabaseManager(new Repository([
            'database' => [
                'default' => 'isolated',
                'connections' => [
                    'isolated' => ['driver' => 'sqlite', 'database' => $this->project->path('phase6a.sqlite')],
                ],
            ],
        ]));

        $pdo = new PDO('sqlite:' . $this->project->path('phase6a.sqlite'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        self::assertSame(2, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('phase6a_cli_parents', 'phase6a_cli_children')"
        )->fetchColumn());
        $parentId = $manager->table('phase6a_cli_parents')->insertId(['name' => 'parent']);
        self::assertSame('1', $parentId);
        self::assertSame(1, $manager->table('phase6a_cli_children')->insert([
            'parent_id' => (int) $parentId,
            'label' => 'valid',
        ]));
        self::assertSame('valid', $manager->table('phase6a_cli_children')
            ->filter('parent_id', (int) $parentId)->first()['label']);
        try {
            $manager->table('phase6a_cli_children')->insert(['parent_id' => 999, 'label' => 'invalid']);
            self::fail('Foreign key enforcement should reject an orphan.');
        } catch (QueryException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame('23000', $exception->getPrevious()->getCode());
        }
        unset($pdo);

        $rolledBack = $this->command('migrate:rollback');
        self::assertStringContainsString('2026_08_02_create_phase6a_cli_children.php', $rolledBack);
        self::assertStringContainsString('2026_08_01_create_phase6a_cli_parents.php', $rolledBack);

        $pdo = new PDO('sqlite:' . $this->project->path('phase6a.sqlite'));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('phase6a_cli_parents', 'phase6a_cli_children')"
        )->fetchColumn());
        unset($pdo);

        $this->command('migrate');
        $this->command('migrate:reset');
        $pdo = new PDO('sqlite:' . $this->project->path('phase6a.sqlite'));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('phase6a_cli_parents', 'phase6a_cli_children')"
        )->fetchColumn());
    }

    private function command(string $name): string
    {
        $process = new Process([PHP_BINARY, $this->project->path('CliRunner.php'), $name], $this->project->path());
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        return $process->getOutput();
    }
}
