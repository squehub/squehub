<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Api\ApiResource;
use App\Api\Contract\Contract as CanonicalContract;
use App\Api\Contract\ContractManager;
use App\Api\Contract\ContractServiceProvider;
use App\Api\Contract\ContractVerifier;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
use App\Database\Pagination\Page;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\RateLimit\Middleware\RateLimitRequests;
use App\RateLimit\RateLimit;
use App\RateLimit\RateLimiter;
use App\RateLimit\RateLimitRule;
use App\RateLimit\RateLimitServiceProvider;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Validation\ValidationServiceProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Runs explicit contract examples through the same Kernel used by web requests. */
final class ApiVerificationGateTest extends TestCase
{
    private const SECRET = 'SQUEHUB_VERIFICATION_SECRET_DO_NOT_LEAK';

    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        CanonicalContract::setResolver(null);
        RateLimit::setResolver(null);
        Route::setResolver(null);
        foreach ($this->projects as $project) $project->remove();
    }

    public function testSingleCollectionAndPaginatedResourcesMatchNativeSchemas(): void
    {
        $app = $this->application();
        $manager = $app->container()->make(ContractManager::class);
        $manager->resource('VerificationUser', VerificationUserResource::class);
        $routes = $app->container()->make(RouteRegistry::class);
        $users = [
            ['id' => 7, 'name' => 'Ada', 'password' => self::SECRET],
            ['id' => 8, 'name' => 'Grace', 'password' => self::SECRET],
        ];

        $routes->get('/api/users/{id}', static fn (): JsonResponse =>
            VerificationUserResource::make($users[0])->response())
            ->named('users.show')
            ->contract((new OperationContract())->path('id', Schema::integer())
                ->response(200, Schema::ref('VerificationUser')));
        $routes->get('/api/users', static fn (): JsonResponse =>
            VerificationUserResource::collection($users)->response())
            ->named('users.index')
            ->contract((new OperationContract())->response(200,
                Schema::array(Schema::ref('VerificationUser'))));
        $routes->get('/api/users/page', static fn (): JsonResponse =>
            VerificationUserResource::collection(new Page($users, 1, 2, 3))->response())
            ->named('users.page')
            ->contract((new OperationContract())->paginatedResponse(200,
                Schema::ref('VerificationUser')));

        $manager->verify('users.show.success')->operation('users.show')->route(['id' => 7]);
        $manager->verify('users.index.success')->operation('users.index');
        $manager->verify('users.page.success')->operation('users.page');

        $report = $app->container()->make(ContractVerifier::class)->verify();
        self::assertTrue($report->passed(), $report->toText());
        self::assertSame(3, $report->toArray()['summary']['cases_passed']);
        self::assertSame(3, $report->toArray()['summary']['statuses_exercised']);
        self::assertStringNotContainsString(self::SECRET, $report->toJson());
    }

    public function testManagedErrorBranchesAndRateLimitRunThroughKernel(): void
    {
        $app = $this->application();
        $routes = $app->container()->make(RouteRegistry::class);
        $manager = $app->container()->make(ContractManager::class);
        $routes->get('/api/status', static fn (): JsonResponse => new JsonResponse(['ok' => true]))
            ->named('status.show')
            ->contract((new OperationContract())
                ->response(200, Schema::object(['ok' => Schema::boolean()])->required(['ok']))
                ->error(404)->error(405));
        $routes->get('/api/validate', static function (Request $request): array {
            return $request->validate(['email' => 'required|email']);
        })->named('users.validate')
            ->contract((new OperationContract())->error(422));
        $routes->get('/api/failure', static function (): never {
            throw new RuntimeException(self::SECRET);
        })->named('failure.show')
            ->contract((new OperationContract())->error(500));
        $app->container()->make(RateLimiter::class)->define('verification.limit',
            static fn (): RateLimitRule => RateLimitRule::fixed('subject-1', 1, 60));
        $routes->get('/api/limited', static fn (): JsonResponse => new JsonResponse('ok'))
            ->named('limited.show')
            ->through(RateLimitRequests::named('verification.limit'))
            ->contract((new OperationContract())->response(200, Schema::string())->error(429));

        $manager->verify('status.success')->operation('status.show')->expectStatus(200);
        $manager->verify('status.not_found')->operation('status.show')
            ->uri('/api/not-found')->expectStatus(404);
        $manager->verify('status.method')->operation('status.show')
            ->method('DELETE')->expectStatus(405);
        $manager->verify('validation.failed')->operation('users.validate')->expectStatus(422);
        $manager->verify('failure.managed')->operation('failure.show')->expectStatus(500);
        $manager->verify('limited.01.allowed')->operation('limited.show')->expectStatus(200);
        $manager->verify('limited.02.denied')->operation('limited.show')->expectStatus(429);

        // DELETE is deliberately used to prove the real Router's 405. The
        // verifier still requires mutation opt-in for every unsafe method.
        $report = $app->container()->make(ContractVerifier::class)
            ->verify(includeMutations: true);
        self::assertTrue($report->passed(), $report->toText());
        self::assertSame(7, $report->toArray()['summary']['cases_passed']);
        self::assertStringNotContainsString(self::SECRET, $report->toJson());
    }

    public function testCorsAndHeaderVersionUseRealRequestPolicy(): void
    {
        $app = $this->application([
            'versioning' => ['strategy' => 'header', 'header' => 'X-API-Version'],
            'cors' => [
                'enabled' => true,
                'paths' => ['/api'],
                'allowed_origins' => ['https://client.example'],
                'allowed_methods' => ['GET', 'OPTIONS'],
                'allowed_headers' => ['X-API-Version'],
                'exposed_headers' => ['X-Request-ID'],
                'allow_credentials' => false,
                'max_age' => 60,
            ],
        ]);
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/api/versioned', static fn (Request $request): JsonResponse =>
            new JsonResponse(['version' => $request->apiVersion()]))
            ->named('versioned.show')
            ->apiVersion('2')
            ->contract((new OperationContract())
                ->response(200, Schema::object(['version' => Schema::string()])
                    ->required(['version']), headers: [
                        'Access-Control-Allow-Origin' => Schema::string(),
                    ])
                ->error(400));
        $manager = $app->container()->make(ContractManager::class);
        $manager->verify('versioned.allowed')->operation('versioned.show')
            ->headers(['X-API-Version' => '2', 'Origin' => 'https://client.example'])
            ->expectStatus(200);
        $manager->verify('versioned.missing')->operation('versioned.show')
            ->headers(['Origin' => 'https://client.example'])->expectStatus(400);
        $manager->verify('versioned.conflicting')->operation('versioned.show')
            ->headers(['X-API-Version' => '2,3', 'Origin' => 'https://client.example'])
            ->expectStatus(400);

        $report = $app->container()->make(ContractVerifier::class)->verify();
        self::assertTrue($report->passed(), $report->toText());
        self::assertSame(3, $report->toArray()['summary']['cases_passed']);

        // The contract case verifies allowed-origin headers. These direct
        // Kernel checks prove that denial and preflight do not grant more.
        $kernel = $app->container()->make(Kernel::class);
        $denied = $kernel->handle(new Request('GET', '/api/versioned', headers: [
            'Origin' => 'https://denied.example', 'X-API-Version' => '2',
        ]));
        self::assertSame(200, $denied->status());
        self::assertNull($denied->header('Access-Control-Allow-Origin'));
        $preflight = $kernel->handle(new Request('OPTIONS', '/api/versioned', headers: [
            'Origin' => 'https://client.example',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'X-API-Version',
        ]));
        self::assertSame(204, $preflight->status());
        self::assertSame('https://client.example', $preflight->header('Access-Control-Allow-Origin'));
        self::assertSame('X-API-Version', $preflight->header('Access-Control-Allow-Headers'));
    }

    /** @param array<string,mixed> $apiOverrides */
    private function application(array $apiOverrides = []): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $api = array_replace_recursive([
            'enabled' => true,
            'paths' => ['/api'],
            'versioning' => ['strategy' => 'uri', 'header' => 'X-API-Version'],
            'cors' => ['enabled' => false, 'paths' => ['/api'], 'allowed_origins' => [],
                'allowed_methods' => ['GET', 'HEAD', 'POST', 'OPTIONS'],
                'allowed_headers' => [], 'exposed_headers' => [],
                'allow_credentials' => false, 'max_age' => 60],
        ], $apiOverrides);
        foreach ([
            'App' => ['env' => 'testing', 'debug' => false],
            'Api' => $api,
            'RateLimit' => ['store' => 'array', 'prefix' => 'verification-gate', 'path' => null],
        ] as $name => $settings) {
            $project->write('Config/' . $name . '.php', '<?php return ' . var_export($settings, true) . ';');
        }
        $app = new Application($project->path());
        foreach ([ValidationServiceProvider::class, HttpServiceProvider::class,
            RoutingServiceProvider::class, ContractServiceProvider::class,
            RateLimitServiceProvider::class] as $provider) $app->register($provider);
        $app->bootstrap();
        return $app;
    }
}

/** A selected public representation; private source fields never reach JSON. */
final class VerificationUserResource extends ApiResource
{
    public static function contractSchema(): ?Schema
    {
        return Schema::object(['id' => Schema::integer(), 'name' => Schema::string()])
            ->required(['id', 'name'])->additionalProperties(false);
    }

    public function toArray(): array
    {
        return ['id' => $this->resource['id'], 'name' => $this->resource['name']];
    }
}
