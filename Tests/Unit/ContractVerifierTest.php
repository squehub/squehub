<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Api\Contract\Contract;
use App\Api\Contract\ContractException;
use App\Api\Contract\ContractManager;
use App\Api\Contract\ContractServiceProvider;
use App\Api\Contract\ContractVerifier;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
use App\Auth\Middleware\RequireAuthentication;
use App\Auth\Middleware\RequireToken;
use App\Auth\Middleware\RequireTokenAbility;
use App\Authorization\Middleware\RequireAbility;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Response;
use App\Routing\MiddlewareRegistry;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Focused checks for verification policy, report privacy, and case lifecycle. */
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

    public function testUnsafeMethodNeedsExplicitMutationOptInEvenWithoutMarker(): void
    {
        $app = $this->application();
        $calls = 0;
        $app->container()->make(RouteRegistry::class)
            ->post('/api/orders', static function () use (&$calls): JsonResponse {
                ++$calls;
                return new JsonResponse(['ok' => true], 201);
            })
            ->named('orders.create')
            ->contract((new OperationContract())->response(201, Schema::object([
                'ok' => Schema::boolean(),
            ])->required(['ok'])));
        $app->container()->make(ContractManager::class)
            ->verify('orders.create.success')->operation('orders.create')->expectStatus(201);

        $verifier = $app->container()->make(ContractVerifier::class);
        $skipped = $verifier->verify();
        self::assertTrue($skipped->passed());
        self::assertSame(0, $calls);
        self::assertSame(1, $skipped->toArray()['summary']['cases_skipped']);
        self::assertSame('mutation_skipped', $skipped->toArray()['findings'][0]['code']);

        $executed = $verifier->verify(includeMutations: true);
        self::assertTrue($executed->passed(), $executed->toText());
        self::assertSame(1, $calls);
    }

    public function testExplicitMutationMarkerProtectsSideEffectingGet(): void
    {
        $app = $this->application();
        $calls = 0;
        $app->container()->make(RouteRegistry::class)
            ->get('/api/refresh', static function () use (&$calls): JsonResponse {
                ++$calls;
                return new JsonResponse(['ok' => true]);
            })
            ->named('refresh')
            ->contract((new OperationContract())->response(200, Schema::object([
                'ok' => Schema::boolean(),
            ])));
        $app->container()->make(ContractManager::class)
            ->verify('refresh.case')->operation('refresh')->mutation();

        self::assertSame(1, $app->container()->make(ContractVerifier::class)
            ->verify()->toArray()['summary']['cases_skipped']);
        self::assertSame(0, $calls);
    }

    public function testResponseContentTypeNeverLeaksRawHeaderIntoReports(): void
    {
        $app = $this->application();
        $secret = 'SQUEHUB_VERIFICATION_PRIVATE_HEADER_VALUE';
        $app->container()->make(RouteRegistry::class)
            ->get('/api/header', static fn (): Response => new Response('{}', 200, [
                'Content-Type' => 'application/' . $secret,
            ]))
            ->named('header.case')
            ->contract((new OperationContract())->response(200, Schema::object()));
        $app->container()->make(ContractManager::class)
            ->verify('header.private')->operation('header.case');

        $report = $app->container()->make(ContractVerifier::class)->verify();
        self::assertFalse($report->passed());
        self::assertSame('content_type_mismatch', $report->toArray()['findings'][0]['code']);
        self::assertSame('different', $report->toArray()['findings'][0]['actual']);
        self::assertStringNotContainsString($secret, $report->toJson() . $report->toText());
    }

    public function testKnownSessionAliasAndPatMiddlewareAreComparedStatically(): void
    {
        $app = $this->application();
        $app->container()->make(MiddlewareRegistry::class)
            ->alias('auth', RequireAuthentication::class);
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/api/session', static fn (): string => 'unused')
            ->named('session.route')->through('auth')
            ->contract((new OperationContract())->session()->response(200, Schema::string()));
        $routes->get('/api/pat', static fn (): string => 'unused')
            ->named('pat.route')
            ->through([RequireToken::guard('api'), RequireTokenAbility::named('users.read')])
            ->contract((new OperationContract())->pat(['users.write'])->response(200, Schema::string()));
        $report = $app->container()->make(ContractVerifier::class)->verify(staticOnly: true);
        self::assertSame(['security_mismatch'], array_column($report->toArray()['findings'], 'code'));
        self::assertSame('pat.route', $report->toArray()['findings'][0]['operation_id']);
    }

    public function testOpaqueStringMiddlewareWarnsRatherThanProvingPatSecurity(): void
    {
        $app = $this->application();
        // A class-string lacks the configured guard and cannot prove PAT auth.
        $app->container()->make(MiddlewareRegistry::class)
            ->alias('token-class', RequireToken::class);
        $app->container()->make(RouteRegistry::class)
            ->get('/api/opaque', static fn (): string => 'unused')
            ->named('opaque.route')->through('token-class')
            ->contract((new OperationContract())->pat()->response(200, Schema::string()));
        $report = $app->container()->make(ContractVerifier::class)->verify(staticOnly: true);
        self::assertSame('security_unverifiable', $report->toArray()['findings'][0]['code']);
        self::assertTrue($report->passed());
        self::assertFalse($app->container()->make(ContractVerifier::class)
            ->verify(staticOnly: true, strict: true)->passed());
    }

    public function testKnownAuthorizationMiddlewareMismatchIsDetectedWithoutAssumingControllerPolicy(): void
    {
        $app = $this->application();
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/api/reports', static fn (): string => 'unused')
            ->named('reports.list')->through(RequireAbility::named('reports.read'))
            ->contract((new OperationContract())->authorizationAbilities(['reports.write'])
                ->response(200, Schema::string()));
        $routes->get('/api/controller-policy', static fn (): string => 'unused')
            ->named('controller.policy')
            ->contract((new OperationContract())->authorizationAbilities(['reports.read'])
                ->response(200, Schema::string()));

        $report = $app->container()->make(ContractVerifier::class)->verify(staticOnly: true);
        self::assertSame(['security_unverifiable', 'security_mismatch'],
            array_column($report->toArray()['findings'], 'code'));
        self::assertSame('controller.policy', $report->toArray()['findings'][0]['operation_id']);
        self::assertSame('reports.list', $report->toArray()['findings'][1]['operation_id']);
    }

    public function testCleanupRunsAfterResponseDriftAndRequestSchemaFailure(): void
    {
        $app = $this->application();
        $events = [];
        $app->container()->make(RouteRegistry::class)
            ->post('/api/create', static fn (): JsonResponse => new JsonResponse(['ok' => false]))
            ->named('create')
            ->contract((new OperationContract())
                ->body(Schema::object(['name' => Schema::string()])->required(['name']))
                ->response(200, Schema::object(['ok' => Schema::boolean()])));
        $manager = $app->container()->make(ContractManager::class);
        $manager->verify('create.invalid')->operation('create')->json(['name' => 7])
            ->setup(static function () use (&$events): void { $events[] = 'setup-invalid'; })
            ->cleanup(static function () use (&$events): void { $events[] = 'cleanup-invalid'; });
        $manager->verify('create.drift')->operation('create')->json(['name' => 'Ada'])
            ->expectStatus(201)
            ->setup(static function () use (&$events): void { $events[] = 'setup-drift'; })
            ->cleanup(static function () use (&$events): void { $events[] = 'cleanup-drift'; });

        $report = $app->container()->make(ContractVerifier::class)->verify(includeMutations: true);
        self::assertSame(2, $report->toArray()['summary']['cases_failed']);
        self::assertContains('request_schema_mismatch', array_column($report->toArray()['findings'], 'code'));
        self::assertContains('status_mismatch', array_column($report->toArray()['findings'], 'code'));
        self::assertSame(['setup-drift', 'cleanup-drift', 'setup-invalid', 'cleanup-invalid'], $events);
    }

    public function testFixtureFailureIsDistinctAndCleanupStillRuns(): void
    {
        $app = $this->application();
        $cleanup = 0;
        $app->container()->make(RouteRegistry::class)
            ->get('/api/fixture', static fn (): JsonResponse => new JsonResponse(['ok' => true]))
            ->named('fixture.route')
            ->contract((new OperationContract())->response(200, Schema::object()));
        $app->container()->make(ContractManager::class)
            ->verify('fixture.case')->operation('fixture.route')
            ->setup(static function (): void { throw new RuntimeException('PRIVATE_FIXTURE_SECRET'); })
            ->cleanup(static function () use (&$cleanup): void { ++$cleanup; });

        $report = $app->container()->make(ContractVerifier::class)->verify();
        self::assertSame('fixture_failed', $report->toArray()['findings'][0]['code']);
        self::assertSame(1, $cleanup);
        self::assertStringNotContainsString('PRIVATE_FIXTURE_SECRET', $report->toJson());
    }

    public function testDuplicateCaseNameAndPerApplicationRegistrationAreIsolated(): void
    {
        $first = $this->application();
        $manager = $first->container()->make(ContractManager::class);
        $manager->verify('only.first');
        try {
            $manager->verify('only.first');
            self::fail('Duplicate case names must fail.');
        } catch (ContractException) {
            self::assertCount(1, $manager->verificationCases());
        }
        $second = $this->application();
        self::assertSame([], $second->container()->make(ContractManager::class)->verificationCases());
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
