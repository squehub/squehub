<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Api\ApiResource;
use App\Api\Contract\Contract as CanonicalContract;
use App\Api\Contract\ContractException;
use App\Api\Contract\ContractManager;
use App\Api\Contract\ContractServiceProvider;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
use App\Foundation\Application;
use App\Plugins\Contract;
use App\Plugins\ContractSchema;
use App\Plugins\Route as PluginRoute;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises the declared contract through real Application services and path-first routes. */
final class ApplicationContractTest extends TestCase
{
    private const SECRET = 'SQUEHUB_CONTRACT_SECRET_DO_NOT_LEAK';

    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        CanonicalContract::setResolver(null);
        Route::setResolver(null);
        foreach ($this->projects as $project) $project->remove();
    }

    public function testPathFirstRoutesRemainExplicitAndValidatePlaceholderContracts(): void
    {
        $app = $this->application();
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/api/internals', static fn (): string => 'hidden');
        $routes->get('/api/also-internal', static fn (): string => 'hidden')
            ->contract((new OperationContract('hidden.route'))->internal());
        $routes->get('/api/users/{id}', static fn (): string => 'ready')
            ->named('users.show')
            ->contract((new OperationContract())
                ->path('id', Schema::integer())
                ->query('include', Schema::string())
                ->response(200, Schema::string(), 'One user'));

        $native = $app->container()->make(ContractManager::class)->application();
        self::assertSame('1', $native['squehub_contract']);
        self::assertCount(1, $native['operations']);
        self::assertSame('users.show', $native['operations'][0]['operation_id']);
        self::assertSame('/api/users/{id}', $native['operations'][0]['path']);
        self::assertSame(['path', 'query'], array_column($native['operations'][0]['parameters'], 'in'));
        $oas = $app->container()->make(ContractManager::class)->openApi();
        self::assertArrayHasKey('/api/users/{id}', $oas['paths']);
        self::assertArrayNotHasKey('/api/internals', $oas['paths']);
        self::assertArrayNotHasKey('/api/also-internal', $oas['paths']);
    }

    public function testMismatchedPathParametersFailBeforeExport(): void
    {
        $app = $this->application();
        $app->container()->make(RouteRegistry::class)
            ->get('/api/users/{id}', static fn (): string => 'ready')
            ->named('users.show')
            ->contract((new OperationContract())->path('user', Schema::integer())
                ->response(200, Schema::string()));

        $this->expectException(ContractException::class);
        $app->container()->make(ContractManager::class)->application();
    }

    public function testDuplicateOperationIdsFailEvenForDistinctRoutes(): void
    {
        $app = $this->application();
        $routes = $app->container()->make(RouteRegistry::class);
        foreach (['/api/a', '/api/b'] as $path) {
            $routes->get($path, static fn (): string => 'ready')
                ->contract((new OperationContract('same.id'))
                    ->response(200, Schema::string()));
        }

        $this->expectException(ContractException::class);
        $app->container()->make(ContractManager::class)->openApi();
    }

    public function testRegisteredAndRecursiveSchemasAndResourceSchemaNeverRunResourceCode(): void
    {
        $app = $this->application();
        $manager = $app->container()->make(ContractManager::class);
        $manager->schema('Category', Schema::object([
            'name' => Schema::string(),
            'children' => Schema::array(Schema::ref('Category')),
        ])->required(['name', 'children']));
        $manager->resource('User', ContractUserResource::class);
        $app->container()->make(RouteRegistry::class)
            ->get('/api/users/{id}', static fn (): string => 'ready')
            ->named('users.show')
            ->contract((new OperationContract())->path('id', Schema::integer())
                ->response(200, Schema::ref('User')));

        $document = $manager->openApi();
        self::assertSame('#/components/schemas/Category', $document['components']['schemas']
            ['Category']['properties']['children']['items']['$ref']);
        self::assertSame('object', $document['components']['schemas']['User']['type']);
        self::assertSame('#/components/schemas/User', $document['paths']['/api/users/{id}']
            ['get']['responses']['200']['content']['application/json']['schema']['$ref']);
        self::assertSame(0, ContractUserResource::$transformations);
    }

    public function testDuplicateSchemaAndUnresolvedReferenceFailClearly(): void
    {
        $app = $this->application();
        $manager = $app->container()->make(ContractManager::class);
        $manager->schema('User', Schema::string());
        try {
            $manager->schema('User', Schema::integer());
            self::fail('Duplicate schema registration must fail.');
        } catch (ContractException) {
            self::assertSame(['type' => 'string'], $manager->application()['schemas']['User']);
        }

        $app->container()->make(RouteRegistry::class)
            ->get('/api/missing', static fn (): string => 'ready')
            ->named('missing.show')
            ->contract((new OperationContract())->response(200, Schema::ref('Missing')));
        $this->expectException(ContractException::class);
        $manager->application();
    }

    public function testApiErrorValidationAndPaginationReuseActualPublicEnvelope(): void
    {
        $app = $this->application();
        $app->container()->make(RouteRegistry::class)
            ->get('/api/users', static fn (): string => 'ready')
            ->named('users.index')
            ->contract((new OperationContract())->pat(['users.read'])
                ->paginatedResponse(200, Schema::string())
                ->error(401)->error(422)->error(429));

        $document = $app->container()->make(ContractManager::class)->openApi();
        $schemas = $document['components']['schemas'];
        self::assertSame(['error', 'request_id'], $schemas['SqueHubApiError']['required']);
        self::assertSame('array', $schemas['SqueHubValidationError']['properties']
            ['error']['properties']['details']['additionalProperties']['type']);
        self::assertEqualsCanonicalizing(['page', 'per_page', 'total', 'pages', 'from', 'to',
            'has_next', 'has_previous'], array_keys($schemas['SqueHubPaginationMeta']['properties']));
        $operation = $document['paths']['/api/users']['get'];
        self::assertSame('#/components/schemas/SqueHubPaginationMeta', $operation['responses']
            ['200']['content']['application/json']['schema']['properties']['meta']['$ref']);
        self::assertArrayHasKey('WWW-Authenticate', $operation['responses']['401']['headers']);
        self::assertArrayHasKey('Retry-After', $operation['responses']['429']['headers']);
        self::assertSame('SqueHubPat', array_key_first($operation['security'][0]));
    }

    public function testSessionAndPatRemainDistinctSecuritySchemes(): void
    {
        $app = $this->application();
        $app->config()->set('session.name', 'contract_session');
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/api/me', static fn (): string => 'ready')
            ->named('me.show')
            ->contract((new OperationContract())->session()
                ->response(200, Schema::string()));
        $routes->get('/api/token', static fn (): string => 'ready')
            ->named('token.show')
            ->contract((new OperationContract())->pat(['users.read'])
                ->authorizationAbilities(['users.view'])
                ->response(200, Schema::string()));

        $document = $app->container()->make(ContractManager::class)->openApi();
        self::assertSame('contract_session', $document['components']['securitySchemes']
            ['SqueHubSession']['name']);
        self::assertSame('apiKey', $document['components']['securitySchemes']
            ['SqueHubSession']['type']);
        self::assertSame('bearer', $document['components']['securitySchemes']
            ['SqueHubPat']['scheme']);
        self::assertArrayNotHasKey('oauth2', $document['components']['securitySchemes']);
        self::assertSame([['SqueHubSession' => []]], $document['paths']['/api/me']
            ['get']['security']);
        self::assertSame(['users.read'], $document['paths']['/api/token']['get']
            ['x-squehub-token-abilities']);
        self::assertSame(['users.view'], $document['paths']['/api/token']['get']
            ['x-squehub-authorization-abilities']);
    }

    public function testUriAndHeaderVersioningUseExistingRouteMetadata(): void
    {
        $uriApp = $this->application();
        $uriRoutes = $uriApp->container()->make(RouteRegistry::class);
        $uriRoutes->get('/api/v2/users', static fn (): string => 'ready')
            ->named('users.v2')
            ->apiVersion('v2')
            ->contract((new OperationContract())->response(200, Schema::string()));
        $uriRoutes->get('/api/v3/users', static fn (): string => 'ready')
            ->named('users.v3')
            ->apiVersion('v3')
            ->contract((new OperationContract())->response(200, Schema::string()));
        $uriManager = $uriApp->container()->make(ContractManager::class);
        self::assertCount(1, $uriManager->application('v2')['operations']);
        self::assertArrayHasKey('/api/v2/users', $uriManager->openApi('v2')['paths']);
        self::assertArrayNotHasKey('/api/v3/users', $uriManager->openApi('v2')['paths']);
        self::assertSame('v3', $uriManager->openApi('v3')['paths']['/api/v3/users']
            ['get']['x-squehub-api-version']);

        $headerApp = $this->application();
        $headerApp->config()->set('api.versioning.strategy', 'header');
        $headerApp->config()->set('api.versioning.header', 'X-Product-Version');
        $headerApp->container()->make(RouteRegistry::class)
            ->get('/api/users', static fn (): string => 'ready')
            ->named('users.header')
            ->apiVersion('v2')
            ->contract((new OperationContract())->response(200, Schema::string()));
        $operation = $headerApp->container()->make(ContractManager::class)
            ->openApi()['paths']['/api/users']['get'];
        self::assertSame('v2', $operation['x-squehub-api-version']);
        self::assertSame('X-Product-Version', $operation['parameters'][0]['name']);
        self::assertSame('v2', $operation['parameters'][0]['schema']['const']);
        self::assertTrue($operation['parameters'][0]['required']);
    }

    public function testOutgoingWebhookIsOptInAndIncomingWebhookRouteRemainsInPaths(): void
    {
        $app = $this->application();
        $app->config()->set('webhooks.endpoints.billing.url', 'https://example.test/hook');
        $app->config()->set('webhooks.endpoints.billing.secret', self::SECRET);
        $manager = $app->container()->make(ContractManager::class);
        $manager->webhook('order.paid', Schema::object([
            'order_id' => Schema::string(),
        ])->required(['order_id']), 'An order was paid.');
        $app->container()->make(RouteRegistry::class)
            ->post('/api/incoming', static fn (): string => 'ready')
            ->named('webhooks.incoming')
            ->contract((new OperationContract())->response(204, null, 'Accepted'));

        $native = $manager->application();
        $oas = $manager->openApi();
        self::assertSame('order.paid', $native['webhooks'][0]['type']);
        self::assertArrayHasKey('order.paid', $oas['webhooks']);
        self::assertArrayHasKey('/api/incoming', $oas['paths']);
        self::assertSame(['SqueHub-Webhook-Id', 'SqueHub-Webhook-Delivery-Id',
            'SqueHub-Webhook-Timestamp', 'SqueHub-Webhook-Signature'],
            array_column($oas['webhooks']['order.paid']['post']['parameters'], 'name'));
        $serialized = json_encode([$native, $oas], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET, $serialized);
        self::assertStringNotContainsString('https://example.test/hook', $serialized);
    }

    public function testTagDescriptionsAndUsernameOnlyServerUrlPolicy(): void
    {
        $app = $this->application();
        $manager = $app->container()->make(ContractManager::class);
        $manager->tag('users', 'User operations.');
        $app->container()->make(RouteRegistry::class)
            ->get('/api/users', static fn (): string => 'ready')
            ->named('users.index')
            ->contract((new OperationContract())->tags('users')
                ->response(200, Schema::string()));
        $native = $manager->application();
        $oas = $manager->openApi();
        self::assertSame('User operations.', $native['tags']['users']);
        self::assertSame('User operations.', $oas['tags'][0]['description']);

        $this->expectException(ContractException::class);
        $manager->server('https://username@example.test');
    }

    public function testReorderedRegistrationYieldsByteIdenticalArtifacts(): void
    {
        $first = $this->application();
        $second = $this->application();
        $this->populateDeterministic($first, false);
        $this->populateDeterministic($second, true);
        $left = $first->container()->make(ContractManager::class);
        $right = $second->container()->make(ContractManager::class);

        self::assertSame($left->json('squehub'), $right->json('squehub'));
        self::assertSame($left->json(), $right->json());
    }

    public function testSeparateApplicationsAndPluginsUseTheirOwnCurrentServices(): void
    {
        $first = $this->application();
        $firstRegistry = $first->container()->make(RouteRegistry::class);
        $firstRegistry->get('/first', static fn (): string => 'ready')
            ->named('first.show')
            ->contract((new OperationContract())->response(200, Schema::string()));
        $firstManager = $first->container()->make(ContractManager::class);
        self::assertArrayHasKey('/first', $firstManager->openApi()['paths']);

        $second = $this->application();
        Contract::info('Second API', '2.0.0');
        Contract::schema('Second', ContractSchema::string());
        PluginRoute::path('/second')->get(static fn (): string => 'ready')
            ->named('second.show')
            ->contract(Contract::operation()->response(200, ContractSchema::ref('Second')));
        $secondManager = $second->container()->make(ContractManager::class);
        self::assertSame($secondManager, Contract::manager());
        self::assertSame('Second API', Contract::openApi()['info']['title']);
        self::assertArrayHasKey('/second', $secondManager->openApi()['paths']);
        self::assertArrayNotHasKey('/first', $secondManager->openApi()['paths']);
        self::assertArrayHasKey('/first', $firstManager->openApi()['paths']);
        self::assertArrayNotHasKey('/second', $firstManager->openApi()['paths']);
    }

    public function testGenerationAvoidsControllerExecutionAndPrivateConfiguration(): void
    {
        $app = $this->application();
        $app->config()->set('auth.tokens.secret', self::SECRET);
        $app->config()->set('oauth.clients.provider.client_secret', self::SECRET);
        $app->config()->set('database.connections.mysql.password', self::SECRET);
        $app->config()->set('app.key', self::SECRET);
        $app->config()->set('mail.smtp.password', self::SECRET);
        $app->container()->make(RouteRegistry::class)
            ->get('/api/no-effects', static function (): never {
                throw new RuntimeException('Controller executed during contract generation.');
            })
            ->named('no.effects')
            ->contract((new OperationContract())->response(200, Schema::string()));

        $manager = $app->container()->make(ContractManager::class);
        $native = $manager->json('squehub');
        $oas = $manager->json();
        self::assertStringNotContainsString(self::SECRET, $native . $oas);
        self::assertStringNotContainsString('Controller executed', $native . $oas);
        self::assertStringNotContainsString($app->basePath(), $native . $oas);
        self::assertStringContainsString('no.effects', $native . $oas);
    }

    private function application(): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $app = new Application($project->path());
        $app->register(RoutingServiceProvider::class);
        $app->register(ContractServiceProvider::class);
        $app->bootstrap();
        return $app;
    }

    private function populateDeterministic(Application $app, bool $reverse): void
    {
        $manager = $app->container()->make(ContractManager::class);
        $schemas = ['User' => Schema::string(), 'Order' => Schema::integer()];
        $paths = ['/api/users' => 'users.index', '/api/orders' => 'orders.index'];
        if ($reverse) {
            $schemas = array_reverse($schemas, true);
            $paths = array_reverse($paths, true);
        }
        foreach ($schemas as $name => $schema) $manager->schema($name, $schema);
        foreach ($paths as $path => $name) {
            $app->container()->make(RouteRegistry::class)
                ->get($path, static fn (): string => 'ready')
                ->named($name)
                ->contract((new OperationContract())->response(200, Schema::string()));
        }
        $manager->webhook('order.paid', Schema::object(['id' => Schema::integer()]));
        $manager->webhook('user.created', Schema::object(['id' => Schema::integer()]));
    }
}

/** A schema-only resource fixture; exporting must never call toArray(). */
final class ContractUserResource extends ApiResource
{
    public static int $transformations = 0;

    public static function contractSchema(): ?Schema
    {
        return Schema::object(['id' => Schema::integer()])->required(['id']);
    }

    public function toArray(): array
    {
        ++self::$transformations;
        throw new RuntimeException('Resource transformation ran during contract export.');
    }
}
