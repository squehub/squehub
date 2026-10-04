<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\ConnectionFactory;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Exception\QueryException;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\Diagnostic;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Confirms live SQLite metrics cover raw and typed QueryBuilder statements. */
final class DatabaseDiagnosticsTest extends TestCase
{
    public function testAttemptsAreCountedPerConnectionWithoutQueryMaterial(): void
    {
        $config = new Repository([
            'database' => ['default' => 'main', 'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'other' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ]],
            'diagnostics' => ['database' => true, 'slow_query_ms' => 0.0],
        ]);
        $diagnostics = new Diagnostics($config);
        $manager = new DatabaseManager($config, new ConnectionFactory(), null, $diagnostics);
        $diagnostics->begin(new Request());
        $manager->raw('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $manager->table('items')->insert(['name' => 'private-item']);
        self::assertSame(1, $manager->table('items')->count());
        self::assertSame('private-item', $manager->table('items')->filter('id', 1)->first()['name']);
        $manager->raw('SELECT 1', [], 'other');
        try {
            $manager->raw('SELECT * FROM missing_private_table');
            self::fail('Expected failed statement.');
        } catch (QueryException) {
        }
        $manager->begin();
        $manager->commit();
        $snapshot = $diagnostics->snapshot();
        self::assertSame(6, $snapshot['database']['queries']);
        self::assertSame(6, $snapshot['database']['slow_queries']);
        self::assertSame(0.0, $snapshot['database']['slow_query_threshold_ms']);
        self::assertSame(5, $snapshot['database']['connections']['main']['queries']);
        self::assertSame(5, $snapshot['database']['connections']['main']['slow_queries']);
        self::assertSame(1, $snapshot['database']['connections']['other']['queries']);
        self::assertSame(1, $snapshot['database']['connections']['other']['slow_queries']);
        self::assertGreaterThanOrEqual(0.0, $snapshot['database']['time_ms']);
        self::assertStringNotContainsString('private-item', json_encode($snapshot));
        self::assertStringNotContainsString('missing_private_table', json_encode($snapshot));
        $page = $manager->table('items')->page(1, 1);
        self::assertSame(1, $page->total());
        self::assertSame(8, $diagnostics->queryCount()); // COUNT and SELECT each execute once.
        $diagnostics->begin(new Request('GET', '/next'));
        self::assertSame(0, $diagnostics->queryCount());
        self::assertSame(0, $diagnostics->snapshot()['database']['slow_queries']);
        self::assertSame([], $diagnostics->snapshot()['database']['connections']);
        $diagnostics->finish(new Response('done'));
        $manager->raw('SELECT 1');
        self::assertSame(0, $diagnostics->queryCount());
    }

    public function testCollectorIsOptionalAndDatabaseMetricsCanBeDisabled(): void
    {
        $config = new Repository(['diagnostics' => ['database' => false, 'slow_query_ms' => 0.0]]);
        $collector = new Diagnostics($config);
        $collector->begin(new Request());
        $collector->query('main', 2.5);
        self::assertSame(0, $collector->queryCount());
        self::assertSame(0.0, $collector->queryTimeMs());
        self::assertSame(0, $collector->snapshot()['database']['slow_queries']);
    }

    public function testSlowThresholdCountsOnlyQualifiedAttemptsAndResetsPerRequest(): void
    {
        $config = new Repository(['diagnostics' => [
            'database' => true, 'slow_query_ms' => 5.0,
        ]]);
        $collector = new Diagnostics($config);
        $collector->begin(new Request());
        $collector->query('main', 4.9);
        $collector->query('main', 5.0);
        $collector->query('other', 9.0);
        $snapshot = $collector->snapshot();
        self::assertSame(3, $snapshot['database']['queries']);
        self::assertSame(2, $snapshot['database']['slow_queries']);
        self::assertSame(5.0, $snapshot['database']['slow_query_threshold_ms']);
        self::assertSame(1, $snapshot['database']['connections']['main']['slow_queries']);
        self::assertSame(1, $snapshot['database']['connections']['other']['slow_queries']);

        $collector->begin(new Request('GET', '/next'));
        self::assertSame(0, $collector->snapshot()['database']['slow_queries']);
        self::assertSame([], $collector->snapshot()['database']['connections']);

        foreach ([null, -1, '5', INF, NAN] as $invalid) {
            $disabled = new Diagnostics(new Repository(['diagnostics' => [
                'slow_query_ms' => $invalid,
            ]]));
            $disabled->begin(new Request());
            $disabled->query('main', 10.0);
            self::assertSame(1, $disabled->queryCount());
            self::assertNull($disabled->snapshot()['database']['slow_query_threshold_ms']);
            self::assertSame(0, $disabled->snapshot()['database']['slow_queries']);
        }
    }

    public function testDatabaseProviderSharesTheApplicationCollector(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Database.php', '<?php return ["default" => "main", "connections" => ["main" => ["driver" => "sqlite", "database" => ":memory:"]]];');
            $app = new Application($project->path());
            $app->register(DiagnosticsServiceProvider::class);
            $app->register(DatabaseServiceProvider::class);
            $app->bootstrap();
            $collector = $app->container()->make(Diagnostics::class);
            $manager = $app->container()->make(DatabaseManager::class);
            self::assertFalse($manager->connection()->isConnected());
            $collector->begin(new Request());
            $manager->raw('SELECT 1');
            self::assertSame(1, $collector->queryCount());
            self::assertSame(1, $collector->snapshot()['database']['connections']['main']['queries']);
        } finally {
            Diagnostic::setResolver(null);
            $project->remove();
        }
    }
}
