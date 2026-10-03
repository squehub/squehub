<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\ApiResources;

use App\Api\ApiResource;
use App\Api\ResourceCollection;
use App\Api\ResourceException;
use App\Database\Collections\ModelCollection;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\Relations\HasMany;
use App\Foundation\Application;
use App\Http\DispatchResult;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseNormalizer;
use App\Plugins;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises explicit resource boundaries against SQLite and the normal HTTP dispatcher. */
final class ApiResourceIntegrationTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];
    private Application $app;
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for API resource integration tests.');
        }
        $this->app = $this->application();
        $this->manager = $this->app->container()->make(DatabaseManager::class);
        $this->seed($this->manager);
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
        Route::setResolver(null);
        foreach ($this->projects as $project) $project->remove();
    }

    public function testSingleModelExposesOnlyChosenFieldsWithoutChangingItsStateOrLoadingRelations(): void
    {
        $user = ResourceUser::find(1);
        self::assertInstanceOf(ResourceUser::class, $user);
        $attributes = $user->attributes();
        $original = $user->original();
        $modelArray = $user->toArray();
        self::assertArrayHasKey('internal_note', $modelArray);
        self::assertArrayHasKey('email', $modelArray);
        self::assertArrayNotHasKey('password', $modelArray);
        self::assertFalse($user->relationLoaded('posts'));
        $this->startCounting();

        $resource = UserResource::make($user);
        self::assertSame(['id' => 1, 'name' => 'User 1', 'note' => null], $resource->resolve());
        self::assertSame($resource->resolve(), json_decode($resource->response()->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, ResourceCountingStatement::$executions);
        self::assertFalse($user->relationLoaded('posts'));
        self::assertSame($attributes, $user->attributes());
        self::assertSame($original, $user->original());
        self::assertSame($modelArray, $user->toArray());
        self::assertFalse($user->changed());
    }

    public function testModelCollectionRetainsOrderAndItsExistingSerializationContract(): void
    {
        $models = ResourceUser::query()->sort('id', 'desc')->all();
        self::assertInstanceOf(ModelCollection::class, $models);
        $before = $models->toArray();
        $first = $models->first();
        $this->startCounting();

        $resources = UserResource::collection($models);
        self::assertInstanceOf(ResourceCollection::class, $resources);
        self::assertSame([
            ['id' => 3, 'name' => 'User 3', 'note' => null],
            ['id' => 2, 'name' => 'User 2', 'note' => null],
            ['id' => 1, 'name' => 'User 1', 'note' => null],
        ], $resources->resolve());
        self::assertSame($resources->resolve(), json_decode($resources->response()->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, ResourceCountingStatement::$executions);
        self::assertSame($before, $models->toArray());
        self::assertSame($first, $models->first());
        self::assertArrayHasKey('email', $models->toArray()[0]);
    }

    public function testEagerLoadedModelPageUsesExplicitNestedResourcesAndAddsNoQueries(): void
    {
        $page = ResourceUser::query()->with('posts')->sort('id')->page(1, 2);
        self::assertInstanceOf(ModelCollection::class, $page->items());
        $before = $page->toArray();
        $user = $page->items()->first();
        $posts = $user->getRelation('posts');
        self::assertTrue($user->relationLoaded('posts'));
        self::assertInstanceOf(ModelCollection::class, $posts);
        self::assertArrayNotHasKey('posts', $before['items'][0]);
        $this->startCounting();

        $expected = [
            'data' => [
                ['id' => 1, 'name' => 'User 1', 'note' => null, 'posts' => [['id' => 1, 'title' => 'First post']]],
                ['id' => 2, 'name' => 'User 2', 'note' => null, 'posts' => []],
            ],
            'meta' => ['page' => 1, 'per_page' => 2, 'total' => 3, 'pages' => 2,
                'from' => 1, 'to' => 2, 'has_next' => true, 'has_previous' => false],
        ];
        $resources = UserResource::collection($page);
        self::assertSame($expected, $resources->resolve());
        self::assertSame($expected, json_decode($resources->response()->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, ResourceCountingStatement::$executions);
        self::assertSame($before, $page->toArray());
        self::assertSame($posts, $user->getRelation('posts'));
        self::assertFalse($user->changed());
        self::assertSame('private-post-note', $posts->first()->getAttribute('internal_note'));
    }

    public function testRawPageKeepsRowsAndMetadataWhileResourceProjectsPublicColumns(): void
    {
        $page = $this->manager->table('resource_users')->sort('id')->page(2, 2);
        $before = $page->toArray();
        self::assertSame('password-3', $before['items'][0]['password']);
        $this->startCounting();

        self::assertSame([
            'data' => [['id' => 3, 'name' => 'User 3']],
            'meta' => ['page' => 2, 'per_page' => 2, 'total' => 3, 'pages' => 2,
                'from' => 3, 'to' => 3, 'has_next' => false, 'has_previous' => true],
        ], RowResource::collection($page)->resolve());
        self::assertSame(0, ResourceCountingStatement::$executions);
        self::assertSame($before, $page->toArray());
        self::assertSame($before, json_decode($page->toJson(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testMissingModelsEmptyCollectionsAndOutOfRangePagesKeepTheirDistinctShapes(): void
    {
        $missing = ResourceUser::find(999);
        $emptyModels = ResourceUser::query()->filter('id', 999)->all();
        $emptyPage = ResourceUser::query()->filter('id', 999)->page(1, 2);
        $beyond = ResourceUser::query()->sort('id')->page(9, 2);
        $this->startCounting();

        self::assertNull(UserResource::make($missing)->resolve());
        self::assertSame('null', UserResource::make($missing)->response()->content());
        self::assertSame([], UserResource::collection($emptyModels)->resolve());
        self::assertSame([
            'data' => [],
            'meta' => ['page' => 1, 'per_page' => 2, 'total' => 0, 'pages' => 0,
                'from' => null, 'to' => null, 'has_next' => false, 'has_previous' => false],
        ], UserResource::collection($emptyPage)->resolve());
        self::assertSame([
            'data' => [],
            'meta' => ['page' => 9, 'per_page' => 2, 'total' => 3, 'pages' => 2,
                'from' => null, 'to' => null, 'has_next' => false, 'has_previous' => true],
        ], UserResource::collection($beyond)->resolve());
        self::assertSame(0, ResourceCountingStatement::$executions);
    }

    public function testLegacyStaticModelsRemainArraysAndCanBeExplicitlyAdapted(): void
    {
        $row = LegacyResourceUser::find(1);
        $rows = LegacyResourceUser::all();
        self::assertIsArray($row);
        self::assertIsArray($rows);
        self::assertSame('password-1', $row['password']);
        $this->startCounting();

        self::assertSame(['id' => 1, 'name' => 'User 1'], RowResource::make($row)->resolve());
        self::assertSame([
            ['id' => 1, 'name' => 'User 1'], ['id' => 2, 'name' => 'User 2'], ['id' => 3, 'name' => 'User 3'],
        ], RowResource::collection($rows)->resolve());
        self::assertSame(0, ResourceCountingStatement::$executions);
        self::assertSame($row, LegacyResourceUser::find(1));
        self::assertSame($rows, LegacyResourceUser::all());
        self::assertFalse(LegacyResourceUser::find(999));
    }

    public function testExplicitResponsePassesThroughKernelWithStatusHeadersAndNoCapturedEcho(): void
    {
        $user = ResourceUser::find(1);
        $response = UserResource::make($user)->withMeta(['request_id' => 'public-request'])
            ->response(201, ['X-Resource' => 'created', 'content-type' => 'text/plain']);
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/resource', static function () use ($response): JsonResponse {
            echo 'private-controller-output';
            return $response;
        });
        $this->startCounting();

        $actual = $this->handle('/resource');
        self::assertSame($response, $actual);
        self::assertSame(201, $actual->status());
        self::assertSame('created', $actual->header('X-Resource'));
        self::assertSame('application/json; charset=UTF-8', $actual->header('Content-Type'));
        self::assertSame([
            'data' => ['id' => 1, 'name' => 'User 1', 'note' => null],
            'meta' => ['request_id' => 'public-request'],
        ], json_decode($actual->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private-controller-output', $actual->content());
        self::assertSame($response, (new ResponseNormalizer())->normalize(new DispatchResult($response, 'discard')));
        self::assertSame(0, ResourceCountingStatement::$executions);
    }

    public function testHttpPageAndNullResponsesDoNotChangeExistingControllerReturnRules(): void
    {
        $page = UserResource::collection(ResourceUser::query()->sort('id')->page(1, 2));
        $user = UserResource::make(ResourceUser::find(1));
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/page', static fn (): JsonResponse => $page->response(202));
        $routes->get('/missing', static fn (): JsonResponse => UserResource::make(null)->response(404));
        $routes->get('/array', static fn (): array => ['existing' => true]);
        $routes->get('/text', static function (): string { echo 'before:'; return 'after'; });
        $routes->get('/echo', static function (): void { echo 'existing-output'; });
        $routes->get('/unsupported', static fn (): ApiResource => $user);
        $this->startCounting();

        $pageResponse = $this->handle('/page');
        self::assertSame(202, $pageResponse->status());
        self::assertSame($page->resolve(), json_decode($pageResponse->content(), true, 512, JSON_THROW_ON_ERROR));
        $missing = $this->handle('/missing');
        self::assertSame(404, $missing->status());
        self::assertSame('null', $missing->content());
        self::assertSame(['existing' => true], json_decode($this->handle('/array')->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('before:after', $this->handle('/text')->content());
        self::assertSame('existing-output', $this->handle('/echo')->content());
        self::assertSame(500, $this->handle('/unsupported')->status());
        self::assertSame(0, ResourceCountingStatement::$executions);
    }

    public function testResourceFailuresRenderSafeJsonInDebugAndProduction(): void
    {
        $user = ResourceUser::find(1);
        $unsafe = new class($user) extends ApiResource {
            public function toArray(): array { return ['user' => $this->resource]; }
        };
        $broken = new class($user) extends ApiResource {
            public function toArray(): array
            {
                throw new RuntimeException('private-transform-failure: password-1');
            }
        };
        $routes = $this->app->container()->make(RouteRegistry::class);
        foreach (['unsafe' => $unsafe, 'broken' => $broken] as $name => $resource) {
            $routes->get('/' . $name, static function () use ($resource): JsonResponse {
                echo 'private-error-output';
                return $resource->response();
            });
        }
        $this->startCounting();

        foreach ([false, true] as $debug) {
            $this->app->config()->set('app.debug', $debug);
            foreach (['unsafe', 'broken'] as $path) {
                $response = $this->handle('/' . $path);
                self::assertSame(500, $response->status());
                self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
                $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('Internal Server Error', $payload['error']);
                if ($debug) {
                    self::assertSame(ResourceException::class, $payload['exception']);
                } else {
                    self::assertSame(['error' => 'Internal Server Error'], $payload);
                }
                foreach (['password-1', 'private-transform-failure', 'private-error-output',
                    'user1@example.test', 'internal-user-note', 'remember-1'] as $secret) {
                    self::assertStringNotContainsString($secret, $response->content());
                }
            }
        }
        self::assertSame(0, ResourceCountingStatement::$executions);
    }

    public function testResourcesAndMetadataStayLocalAcrossApplications(): void
    {
        $first = UserResource::make(ResourceUser::find(1));
        $firstWithMeta = $first->withMeta(['application' => 'first']);
        $this->app->container()->make(RouteRegistry::class)
            ->get('/resource', static fn (): JsonResponse => $firstWithMeta->response());

        $secondApp = $this->application();
        $secondManager = $secondApp->container()->make(DatabaseManager::class);
        $this->seed($secondManager, 'Second');
        $second = UserResource::make(ResourceUser::find(1));
        $secondApp->container()->make(RouteRegistry::class)
            ->get('/resource', static fn (): JsonResponse => $second->response());
        self::assertNotSame($this->manager, $secondManager);
        $this->startCounting();
        $secondManager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ResourceCountingStatement::class]);

        self::assertSame(['id' => 1, 'name' => 'User 1', 'note' => null], $first->resolve());
        self::assertSame(['id' => 1, 'name' => 'Second 1', 'note' => null], $second->resolve());
        self::assertSame([
            'data' => $first->resolve(), 'meta' => ['application' => 'first'],
        ], json_decode($this->handle('/resource')->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($second->resolve(), json_decode($this->handle('/resource', $secondApp)->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, ResourceCountingStatement::$executions);
    }

    public function testPluginsResourcesAndAliasesInteroperateWithCanonicalNestedResources(): void
    {
        $models = ResourceUser::query()->sort('id')->all();
        $plugin = PluginUserResource::make($models->first());
        $collection = PluginUserResource::collection($models);
        self::assertInstanceOf(ApiResource::class, $plugin);
        self::assertInstanceOf(Plugins\ApiResource::class, $plugin);
        self::assertInstanceOf(ResourceCollection::class, $collection);
        self::assertInstanceOf(Plugins\ResourceCollection::class, $collection);
        $nested = new class($plugin) extends ApiResource {
            public function toArray(): array { return ['owner' => $this->resource]; }
        };
        $reverse = new class(UserResource::make($models->first())) extends Plugins\ApiResource {
            public function toArray(): array { return ['owner' => $this->resource]; }
        };
        $this->startCounting();

        self::assertSame(['owner' => ['id' => 1, 'name' => 'User 1']], $nested->resolve());
        self::assertSame(['owner' => ['id' => 1, 'name' => 'User 1', 'note' => null]], $reverse->resolve());
        self::assertSame([
            ['id' => 1, 'name' => 'User 1'], ['id' => 2, 'name' => 'User 2'], ['id' => 3, 'name' => 'User 3'],
        ], json_decode($collection->response()->content(), true, 512, JSON_THROW_ON_ERROR));
        $unsafe = new class($models->first()) extends Plugins\ApiResource {
            public function toArray(): array { return ['model' => $this->resource]; }
        };
        try {
            $unsafe->resolve();
            self::fail('A raw model must not bypass explicit resource fields.');
        } catch (ResourceException $exception) {
            self::assertInstanceOf(Plugins\ResourceException::class, $exception);
            self::assertStringNotContainsString('password-1', $exception->getMessage());
        }
        self::assertSame(0, ResourceCountingStatement::$executions);
    }

    private function application(): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $project->write('Config/Database.php', '<?php return ["default"=>"main","connections"=>["main"=>["driver"=>"sqlite","database"=>":memory:"]]];');
        $app = new Application($project->path());
        $app->register(DatabaseServiceProvider::class);
        $app->register(HttpServiceProvider::class);
        $app->register(RoutingServiceProvider::class);
        $app->bootstrap();
        return $app;
    }

    private function seed(DatabaseManager $manager, string $prefix = 'User'): void
    {
        $pdo = $manager->connection()->pdo();
        $pdo->exec('CREATE TABLE resource_users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT, remember_token TEXT, internal_note TEXT, note TEXT)');
        $pdo->exec('CREATE TABLE resource_posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, internal_note TEXT)');
        for ($id = 1; $id <= 3; $id++) {
            $manager->table('resource_users')->insert([
                'id' => $id, 'name' => $prefix . ' ' . $id, 'email' => 'user' . $id . '@example.test',
                'password' => 'password-' . $id, 'remember_token' => 'remember-' . $id,
                'internal_note' => 'internal-user-note', 'note' => null,
            ]);
        }
        $manager->table('resource_posts')->insert([
            'id' => 1, 'user_id' => 1, 'title' => 'First post', 'internal_note' => 'private-post-note',
        ]);
    }

    private function startCounting(): void
    {
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ResourceCountingStatement::class]);
        ResourceCountingStatement::$executions = 0;
    }

    private function handle(string $path, ?Application $app = null): Response
    {
        return ($app ?? $this->app)->container()->make(Kernel::class)
            ->handle(new Request('GET', $path, [], [], [], [], ['Accept' => 'application/json']));
    }
}

/** Supplies public and private attributes plus a relation that can be eagerly loaded. */
final class ResourceUser extends Model
{
    protected string $table = 'resource_users';
    protected array $hidden = ['password', 'remember_token'];
    protected array $casts = ['id' => 'integer'];

    public function posts(): HasMany { return $this->hasMany(ResourcePost::class, 'user_id'); }
}

/** Retains internal post fields for verifying explicit nested representations. */
final class ResourcePost extends Model
{
    protected string $table = 'resource_posts';
    protected array $casts = ['id' => 'integer'];
}

/** Exercises the existing static array API against the same isolated table. */
final class LegacyResourceUser extends \App\Core\Model
{
    protected static $table = 'resource_users';
}

/** Publishes selected user fields and includes posts only when already loaded. */
final class UserResource extends ApiResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'note' => $this->resource->note,
            'posts' => $this->when($this->resource->relationLoaded('posts'),
                fn (): ResourceCollection => PostResource::collection($this->resource->getRelation('posts'))),
        ];
    }
}

/** Defines the public fields of each nested post. */
final class PostResource extends ApiResource
{
    public function toArray(): array { return ['id' => $this->resource->id, 'title' => $this->resource->title]; }
}

/** Adapts raw query and legacy model rows through an explicit field selection. */
final class RowResource extends ApiResource
{
    public function toArray(): array { return ['id' => (int) $this->resource['id'], 'name' => $this->resource['name']]; }
}

/** Verifies application resources can extend the public Plugins gateway. */
final class PluginUserResource extends Plugins\ApiResource
{
    public function toArray(): array { return ['id' => $this->resource->id, 'name' => $this->resource->name]; }
}

/** Counts actual statement executions after the query phase has completed. */
final class ResourceCountingStatement extends PDOStatement
{
    public static int $executions = 0;

    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        self::$executions++;
        return parent::execute($params);
    }
}
