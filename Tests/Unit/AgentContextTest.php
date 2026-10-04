<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Agent\AgentException;
use App\Agent\AgentManager;
use App\Agent\AgentOutput;
use App\Database\DatabaseManager;
use App\Database\Schema\Table;
use App\Foundation\Application;
use App\Routing\RouteCache;
use App\Routing\RoutingServiceProvider;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/** Agent context is derived from bounded metadata without loading project route code. */
final class AgentContextTest extends TestCase
{
    public function testDefaultStartupAndRouteResourcesDoNotExecuteDeclarationsOrWriteCache(): void
    {
        $project = TestApplication::temporary();
        try {
            $marker = $project->path('route-executed.txt');
            $project->write('Project/Routes/Web.php', '<?php file_put_contents('
                . var_export($marker, true) . ', "executed");');
            $app = $project->application();
            $agent = new AgentManager($app);
            self::assertFileDoesNotExist($marker);
            self::assertFileDoesNotExist($project->path('Storage/Cache/Framework/Routes.lock'));
            $status = $agent->status();
            $framework = $agent->resource('squehub://framework');
            $routes = $agent->resource('squehub://routes');
            $contract = $agent->resource('squehub://application/contract');
            self::assertSame('2.0.0', $status['framework']['version']);
            self::assertArrayNotHasKey('release', $status['framework']);
            self::assertSame('2025-11-25', $framework['protocol']['supported_stdio']);
            self::assertSame('path-first', $framework['routing']['style']);
            self::assertSame('partial', $routes['state']);
            self::assertSame([], $routes['items']);
            self::assertSame('partial', $contract['state']);
            self::assertSame([], $contract['operations']);
            self::assertFileDoesNotExist($marker);
            self::assertFileDoesNotExist($project->path('Storage/Cache/Framework/Routes.lock'));
        } finally {
            $project->cleanup();
        }
    }

    public function testRegisteredRoutesAndActualCliInventoryAreExposedWithoutPrivateConfig(): void
    {
        $project = TestApplication::temporary(['app' => ['key' => 'AGENT_PRIVATE_VALUE']]);
        try {
            $app = $project->application();
            $app->container()->make(\App\Routing\RouteRegistry::class)
                ->get('/hello', static fn (): string => 'ok')->named('hello');
            $agent = new AgentManager($app, [
                ['name' => 'route:list', 'description' => 'List registered routes.'],
            ]);
            $framework = $agent->resource('squehub://framework');
            self::assertSame('registered', $framework['cli']['state']);
            self::assertSame('route:list', $framework['cli']['items'][0]['name']);
            self::assertContains('App\\Plugins\\Route', $framework['public_api']['symbols']);
            $routes = $agent->resource('squehub://routes');
            self::assertSame('partial', $routes['state']);
            self::assertSame('hello', $routes['items'][0]['name']);
            self::assertStringNotContainsString('AGENT_PRIVATE_VALUE',
                json_encode([$framework, $routes, $agent->status()], JSON_THROW_ON_ERROR));
            self::assertSame('unavailable', (new AgentManager($app))->resource('squehub://cli')['state']);
        } finally {
            $project->cleanup();
        }
    }

    public function testValidatedRouteCacheCanBeInspectedWithoutLoadingRoutePhp(): void
    {
        $project = TestApplication::temporary();
        try {
            $project->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Plugins\Route::path('/cached')
    ->get(['Project\\Controllers\\CachedController', 'show'])->named('cached.show');
PHP);
            self::assertSame(1, (new RouteCache($project->application()))->build()['routes']);
            $fresh = new Application($project->root());
            $fresh->register(RoutingServiceProvider::class);
            $fresh->bootstrap();
            $agent = new AgentManager($fresh);
            $routes = $agent->resource('squehub://routes');
            self::assertSame('observed', $routes['state']);
            self::assertSame('validated_cache', $routes['source']);
            self::assertFalse($routes['route_sources_loaded']);
            self::assertSame(['cached.show'], array_column($routes['items'], 'name'));
            self::assertSame('observed', $agent->resource('squehub://application/contract')['state']);
        } finally {
            $project->cleanup();
        }
    }

    public function testDocsSearchIsBoundedAndSecretsInExcerptsAreRedacted(): void
    {
        $project = TestApplication::temporary();
        try {
            $project->write('Documentation/Agent.md', "Agent routing example.\nAPP_KEY=PRIVATE_EXAMPLE routing\n");
            $project->write('Docs/V2.x/Internal.md', 'INTERNAL_DOCS_SENTINEL');
            $agent = new AgentManager($project->application());
            $result = $agent->tool('search_docs', ['query' => 'routing', 'limit' => 1]);
            self::assertSame('observed', $result['state']);
            self::assertCount(1, $result['items']);
            self::assertSame('Documentation/Agent.md', $result['items'][0]['path']);
            self::assertTrue($result['truncated']);
            $second = $agent->tool('search_docs', ['query' => 'PRIVATE_EXAMPLE']);
            self::assertStringNotContainsString('PRIVATE_EXAMPLE',
                json_encode($second, JSON_THROW_ON_ERROR));
            $internal = $agent->tool('search_docs', ['query' => 'INTERNAL_DOCS_SENTINEL']);
            self::assertSame([], $internal['items']);
            foreach ([['query' => '.'], ['query' => "route\nsecret"],
                ['query' => 'routing', 'limit' => 21]] as $bad) {
                try {
                    $agent->tool('search_docs', $bad);
                    self::fail('Unbounded search arguments must be rejected.');
                } catch (AgentException) {
                    self::assertTrue(true);
                }
            }
        } finally {
            $project->cleanup();
        }
    }

    public function testSchemaInspectorUsesOnlyGrantedConnectionAndNeverReturnsRows(): void
    {
        $project = TestApplication::temporary(['agent' => ['grants' => [
            'read_schema' => ['connections' => ['testing']],
        ]]]);
        try {
            $app = $project->application();
            $database = $app->container()->make(DatabaseManager::class);
            $database->schema()->create('agent_records', static function (Table $table): void {
                $table->id();
                $table->string('private_value');
                $table->index('private_value');
            });
            $database->table('agent_records')->insert(['private_value' => 'ROW_SECRET_NEVER_EXPORT']);
            $agent = new AgentManager($app);
            $result = $agent->tool('inspect_schema', ['table' => 'agent_records']);
            self::assertTrue($result['exists']);
            self::assertSame('testing', $result['connection']);
            self::assertFalse($result['records_exposed']);
            self::assertNotEmpty($result['indexes']);
            self::assertStringNotContainsString('ROW_SECRET_NEVER_EXPORT',
                json_encode($result, JSON_THROW_ON_ERROR));
            try {
                $agent->tool('inspect_schema', ['table' => 'agent_records', 'connection' => 'production']);
                self::fail('An ungranted database must not be opened.');
            } catch (AgentException) {
                self::assertTrue(true);
            }
        } finally {
            $project->cleanup();
        }
    }

    public function testDefaultHealthMetadataDoesNotRevealConfiguredConnectionNames(): void
    {
        $marker = 'DB_PASSWORD_AGENT_SENTINEL';
        $project = TestApplication::temporary([
            'database' => ['connections' => [
                $marker => ['driver' => 'sqlite', 'database' => ':memory:'],
            ]],
            'queue' => ['default' => $marker,
                'connections' => [$marker => ['driver' => 'sync']]],
        ]);
        try {
            $agent = new AgentManager($project->application());
            $health = $agent->resource('squehub://health');
            self::assertSame('configured', $health['infrastructure']['state']);
            self::assertArrayHasKey('listed_connection_count', $health['infrastructure']['database']);
            self::assertArrayNotHasKey('connection', $health['queue']);
            self::assertArrayNotHasKey('items', $health['scheduler']);
            self::assertStringNotContainsString($marker,
                json_encode($health, JSON_THROW_ON_ERROR));
            self::assertNotContains('squehub://schema', array_column($agent->resources(), 'uri'));
        } finally {
            $project->cleanup();
        }
    }

    public function testFinalOutputGuardRemovesCredentialLabelsAndRejectsOversizedData(): void
    {
        $safe = AgentOutput::safe(['app_key' => 'DO_NOT_LEAK',
            'message' => 'Authorization: Bearer SECRET_SENTINEL']);
        self::assertSame('[redacted]', $safe['app_key']);
        self::assertStringNotContainsString('SECRET_SENTINEL', $safe['message']);
        $this->expectException(AgentException::class);
        AgentOutput::safe(['oversized' => str_repeat('x', 8193)]);
    }
}
