<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\Phase6GCli;

use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Runs canonical Seeder commands only against a disposable SQLite project. */
final class SeederCliTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', "<?php return ['env' => 'testing'];");
        $this->project->write('Config/Database.php', <<<'PHP'
<?php
return ['default' => 'test', 'connections' => ['test' => [
    'driver' => 'sqlite', 'database' => dirname(__DIR__) . '/seed.sqlite',
]]];
PHP);
        $repo = dirname(__DIR__, 2);
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
            [var_export($repo . '/vendor/autoload.php', true), var_export($repo . '/squehub', true)],
            $runner
        ));
        $this->project->write('Database/Seeders/DatabaseSeeder.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class DatabaseSeeder extends \App\Database\Seeding\Seeder {
    public function run(): void { $this->call([NamedSeeder::class]); }
}
PHP);
        $this->project->write('Database/Seeders/NamedSeeder.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class NamedSeeder extends \App\Database\Seeding\Seeder {
    public function run(): void {
        \App\Database\Database::manager()->table('seed_log')->insert(['name' => 'seeder']);
    }
}
PHP);
        $this->project->write('Database/Seeders/ReversibleSeeder.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class ReversibleSeeder extends \App\Plugins\Seeder implements \App\Plugins\ReversibleSeeder {
    public function run(): void {
        \App\Database\Database::manager()->table('seed_log')->insert(['name' => 'reversible']);
    }
    public function rollback(): void {
        \App\Database\Database::manager()->table('seed_log')->filter('name', 'reversible')->delete();
    }
}
PHP);
        $pdo = new PDO('sqlite:' . $this->project->path('seed.sqlite'));
        $pdo->exec('CREATE TABLE seed_log (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->remove();
        }
    }

    public function testRootSpecificProductionForceRollbackAndStatus(): void
    {
        $root = $this->command('seed');
        self::assertTrue($root->isSuccessful(), $root->getOutput() . $root->getErrorOutput());
        self::assertStringContainsString('Database seeded.', $root->getOutput());
        self::assertSame(['seeder'], $this->rows());

        $specific = $this->command('seed', 'NamedSeeder');
        self::assertTrue($specific->isSuccessful());
        self::assertSame(['seeder', 'seeder'], $this->rows());

        $this->project->write('Config/App.php', "<?php return ['env' => 'production'];");
        $refused = $this->command('seed');
        self::assertFalse($refused->isSuccessful());
        self::assertStringContainsString('without --force', $refused->getOutput());
        self::assertSame(['seeder', 'seeder'], $this->rows());

        $forced = $this->command('seed', 'NamedSeeder', '--force');
        self::assertTrue($forced->isSuccessful(), $forced->getOutput() . $forced->getErrorOutput());
        self::assertSame(['seeder', 'seeder', 'seeder'], $this->rows());

        $status = $this->command('seed:status');
        self::assertTrue($status->isSuccessful(), $status->getOutput() . $status->getErrorOutput());
        self::assertStringContainsString('DatabaseSeeder', $status->getOutput());
        self::assertStringContainsString('NamedSeeder', $status->getOutput());
        self::assertStringContainsString('ReversibleSeeder', $status->getOutput());
        self::assertStringContainsString('history is not persisted', strtolower($status->getOutput()));
        self::assertSame(['seeder', 'seeder', 'seeder'], $this->rows());

        self::assertTrue($this->command('seed', 'ReversibleSeeder', '--force')->isSuccessful());
        self::assertSame(['seeder', 'seeder', 'seeder', 'reversible'], $this->rows());
        $blockedRollback = $this->command('seed:rollback', 'ReversibleSeeder');
        self::assertFalse($blockedRollback->isSuccessful());
        self::assertStringContainsString('without --force', $blockedRollback->getOutput());
        self::assertSame(['seeder', 'seeder', 'seeder', 'reversible'], $this->rows());

        $nonreversible = $this->command('seed:rollback', 'NamedSeeder', '--force');
        self::assertFalse($nonreversible->isSuccessful());
        self::assertSame(['seeder', 'seeder', 'seeder', 'reversible'], $this->rows());
        self::assertTrue($this->command('seed:rollback', 'ReversibleSeeder', '--force')->isSuccessful());
        self::assertSame(['seeder', 'seeder', 'seeder'], $this->rows());
        self::assertFalse($this->command('seed', 'MissingSeeder', '--force')->isSuccessful());
    }

    public function testSeederRollbackLeavesMigrationHistoryAndSchemaUntouched(): void
    {
        $this->project->write('Database/Migrations/2026_09_27_create_seeded_reports.php', <<<'PHP'
<?php
namespace Database\Migrations;
use App\Plugins\Schema;
use App\Plugins\Table;
final class CreateSeededReports {
    public function up(\PDO $pdo, Schema $schema): void {
        $schema->create('seeded_reports', static function (Table $table): void { $table->id(); });
    }
    public function down(\PDO $pdo, Schema $schema): void { $schema->dropIfExists('seeded_reports'); }
}
PHP);
        $migration = $this->command('migrate');
        self::assertTrue($migration->isSuccessful(), $migration->getOutput() . $migration->getErrorOutput());
        self::assertSame('seeded_reports', $this->tableName('seeded_reports'));

        self::assertTrue($this->command('seed', 'ReversibleSeeder')->isSuccessful());
        self::assertSame(['reversible'], $this->rows());
        self::assertTrue($this->command('seed:rollback', 'ReversibleSeeder')->isSuccessful());
        self::assertSame([], $this->rows());
        self::assertSame('seeded_reports', $this->tableName('seeded_reports'));
        $status = $this->command('migrate:status');
        self::assertTrue($status->isSuccessful());
        self::assertStringContainsString('2026_09_27_create_seeded_reports.php', $status->getOutput());
        self::assertStringContainsString('applied', strtolower($status->getOutput()));

        $rollback = $this->command('migrate:rollback');
        self::assertTrue($rollback->isSuccessful(), $rollback->getOutput() . $rollback->getErrorOutput());
        self::assertFalse($this->tableName('seeded_reports'));
    }

    public function testSeederLoadFailureDoesNotExposeApplicationDetailsOnCli(): void
    {
        $secret = 'SQUEHUB_SEED_SECRET_DO_NOT_LEAK';
        $this->project->write('Database/Seeders/TopLevelThrowSeeder.php',
            '<?php throw new \\RuntimeException(' . var_export($secret, true) . ');');
        $this->project->write('Database/Seeders/MalformedSeeder.php',
            '<?php namespace Database\\Seeders; final class MalformedSeeder { public function run(: void {}');

        foreach (['TopLevelThrowSeeder', 'MalformedSeeder'] as $name) {
            foreach ([['seed', $name], ['seed:rollback', $name]] as $arguments) {
                $run = $this->command(...$arguments);
                self::assertFalse($run->isSuccessful(), implode(' ', $arguments));
                $output = $run->getOutput() . $run->getErrorOutput();
                self::assertStringContainsString($name, $output);
                self::assertStringNotContainsString($secret, $output);
                self::assertStringNotContainsString('Fatal error', $output);
                self::assertStringNotContainsString('Stack trace', $output);
            }
        }
        self::assertSame([], $this->rows());
    }

    private function tableName(string $name): string|false
    {
        $pdo = new PDO('sqlite:' . $this->project->path('seed.sqlite'));
        $statement = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $statement->execute([$name]);
        return $statement->fetchColumn();
    }

    /** @return list<string> */
    private function rows(): array
    {
        $pdo = new PDO('sqlite:' . $this->project->path('seed.sqlite'));
        return $pdo->query('SELECT name FROM seed_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function command(string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, $this->project->path('CliRunner.php'), ...$arguments], $this->project->path());
        $process->run();
        return $process;
    }
}
