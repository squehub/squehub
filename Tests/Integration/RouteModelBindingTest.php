<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Api\Contract\ContractManager;
use App\Api\Contract\ContractServiceProvider;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Diagnostics\Diagnostic;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Packages\PackageManager;
use App\Plugins\Route as PluginRoute;
use App\Routing\Route;
use App\Routing\RouteMatcher;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use Closure;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises explicit binding through the real router, ORM, and HTTP error boundary. */
final class RouteModelBindingTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];
    private Application $app;
    private DatabaseManager $database;
    private Diagnostics $diagnostics;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for route Model binding tests.');
        }
        $this->app = $this->application();
        $this->database = $this->app->container()->make(DatabaseManager::class);
        $this->diagnostics = $this->app->container()->make(Diagnostics::class);
        $this->createTables($this->database);
    }

    protected function tearDown(): void
    {
        Route::setResolver(null);
        Database::setResolver(null);
        Diagnostic::setResolver(null);
        foreach ($this->projects as $project) {
            $project->remove();
        }
    }

    public function testExplicitPrimaryKeyBindingPreservesRawRequestValueAndUsesOneQuery(): void
    {
        $this->database->table('binding_users')->insert([
            'slug' => 'ada', 'name' => 'Ada', 'team_id' => 1,
        ]);
        $route = PluginRoute::path('/users/{user}')
            ->get([BoundUserController::class, 'show'])
            ->named('users.show')
            ->bind('user', BoundUser::class);
        self::assertSame($route, $this->app->container()->make(RouteRegistry::class)->all()[0]);
        self::assertSame(['user' => ['model' => BoundUser::class, 'key' => null]],
            $route->modelBindings());
        self::assertSame(0, $this->diagnostics->queryCount());

        $request = new Request('GET', '/users/1', query: ['user' => 'query']);
        $response = $this->handle($request);
        self::assertSame(200, $response->status(), $response->content());
        self::assertSame(['id' => '1', 'name' => 'Ada', 'raw' => '1'], $this->decode($response));
        self::assertSame(['user' => '1'], $request->route());
        self::assertSame('query', $request->query('user'));
        self::assertSame(1, $this->diagnostics->queryCount());
        self::assertSame(['user' => ['model' => BoundUser::class, 'key' => null]],
            $route->modelBindings(), 'Resolved Models must not enter reusable route metadata.');
    }

    public function testUnboundScalarRouteAndUnmatchedBoundRouteDoNotQuery(): void
    {
        PluginRoute::path('/plain/{id}')->get(static fn (string $id): string => $id);
        PluginRoute::path('/users/{user}')->get(static fn (BoundUser $user): string => $user->name)
            ->bind('user', BoundUser::class);

        self::assertSame('literal', $this->handle(new Request('GET', '/plain/literal'))->content());
        self::assertSame(0, $this->diagnostics->queryCount());
        self::assertSame(404, $this->handle(new Request('GET', '/absent/1'))->status());
        self::assertSame(0, $this->diagnostics->queryCount());
        self::assertSame(405, $this->handle(new Request('POST', '/users/1'))->status());
        self::assertSame(0, $this->diagnostics->queryCount());
    }

    public function testTypeHintAloneCannotTriggerAnUndeclaredModelLookup(): void
    {
        PluginRoute::path('/implicit/{user}')
            ->get(static fn (BoundUser $user): string => $user->name);

        self::assertSame(500, $this->handle(new Request('GET', '/implicit/1'))->status());
        self::assertSame(0, $this->diagnostics->queryCount());
    }

    public function testInvalidDeclarationsAreRejectedBeforeAnyLookup(): void
    {
        $route = PluginRoute::path('/users/{user}')
            ->get(static fn (BoundUser $user): string => $user->name);
        foreach ([
            static fn () => $route->bind('missing', BoundUser::class),
            static fn () => $route->bind('user', BoundUser::class, 'slug;DROP TABLE binding_users'),
            static fn () => $route->bind('user', \stdClass::class),
            static fn () => $route->bind('user', BoundAbstractUser::class),
        ] as $declare) {
            $caught = null;
            try {
                $declare();
            } catch (Throwable $exception) {
                $caught = $exception;
            }
            self::assertInstanceOf(Throwable::class, $caught,
                'An invalid Model binding declaration was accepted.');
            self::assertNotSame('', $caught->getMessage());
        }
        $route->bind('user', BoundUser::class);
        $caught = null;
        try {
            $route->bind('user', BoundUser::class);
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        self::assertInstanceOf(Throwable::class, $caught,
            'One route parameter cannot receive two Model binding declarations.');
        self::assertSame(0, $this->diagnostics->queryCount());
    }

    public function testCustomRouteKeyUsesPreparedValueAndRejectsMissingOrEncodedSeparator(): void
    {
        $this->database->table('binding_users')->insert([
            'slug' => "o'hara", 'name' => 'Quoted', 'team_id' => 1,
        ]);
        PluginRoute::path('/by-slug/{user}')
            ->get(static fn (BoundUser $user): string => $user->name)
            ->bind('user', BoundUser::class, 'slug');

        self::assertSame('Quoted', $this->handle(new Request('GET', '/by-slug/o%27hara'))->content());
        self::assertSame(1, $this->diagnostics->queryCount());
        $attack = $this->handle(new Request('GET', '/by-slug/%27%20OR%201%3D1--'));
        self::assertSame(404, $attack->status());
        self::assertSame(1, $this->diagnostics->queryCount());
        self::assertStringNotContainsString('binding_users', $attack->content());
        self::assertSame(404, $this->handle(new Request('GET', '/by-slug/a%2Fb'))->status());
        self::assertSame(0, $this->diagnostics->queryCount());
    }

    public function testUnicodeLongAndNonnumericValuesRemainOrdinaryBoundInputs(): void
    {
        $this->database->table('binding_users')->insert([
            'slug' => 'álîçé', 'name' => 'Unicode', 'team_id' => 1,
        ]);
        PluginRoute::path('/unicode/{user}')
            ->get(static fn (BoundUser $user): string => $user->name)
            ->bind('user', BoundUser::class, 'slug');
        PluginRoute::path('/numeric/{user}')
            ->get(static fn (BoundUser $user): string => $user->name)
            ->bind('user', BoundUser::class);

        self::assertSame('Unicode', $this->handle(new Request('GET', '/unicode/' . rawurlencode('álîçé')))->content());
        self::assertSame(1, $this->diagnostics->queryCount());
        $long = str_repeat('x', 4096);
        $missing = $this->handle(new Request('GET', '/unicode/' . $long));
        self::assertSame(404, $missing->status());
        self::assertSame(1, $this->diagnostics->queryCount());
        self::assertStringNotContainsString($long, $missing->content());
        self::assertSame(404, $this->handle(new Request('GET', '/numeric/not-a-number'))->status());
        self::assertSame(1, $this->diagnostics->queryCount());
    }

    public function testNamedConnectionAndStringPrimaryKeyAreRespected(): void
    {
        $this->database->table('binding_archive_users', 'archive')->insert([
            'uuid' => 'user-9c42', 'name' => 'Archive',
        ]);
        PluginRoute::path('/archive/{user}')
            ->get(static fn (BoundArchiveUser $user): string => $user->name)
            ->bind('user', BoundArchiveUser::class);

        self::assertSame('Archive', $this->handle(new Request('GET', '/archive/user-9c42'))->content());
        self::assertSame(1, $this->diagnostics->queryCount());
        self::assertSame(1, $this->diagnostics->snapshot()['database']['connections']['archive']['queries']);
        self::assertArrayNotHasKey('main', $this->diagnostics->snapshot()['database']['connections']);
    }

    public function testTwoBindingsAreIndependentAndDoNotInferRelationshipScoping(): void
    {
        $this->database->table('binding_teams')->insert(['name' => 'First']);
        $this->database->table('binding_teams')->insert(['name' => 'Second']);
        $this->database->table('binding_users')->insert([
            'slug' => 'ada', 'name' => 'Ada', 'team_id' => 2,
        ]);
        PluginRoute::path('/teams/{team}/members/{member}')
            ->get(static fn (BoundTeam $team, BoundUser $member, Request $request): array => [
                'team' => $team->name,
                'member' => $member->name,
                'actual_team_id' => $member->team_id,
                'raw' => $request->route(),
            ])
            ->bind('team', BoundTeam::class)
            ->bind('member', BoundUser::class);

        $response = $this->handle(new Request('GET', '/teams/1/members/1'));
        self::assertSame(200, $response->status(), $response->content());
        self::assertSame(['team' => 'First', 'member' => 'Ada', 'actual_team_id' => 2,
            'raw' => ['team' => '1', 'member' => '1']], $this->decode($response));
        self::assertSame(2, $this->diagnostics->queryCount());
    }

    public function testGroupedRouteUsesItsFinalUriAndPreservesGroupMiddlewareOrder(): void
    {
        $this->database->table('binding_users')->insert([
            'slug' => 'grouped', 'name' => 'Grouped user', 'team_id' => 1,
        ]);
        $probe = new BindingPipelineProbe();
        PluginRoute::group()->prefix('/group')
            ->through(new BindingProbeMiddleware($probe, $this->diagnostics, false))
            ->routes(static function (): void {
                PluginRoute::path('/users/{user}')
                    ->get(static fn (BoundUser $user): string => $user->name)
                    ->named('group.users.show')
                    ->bind('user', BoundUser::class);
            });

        self::assertSame('/group/users/1',
            $this->app->container()->make(RouteRegistry::class)->url('group.users.show', ['user' => 1]));
        self::assertSame('Grouped user',
            $this->handle(new Request('GET', '/group/users/1'))->content());
        self::assertSame(['before:1:0', 'after:1'], $probe->events);
        self::assertSame(1, $this->diagnostics->queryCount());
    }

    public function testMissingAndSoftDeletedModelsUseSafeBrowserAndApi404Responses(): void
    {
        $this->database->table('binding_users')->insert([
            'slug' => 'private-marker', 'name' => 'Private', 'team_id' => 1,
            'deleted_at' => '2026-01-01 00:00:00',
        ]);
        foreach (['/users/{user}', '/api/users/{user}'] as $path) {
            PluginRoute::path($path)->get(static fn (BoundUser $user): string => $user->name)
                ->bind('user', BoundUser::class);
        }

        $browser = $this->handle(new Request('GET', '/users/1'));
        self::assertSame(404, $browser->status());
        self::assertStringNotContainsString('binding_users', $browser->content());
        self::assertStringNotContainsString('private-marker', $browser->content());
        self::assertSame(1, $this->diagnostics->queryCount());

        $api = $this->handle(new Request('GET', '/api/users/1'));
        self::assertSame(404, $api->status(), $api->content());
        self::assertSame('not_found', $this->decode($api)['error']['code']);
        self::assertSame('The requested resource was not found.', $this->decode($api)['error']['message']);
        self::assertSame($api->header('X-Request-ID'), $this->decode($api)['request_id']);
        self::assertStringNotContainsString('binding_users', $api->content());
        self::assertStringNotContainsString('private-marker', $api->content());
        self::assertSame(1, $this->diagnostics->queryCount());
    }

    public function testMissingBoundModelHonorsBrowserCustom404WithoutAffectingApiEnvelope(): void
    {
        PluginRoute::error(404, static fn (): Response => new Response('Custom missing page', 200));
        PluginRoute::path('/users/{user}')
            ->get(static fn (BoundUser $user): string => $user->name)
            ->bind('user', BoundUser::class);
        PluginRoute::path('/api/users/{user}')
            ->get(static fn (BoundUser $user): string => $user->name)
            ->bind('user', BoundUser::class);

        $browser = $this->handle(new Request('GET', '/users/999'));
        self::assertSame(404, $browser->status());
        self::assertSame('Custom missing page', $browser->content());
        self::assertSame(1, $this->diagnostics->queryCount());

        $api = $this->handle(new Request('GET', '/api/users/999'));
        self::assertSame(404, $api->status());
        self::assertSame('not_found', $this->decode($api)['error']['code']);
        self::assertStringNotContainsString('Custom missing page', $api->content());
        self::assertSame(1, $this->diagnostics->queryCount());
    }

    public function testMiddlewareReceivesRawValueAndCanShortCircuitBeforeBinding(): void
    {
        $this->database->table('binding_users')->insert([
            'slug' => 'ada', 'name' => 'Ada', 'team_id' => 1,
        ]);
        $probe = new BindingPipelineProbe();
        PluginRoute::path('/blocked/{user}')
            ->get(static function (BoundUser $user) use ($probe): string {
                $probe->events[] = 'controller';
                return $user->name;
            })
            ->bind('user', BoundUser::class)
            ->through(new BindingProbeMiddleware($probe, $this->diagnostics, true));
        PluginRoute::path('/allowed/{user}')
            ->get(static function (BoundUser $user) use ($probe): string {
                $probe->events[] = 'controller';
                return $user->name;
            })
            ->bind('user', BoundUser::class)
            ->through(new BindingProbeMiddleware($probe, $this->diagnostics, false));

        $blocked = $this->handle(new Request('GET', '/blocked/1'));
        self::assertSame(403, $blocked->status());
        self::assertSame(['before:1:0'], $probe->events);
        self::assertSame(0, $this->diagnostics->queryCount());

        $probe->events = [];
        self::assertSame('Ada', $this->handle(new Request('GET', '/allowed/1'))->content());
        self::assertSame(['before:1:0', 'controller', 'after:1'], $probe->events);
        self::assertSame(1, $this->diagnostics->queryCount());
    }

    public function testBindingIsRequestLocalAndApplicationsKeepSeparateDatabases(): void
    {
        $this->database->table('binding_users')->insert([
            'slug' => 'first', 'name' => 'First app', 'team_id' => 1,
        ]);
        $firstRoute = $this->app->container()->make(RouteRegistry::class)
            ->get('/users/{user}', static fn (BoundUser $user): string => $user->name)
            ->bind('user', BoundUser::class);
        $second = $this->application();
        $secondDatabase = $second->container()->make(DatabaseManager::class);
        $this->createTables($secondDatabase);
        $secondDatabase->table('binding_users')->insert([
            'slug' => 'second', 'name' => 'Second app', 'team_id' => 1,
        ]);
        $secondRoutes = $second->container()->make(RouteRegistry::class);
        $secondRoutes->get('/users/{user}', static fn (string $user): string => 'raw:' . $user);
        $secondRoutes->get('/bound/{user}', static fn (BoundUser $user): string => $user->name)
            ->bind('user', BoundUser::class);

        self::assertSame('raw:1', $this->handle(new Request('GET', '/users/1'), $second)->content());
        self::assertSame(0, $second->container()->make(Diagnostics::class)->queryCount());
        self::assertSame('Second app', $this->handle(new Request('GET', '/bound/1'), $second)->content());
        self::assertSame('First app', $this->handle(new Request('GET', '/users/1'))->content());
        $this->database->table('binding_users')->filter('id', 1)->update(['name' => 'First changed']);
        self::assertSame('First changed', $this->handle(new Request('GET', '/users/1'))->content());
        self::assertSame(1, $this->diagnostics->queryCount());
        self::assertSame($firstRoute, $this->app->container()->make(RouteRegistry::class)->all()[0]);
        self::assertCount(1, $this->app->container()->make(RouteRegistry::class)->all());
        self::assertCount(2, $secondRoutes->all());
    }

    public function testReusingOneRequestStillResolvesAChangedModelEachTime(): void
    {
        $this->database->table('binding_users')->insert([
            'slug' => 'reused', 'name' => 'Before', 'team_id' => 1,
        ]);
        $route = PluginRoute::path('/users/{user}')
            ->get(static fn (BoundUser $user): string => $user->name)
            ->bind('user', BoundUser::class);
        $request = new Request('GET', '/users/1');

        self::assertSame('Before', $this->handle($request)->content());
        self::assertSame(1, $this->diagnostics->queryCount());
        $this->database->table('binding_users')->filter('id', 1)->update(['name' => 'After']);
        self::assertSame('After', $this->handle($request)->content());
        self::assertSame(1, $this->diagnostics->queryCount());
        self::assertSame('1', $request->route('user'));
        self::assertSame(['user' => ['model' => BoundUser::class, 'key' => null]],
            $route->modelBindings());
    }

    public function testRegistrationMatchingInspectionAndContractExportNeverResolveModels(): void
    {
        $app = $this->application();
        $database = $app->container()->make(DatabaseManager::class);
        $diagnostics = $app->container()->make(Diagnostics::class);
        $diagnostics->begin(new Request());
        $route = $app->container()->make(RouteRegistry::class)
            ->get('/api/users/{user}', static fn (BoundUser $user): string => $user->name)
            ->bind('user', BoundUser::class)
            ->named('users.show')
            ->contract((new OperationContract())->path('user', Schema::string())
                ->response(200, Schema::string()));

        self::assertFalse($database->connection()->isConnected());
        self::assertSame([$route], $app->container()->make(RouteRegistry::class)->all());
        self::assertSame(['user' => '1'], (new RouteMatcher())->match(
            $app->container()->make(RouteRegistry::class), new Request('GET', '/api/users/1')
        )->parameters);
        self::assertArrayHasKey('/api/users/{user}',
            $app->container()->make(ContractManager::class)->openApi()['paths']);
        self::assertFalse($database->connection()->isConnected());
        self::assertSame(0, $diagnostics->queryCount());
    }

    public function testEnabledPackageRouteCanDeclareTheSameBinding(): void
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $project->write('Project/Packages/BoundPackage/BoundPackage.php',
            '<?php namespace Project\\Packages\\BoundPackage; final class BoundPackage extends \\App\\Plugins\\ServiceProvider {}');
        $project->write('Project/Packages/BoundPackage/Routes/Web.php', <<<'PHP'
<?php
\App\Plugins\Route::path('/package-users/{user}')
    ->get(static fn (\SqueHub\Tests\Integration\BoundUser $user): string => $user->name)
    ->bind('user', \SqueHub\Tests\Integration\BoundUser::class);
PHP);
        $manager = new PackageManager(new Application($project->path()));
        $manager->apply($manager->planEnable('BoundPackage'));
        $app = $this->boot($project);
        $database = $app->container()->make(DatabaseManager::class);
        $this->createTables($database);
        $database->table('binding_users')->insert([
            'slug' => 'package', 'name' => 'Package user', 'team_id' => 1,
        ]);

        $squehubApp = $app;
        require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';
        self::assertSame('Package user',
            $this->handle(new Request('GET', '/package-users/1'), $app)->content());
        self::assertSame(1, $app->container()->make(Diagnostics::class)->queryCount());
    }

    private function application(): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        return $this->boot($project);
    }

    private function boot(TemporaryProject $project): Application
    {
        $config = [
            'App' => ['env' => 'production', 'debug' => false],
            'Api' => ['enabled' => true, 'paths' => ['/api']],
            'Database' => ['default' => 'main', 'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'archive' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ]],
            'Diagnostics' => ['database' => true],
        ];
        foreach ($config as $name => $values) {
            $project->write('Config/' . $name . '.php', '<?php return ' . var_export($values, true) . ';');
        }
        $app = new Application($project->path());
        foreach ([DiagnosticsServiceProvider::class, DatabaseServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class,
            ContractServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        return $app;
    }

    private function createTables(DatabaseManager $database): void
    {
        $database->connection('main')->pdo()->exec(
            'CREATE TABLE binding_users (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, name TEXT, team_id INTEGER, deleted_at TEXT NULL)'
        );
        $database->connection('main')->pdo()->exec(
            'CREATE TABLE binding_teams (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)'
        );
        $database->connection('archive')->pdo()->exec(
            'CREATE TABLE binding_archive_users (uuid TEXT PRIMARY KEY, name TEXT)'
        );
    }

    private function handle(Request $request, ?Application $app = null): Response
    {
        return ($app ?? $this->app)->container()->make(Kernel::class)->handle($request);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        return json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
    }
}

/**
 * Main-connection fixture with the ordinary soft-delete policy.
 *
 * @property int|string $id
 * @property string $name
 * @property int|string $team_id
 */
final class BoundUser extends Model
{
    protected string $table = 'binding_users';
    protected bool $softDeletes = true;
    protected array $fillable = ['slug', 'name', 'team_id'];
}

/**
 * The configured key and connection must govern lookup.
 *
 * @property string $name
 */
final class BoundArchiveUser extends Model
{
    protected string $table = 'binding_archive_users';
    protected string $primaryKey = 'uuid';
    protected ?string $connection = 'archive';
    protected array $fillable = ['uuid', 'name'];
}

/** @property string $name */
final class BoundTeam extends Model
{
    protected string $table = 'binding_teams';
    protected array $fillable = ['name'];
}

/** An abstract subclass is a Model type but cannot be reconstructed. */
abstract class BoundAbstractUser extends Model
{
    protected string $table = 'binding_users';
}

final class BoundUserController
{
    public function show(BoundUser $user, Request $request): array
    {
        return ['id' => (string) $user->id, 'name' => $user->name,
            'raw' => $request->route('user')];
    }
}

final class BindingPipelineProbe
{
    /** @var list<string> */
    public array $events = [];
}

/** Shows middleware sees the scalar before the controller's Model lookup. */
final class BindingProbeMiddleware
{
    public function __construct(
        private BindingPipelineProbe $probe,
        private Diagnostics $diagnostics,
        private bool $block
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->probe->events[] = 'before:' . $request->route('user')
            . ':' . $this->diagnostics->queryCount();
        if ($this->block) {
            return new Response('blocked', 403);
        }
        $response = $next($request);
        $this->probe->events[] = 'after:' . $this->diagnostics->queryCount();
        return $response;
    }
}
