<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\ContractVerificationSecurity;

use App\Api\Contract\Contract;
use App\Api\Contract\ContractManager;
use App\Api\Contract\ContractServiceProvider;
use App\Api\Contract\ContractVerifier;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
use App\Auth\Auth as AuthGateway;
use App\Auth\AuthManager;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Middleware\RequireToken;
use App\Auth\Middleware\RequireTokenAbility;
use App\Authorization\Authorization as AuthorizationGateway;
use App\Authorization\AuthorizationManager;
use App\Authorization\AuthorizationServiceProvider;
use App\Authorization\Middleware\RequireAbility;
use App\Database\Database as DatabaseGateway;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Plugins\Auth;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Session\Session as SessionGateway;
use App\Session\SessionServiceProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/**
 * Verifies contract examples through the real Auth, Authorization, and HTTP
 * middleware pipeline. Token credentials are fixtures, never report fields.
 */
final class ContractVerificationSecurityTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private AuthManager $auth;
    private ContractManager $contracts;
    private RouteRegistry $routes;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $this->project = new TemporaryProject();
        foreach ([
            'App' => ['env' => 'testing', 'debug' => false],
            'Api' => ['enabled' => true, 'paths' => ['/api']],
            'Database' => ['default' => 'main', 'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'Session' => ['driver' => 'array'],
            'Auth' => [
                'default' => 'web',
                'guards' => [
                    'web' => ['driver' => 'session', 'identity' => 'users'],
                    'api' => ['driver' => 'token', 'identity' => 'users', 'repository' => 'array'],
                ],
                'identities' => ['users' => ['driver' => 'model',
                    'model' => ContractVerificationUser::class, 'identifier' => 'id',
                    'password' => 'password', 'credentials' => ['email']]],
                'tokens' => ['driver' => 'array', 'default_ttl' => 3600],
            ],
            'Authorization' => ['abilities' => [], 'policies' => []],
        ] as $name => $config) {
            $this->project->write('Config/' . $name . '.php',
                '<?php return ' . var_export($config, true) . ';');
        }

        $this->app = new Application($this->project->path());
        foreach ([DatabaseServiceProvider::class, SessionServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class,
            AuthServiceProvider::class, AuthorizationServiceProvider::class,
            ContractServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $container = $this->app->container();
        $this->auth = $container->make(AuthManager::class);
        $this->contracts = $container->make(ContractManager::class);
        $this->routes = $container->make(RouteRegistry::class);
        $container->make(DatabaseManager::class)->schema()->create('contract_verification_users',
            static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('password');
            });
    }

    protected function tearDown(): void
    {
        Contract::setResolver(null);
        AuthGateway::setResolver(null);
        AuthorizationGateway::setResolver(null);
        DatabaseGateway::setResolver(null);
        SessionGateway::setResolver(null);
        Route::setResolver(null);
        if (isset($this->project)) $this->project->remove();
    }

    public function testPatCasesReachTheKernelAndDistinguishSuccessFrom401And403(): void
    {
        $user = $this->user();
        $readToken = $this->auth->tokens('api')->issue($user, 'read', ['orders.read'])->token();
        $wrongAbilityToken = $this->auth->tokens('api')->issue($user, 'write', ['orders.write'])->token();
        $controllerRuns = 0;
        $this->routes->get('/api/orders/me', static function () use (&$controllerRuns): JsonResponse {
            ++$controllerRuns;
            return new JsonResponse(['id' => (int) Auth::id()]);
        })->named('orders.me')->through([
            RequireToken::guard('api'), RequireTokenAbility::named('orders.read'),
        ])->contract((new OperationContract())->pat(['orders.read'])
            ->response(200, Schema::object(['id' => Schema::integer()])->required(['id']))
            ->error(401)->error(403));

        $this->contracts->verify('orders.01.missing')->operation('orders.me')->expectStatus(401);
        $this->contracts->verify('orders.02.invalid')->operation('orders.me')
            ->headers(['Authorization' => 'Bearer invalid-contract-token'])
            ->expectStatus(401);
        $this->contracts->verify('orders.03.forbidden')->operation('orders.me')
            ->headers(['Authorization' => 'Bearer ' . $wrongAbilityToken])
            ->expectStatus(403);
        $this->contracts->verify('orders.04.allowed')->operation('orders.me')
            ->headers(['Authorization' => 'Bearer ' . $readToken])
            ->expectStatus(200);

        $report = $this->app->container()->make(ContractVerifier::class)->verify();
        self::assertTrue($report->passed(), $report->toText());
        self::assertSame(4, $report->toArray()['summary']['cases_executed']);
        self::assertSame(3, $report->toArray()['summary']['statuses_exercised']);
        self::assertSame(1, $controllerRuns);
        self::assertStringNotContainsString($readToken, $report->toJson() . $report->toText());
        self::assertStringNotContainsString($wrongAbilityToken, $report->toJson());
    }

    public function testAuthorizationPolicyDenialUsesTheDeclared403WithAValidPat(): void
    {
        $user = $this->user();
        $token = $this->auth->tokens('api')->issue($user, 'read', ['orders.read'])->token();
        $this->app->container()->make(AuthorizationManager::class)->define('orders.view',
            static fn (ContractVerificationUser $identity): bool => false);
        $controllerRuns = 0;
        $this->routes->get('/api/orders/restricted', static function () use (&$controllerRuns): JsonResponse {
            ++$controllerRuns;
            return new JsonResponse(['ok' => true]);
        })->named('orders.restricted')->through([
            RequireToken::guard('api'), RequireTokenAbility::named('orders.read'),
            RequireAbility::named('orders.view'),
        ])->contract((new OperationContract())->pat(['orders.read'])
            ->authorizationAbilities(['orders.view'])
            ->response(200, Schema::object(['ok' => Schema::boolean()])->required(['ok']))
            ->error(403));

        $this->contracts->verify('orders.restricted.denied')->operation('orders.restricted')
            ->headers(['Authorization' => 'Bearer ' . $token])->expectStatus(403);

        $report = $this->app->container()->make(ContractVerifier::class)->verify();
        self::assertTrue($report->passed(), $report->toText());
        self::assertSame(0, $controllerRuns);
        self::assertSame(1, $report->toArray()['summary']['cases_passed']);
        self::assertSame(1, $report->toArray()['summary']['statuses_exercised']);
    }

    public function testSessionCasesUseTheDeclaredSessionSchemeAndDoNotSubstitutePat(): void
    {
        $user = $this->user();
        $token = $this->auth->tokens('api')->issue($user, 'bearer-only')->token();
        $controllerRuns = 0;
        $this->routes->get('/api/session/me', static function () use (&$controllerRuns): JsonResponse {
            ++$controllerRuns;
            return new JsonResponse(['id' => (int) Auth::id()]);
        })->named('session.me')->through('auth')
            ->contract((new OperationContract())->session()
                ->response(200, Schema::object(['id' => Schema::integer()])->required(['id']))
                ->error(401));

        $this->contracts->verify('session.01.missing')->operation('session.me')
            ->expectStatus(401);
        $this->contracts->verify('session.02.bearer-only')->operation('session.me')
            ->headers(['Authorization' => 'Bearer ' . $token])->expectStatus(401);
        $this->contracts->verify('session.03.logged-in')->operation('session.me')
            ->setup(function (Application $app) use ($user): void {
                $app->container()->make(AuthManager::class)->login($user);
            })->cleanup(static function (Application $app): void {
                $app->container()->make(AuthManager::class)->logout();
            })->expectStatus(200);

        $report = $this->app->container()->make(ContractVerifier::class)->verify();
        self::assertTrue($report->passed(), $report->toText());
        self::assertSame(3, $report->toArray()['summary']['cases_passed']);
        self::assertSame(2, $report->toArray()['summary']['statuses_exercised']);
        self::assertSame(1, $controllerRuns);
        self::assertStringNotContainsString($token, $report->toJson() . $report->toText());
    }

    private function user(): ContractVerificationUser
    {
        return ContractVerificationUser::create([
            'email' => 'verification@example.test', 'password' => 'fixture-hash',
        ]);
    }
}

/** The verification gate resolves persisted identity through the ordinary Model provider. */
final class ContractVerificationUser extends Model implements Authenticatable
{
    protected string $table = 'contract_verification_users';
    protected array $fillable = ['email', 'password'];

    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}
