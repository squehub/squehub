<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Migrations\MigrationException;
use App\Database\Migrations\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class MigrationConcurrencyTest extends TestCase
{
    private TemporaryProject $project;
    private DatabaseManager $manager;
    private Migrator $migrator;
    private string $databasePath;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for migration concurrency tests.');
        }
        $this->project = new TemporaryProject();
        $this->databasePath = $this->project->path('database.sqlite');
        $this->manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => $this->databasePath]],
        ]]));
        $this->migrator = new Migrator($this->manager, $this->project->path());
        $this->manager->connection()->pdo();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) $this->manager->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    public function testActiveDeploymentLockBlocksRunRollbackAndResetBeforeHistoryReads(): void
    {
        $file = '2026_09_29_create_migration_lock_probe.php';
        $this->project->write('Database/Migrations/' . $file, <<<'PHP'
<?php
class CreateMigrationLockProbe {
    public function up(\PDO $pdo): void { $pdo->exec('CREATE TABLE migration_lock_probe (id INTEGER PRIMARY KEY)'); }
    public function down(\PDO $pdo): void { $pdo->exec('DROP TABLE migration_lock_probe'); }
}
PHP);
        $handle = fopen($this->lockPath(), 'c+b');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            foreach (['run', 'rollback', 'reset'] as $operation) {
                try {
                    $this->migrator->{$operation}();
                    self::fail("{$operation} should reject a concurrent runner.");
                } catch (MigrationException $failure) {
                    self::assertStringContainsString('Another migration runner is active', $failure->getMessage());
                }
            }
            self::assertSame(0, (int) $this->manager->connection()->raw(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='migrations'"
            )->fetchColumn());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        self::assertSame([$file], $this->migrator->run());
        self::assertSame([], $this->migrator->run());
        self::assertSame([$file], $this->migrator->rollback());
    }

    public function testMigrationFailureReleasesDeploymentLock(): void
    {
        $file = '2026_09_29_fail_migration_lock_probe.php';
        $this->project->write('Database/Migrations/' . $file, <<<'PHP'
<?php
class FailMigrationLockProbe {
    public function up(\PDO $pdo): void { throw new \RuntimeException('expected failure'); }
    public function down(\PDO $pdo): void {}
}
PHP);
        try {
            $this->migrator->run();
            self::fail('The migration should fail.');
        } catch (MigrationException $failure) {
            self::assertStringContainsString('failed during up()', $failure->getMessage());
        }

        $handle = fopen($this->lockPath(), 'c+b');
        self::assertNotFalse($handle);
        try {
            self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
            self::assertSame(0, (int) $this->manager->connection()->raw(
                'SELECT COUNT(*) FROM migrations'
            )->fetchColumn());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testDeploymentLockIsHonoredAcrossPhpProcesses(): void
    {
        $file = '2026_09_29_create_process_lock_probe.php';
        $this->project->write('Database/Migrations/' . $file, <<<'PHP'
<?php
class CreateProcessLockProbe {
    public function up(\PDO $pdo): void { $pdo->exec('CREATE TABLE process_lock_probe (id INTEGER PRIMARY KEY)'); }
    public function down(\PDO $pdo): void { $pdo->exec('DROP TABLE process_lock_probe'); }
}
PHP);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $source = "<?php\nrequire " . var_export($autoload, true) . ";\n" . <<<'PHP'
$manager = new \App\Database\DatabaseManager(new \App\Config\Repository(['database' => [
    'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $argv[1]]],
]]));
try {
    (new \App\Database\Migrations\Migrator($manager, $argv[2]))->run();
    echo 'ran';
} catch (\App\Database\Migrations\MigrationException $exception) {
    echo 'blocked';
    exit(10);
}
PHP;
        $this->project->write('RunMigration.php', $source);
        $command = [PHP_BINARY, $this->project->path('RunMigration.php'),
            $this->databasePath, $this->project->path()];

        $handle = fopen($this->lockPath(), 'c+b');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            $blocked = new Process($command, dirname(__DIR__, 2));
            $blocked->run();
            self::assertSame(10, $blocked->getExitCode());
            self::assertSame('blocked', $blocked->getOutput());
            self::assertFalse($this->manager->schema()->hasTable('process_lock_probe'));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $allowed = new Process($command, dirname(__DIR__, 2));
        $allowed->run();
        self::assertTrue($allowed->isSuccessful(), $allowed->getErrorOutput());
        self::assertSame('ran', $allowed->getOutput());
        self::assertTrue($this->manager->schema()->hasTable('process_lock_probe'));
    }

    private function lockPath(): string
    {
        $canonical = realpath($this->databasePath);
        self::assertNotFalse($canonical);
        return $canonical . '.squehub-migrations.lock';
    }
}
