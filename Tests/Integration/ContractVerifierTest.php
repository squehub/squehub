<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\ContractVerification;

use App\Api\Contract\Contract;
use App\Api\Contract\ContractManager;
use App\Api\Contract\ContractServiceProvider;
use App\Api\Contract\ContractVerifier;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
use App\Auth\Middleware\RequireToken;
use App\Auth\Middleware\RequireTokenAbility;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Response;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Narrow verifier behavior tests; broader API security paths have separate fixtures. */
final class ContractVerifierTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        Contract::setResolver(null);
        Route::setResolver(null);
        foreach ($this->projects as $project) $project->remove();
    }

    public function testRealKernelResponsePassesAndRepeatedReportsAreByteStable(): void
    {
        $app = $this->application();
        $app->container()->make(RouteRegistry::class)
            ->get('/api/users/{id}', static fn (): JsonResponse => new JsonResponse([
                'data' => ['id' => 7, 'name' => 'Ada'],
            ]))
            ->named('users.show')
            ->contract((new OperationContract())->path('id', Schema::integer())
                ->response(200, Schema::object(['data' => Schema::object([
                    'id' => Schema::integer(), 'name' => Schema::string(),
                ])->required(['id', 'name'])->additionalProperties(false)])
                    ->required(['data'])->additionalProperties(false)));
        $manager = $app->container()->make(ContractManager::class);
        $manager->verify('users.show.success')->operation('users.show')->route(['id' => 7])
            ->expectStatus(200);
        $verifier = $app->container()->make(ContractVerifier::class);
        $first = $verifier->verify();
        $second = $verifier->verify();
        self::assertTrue($first->passed(), $first->toText());
        self::assertSame($first->toJson(), $second->toJson());
        self::assertSame(1, $first->toArray()['summary']['cases_executed']);
        self::assertSame(1, $first->toArray()['summary']['statuses_exercised']);
    }

    public function testStatusTypeAndClosedObjectDriftProduceSafeFindings(): void
    {
        $app = $this->application();
        $secret = 'TEST_CONTRACT_SECRET_DO_NOT_LEAK';
        $app->container()->make(RouteRegistry::class)
            ->get('/api/drift', static fn (): JsonResponse => new JsonResponse([
                'id' => 'wrong', 'private' => $secret,
            ], 201))
            ->named('drift.show')
            ->contract((new OperationContract())->response(200,
                Schema::object(['id' => Schema::integer()])->required(['id'])
                    ->additionalProperties(false)));
        $app->container()->make(ContractManager::class)
            ->verify('drift.case')->operation('drift.show')->expectStatus(200);
        $report = $app->container()->make(ContractVerifier::class)->verify();
        self::assertFalse($report->passed());
        self::assertContains('status_mismatch', array_column($report->toArray()['findings'], 'code'));
        self::assertContains('status_undeclared', array_column($report->toArray()['findings'], 'code'));
        self::assertStringNotContainsString($secret, $report->toJson() . $report->toText());
    }

    public function testDeclaredResponseDetectsWrongPrimitiveAndContentType(): void
    {
        $app = $this->application();
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/api/type', static fn (): JsonResponse => new JsonResponse(['id' => '7']))
            ->named('type.show')
            ->contract((new OperationContract())->response(200,
                Schema::object(['id' => Schema::integer()])->required(['id'])));
        $routes->get('/api/content', static fn (): Response => new Response('plain', 200,
            ['Content-Type' => 'text/plain']))
            ->named('content.show')
            ->contract((new OperationContract())->response(200, Schema::object()));
        $manager = $app->container()->make(ContractManager::class);
        $manager->verify('type.case')->operation('type.show');
        $manager->verify('content.case')->operation('content.show');
        $report = $app->container()->make(ContractVerifier::class)->verify();
        $codes = array_column($report->toArray()['findings'], 'code');
        self::assertContains('response_schema_mismatch', $codes);
        self::assertContains('content_type_mismatch', $codes);
    }

    public function testMissingHeaderAndMalformedJsonAreDistinct(): void
    {
        $app = $this->application();
        $app->container()->make(RouteRegistry::class)
            ->get('/api/malformed', static fn (): Response => new Response('{broken', 200,
                ['Content-Type' => 'application/json']))
            ->named('malformed.show')
            ->contract((new OperationContract())->response(200, Schema::object(),
                headers: ['X-Required' => Schema::string()]));
        $app->container()->make(ContractManager::class)
            ->verify('malformed.case')->operation('malformed.show');
        $report = $app->container()->make(ContractVerifier::class)->verify();
        $codes = array_column($report->toArray()['findings'], 'code');
        self::assertContains('header_missing', $codes);
        self::assertContains('json_invalid', $codes);
    }

    public function testMissingRouteAndPatAbilityDriftFailStaticVerification(): void
    {
        $app = $this->application();
        $app->container()->make(RouteRegistry::class)
            ->get('/api/private', static fn (): string => 'never')
            ->named('private.show')
            ->through([RequireToken::guard('api'), RequireTokenAbility::named('users.write')])
            ->contract((new OperationContract())->pat(['users.read'])
                ->response(200, Schema::string()));
        $manager = $app->container()->make(ContractManager::class);
        $manager->verify('missing.case')->operation('missing.show');
        $report = $app->container()->make(ContractVerifier::class)->verify(staticOnly: true);
        $codes = array_column($report->toArray()['findings'], 'code');
        self::assertContains('security_mismatch', $codes);
        self::assertContains('route_missing', $codes);
        self::assertSame(0, $report->toArray()['summary']['cases_executed']);
    }

    public function testProductionRefusesExecutionAndStaticModeNeverCallsController(): void
    {
        $app = $this->application('production');
        $runs = 0;
        $app->container()->make(RouteRegistry::class)
            ->get('/api/ping', static function () use (&$runs): JsonResponse {
                ++$runs;
                return new JsonResponse(['ok' => true]);
            })
            ->named('ping.show')
            ->contract((new OperationContract())->response(200, Schema::object([
                'ok' => Schema::boolean(),
            ])->required(['ok'])));
        $app->container()->make(ContractManager::class)
            ->verify('ping.case')->operation('ping.show');
        $verifier = $app->container()->make(ContractVerifier::class);
        self::assertTrue($verifier->verify(staticOnly: true)->passed());
        $blocked = $verifier->verify();
        self::assertFalse($blocked->passed());
        self::assertContains('execution_prohibited', array_column($blocked->toArray()['findings'], 'code'));
        self::assertSame(0, $runs);
    }

    public function testMutationRequiresExplicitOptIn(): void
    {
        $app = $this->application();
        $runs = 0;
        $app->container()->make(RouteRegistry::class)
            ->post('/api/create', static function () use (&$runs): JsonResponse {
                ++$runs;
                return new JsonResponse(['created' => true], 201);
            })
            ->named('create')
            ->contract((new OperationContract())->response(201, Schema::object([
                'created' => Schema::boolean(),
            ])->required(['created'])));
        $app->container()->make(ContractManager::class)
            ->verify('create.case')->operation('create')->mutation()->expectStatus(201);
        $verifier = $app->container()->make(ContractVerifier::class);
        self::assertSame(1, $verifier->verify()->toArray()['summary']['cases_skipped']);
        self::assertSame(0, $runs);
        self::assertTrue($verifier->verify(includeMutations: true)->passed());
        self::assertSame(1, $runs);
    }

    private function application(string $environment = 'testing'): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $project->write('Config/App.php', '<?php return ' . var_export([
            'env' => $environment, 'debug' => false,
        ], true) . ';');
        $project->write('Config/Api.php', '<?php return ' . var_export([
            'enabled' => true, 'paths' => ['/api'],
        ], true) . ';');
        $app = new Application($project->path());
        $app->register(HttpServiceProvider::class);
        $app->register(RoutingServiceProvider::class);
        $app->register(ContractServiceProvider::class);
        $app->bootstrap();
        return $app;
    }
}
