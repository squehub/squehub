<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\AuthManager;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Middleware\RequireToken;
use App\Auth\Middleware\RequireTokenAbility;
use App\Authorization\AuthorizationException;
use App\Authorization\AuthorizationManager;
use App\Authorization\AuthorizationServiceProvider;
use App\Authorization\Rbac\RbacManager;
use App\Authorization\Rbac\RbacServiceProvider;
use App\Broadcasting\BroadcastManager;
use App\Broadcasting\BroadcastServiceProvider;
use App\Broadcasting\ChannelAuthorizer;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\CsrfServiceProvider;
use App\Security\Csrf\CsrfTokenManager;
use App\Session\SessionManager;
use App\Session\SessionServiceProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Private-channel checks stay inside the ordinary mounted HTTP security pipeline. */
final class BroadcastHttpIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private Kernel $kernel;
    private AuthManager $auth;
    private BroadcastManager $broadcast;
    private int $ruleCalls = 0;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for broadcast authorization integration.');
        }
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env"=>"production","debug"=>false];');
        $this->project->write('Config/Http.php', '<?php return ["base_path"=>"/app"];');
        $this->project->write('Config/Database.php', '<?php return ["default"=>"test","connections"=>'
            . '["test"=>["driver"=>"sqlite","database"=>":memory:"]]];');
        $this->project->write('Config/Session.php', '<?php return ["driver"=>"array"];');
        $this->project->write('Config/Csrf.php', '<?php return ["enabled"=>true,"field"=>"_csrf",'
            . '"header"=>"X-CSRF-Token","except"=>["/api/broadcast/authorize"]];');
        $this->project->write('Config/Auth.php', '<?php return ' . var_export(self::authSettings(), true) . ';');
        $this->project->write('Config/Rbac.php', '<?php return ["enabled"=>true,"driver"=>"array"];');
        $this->project->write('Config/Broadcasting.php', '<?php return ["enabled"=>true,"driver"=>"array"];');
        $this->app = new Application($this->project->path());
        foreach ([DatabaseServiceProvider::class, SessionServiceProvider::class,
            CsrfServiceProvider::class, HttpServiceProvider::class, RoutingServiceProvider::class,
            AuthServiceProvider::class, RbacServiceProvider::class,
            AuthorizationServiceProvider::class, BroadcastServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $container = $this->app->container();
        $this->kernel = $container->make(Kernel::class);
        $this->auth = $container->make(AuthManager::class);
        $this->broadcast = $container->make(BroadcastManager::class);
        BroadcastTeamAuthorizer::$constructed = 0;
        $container->make(DatabaseManager::class)->schema()->create('broadcast_users',
            static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('password');
            });
        $rbac = $container->make(RbacManager::class);
        $rbac->createRole('subscriber');
        $rbac->createPermission('orders.view');
        $rbac->grantPermission('subscriber', 'orders.view');
        $authorization = $container->make(AuthorizationManager::class);
        $this->broadcast->privateChannel('orders.{order}', function (
            Authenticatable $identity, array $parameters
        ) use ($authorization): bool {
            ++$this->ruleCalls;
            return $authorization->forIdentity($identity)->allows('orders.view')
                && (string) $identity->authIdentifier() === $parameters['order'];
        });
        $routes = $container->make(RouteRegistry::class);
        $action = static function (Request $request): JsonResponse {
            $channel = $request->input('channel');
            if (!is_string($channel) || !\App\Plugins\Broadcast::authorizePrivate($channel)) {
                throw new AuthorizationException();
            }
            return new JsonResponse(['authorized' => true]);
        };
        $routes->post('/broadcast/authorize', $action)->through('auth');
        $routes->post('/api/broadcast/authorize', $action)->through([
            RequireToken::guard('api'), RequireTokenAbility::named('broadcast.subscribe'),
        ]);
    }

    protected function tearDown(): void
    {
        \App\Broadcasting\Broadcast::setResolver(null);
        \App\Auth\Auth::setResolver(null);
        \App\Authorization\Authorization::setResolver(null);
        \App\Authorization\Rbac\Rbac::setResolver(null);
        \App\Routing\Route::setResolver(null);
        \App\Session\Session::setResolver(null);
        \App\Security\Csrf\Csrf::setResolver(null);
        \App\Database\Database::setResolver(null);
        if (isset($this->app)) $this->app->container()->make(DatabaseManager::class)->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    private static function authSettings(): array
    {
        return [
            'default' => 'web',
            'guards' => [
                'web' => ['driver' => 'session', 'identity' => 'users'],
                'api' => ['driver' => 'token', 'identity' => 'users', 'repository' => 'array'],
            ],
            'identities' => ['users' => ['driver' => 'model', 'model' => BroadcastUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
            'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                'rehash_on_login' => false, 'max_bytes' => 4096],
            'browser' => ['login_path' => null, 'authenticated_path' => null],
            'tokens' => ['driver' => 'array'],
        ];
    }

    private function user(string $email = 'ada@example.test'): BroadcastUser
    {
        return BroadcastUser::create(['email' => $email,
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    private function request(string $path, ?string $csrf = null, ?string $bearer = null,
        string $channel = 'orders.1'): Request
    {
        $headers = ['Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded'];
        if ($csrf !== null) $headers['X-CSRF-Token'] = $csrf;
        if ($bearer !== null) $headers['Authorization'] = 'Bearer ' . $bearer;
        return new Request('POST', $path, [], ['channel' => $channel], [], [], $headers);
    }

    public function testMountedBrowserRouteHonorsCsrfAuthenticationRbacAndPendingMfa(): void
    {
        $user = $this->user();
        $this->app->container()->make(RbacManager::class)->assignRole($user, 'subscriber');
        $csrf = $this->app->container()->make(CsrfTokenManager::class)->token();
        self::assertSame(403, $this->kernel->handle($this->request('/app/broadcast/authorize'))->status());
        self::assertSame(0, $this->ruleCalls);
        self::assertSame(401, $this->kernel->handle($this->request('/app/broadcast/authorize', $csrf))->status());
        self::assertSame(0, $this->ruleCalls);
        $this->auth->login($user);
        $allowed = $this->kernel->handle($this->request('/app/broadcast/authorize', $csrf));
        self::assertSame(200, $allowed->status());
        self::assertSame('{"authorized":true}', $allowed->content());
        self::assertSame(1, $this->ruleCalls);
        self::assertSame(403, $this->kernel->handle(
            $this->request('/app/broadcast/authorize', $csrf, channel: 'orders.999'))->status());
        self::assertSame(2, $this->ruleCalls);
        self::assertSame(404, $this->kernel->handle($this->request('/broadcast/authorize', $csrf))->status());
        self::assertSame(2, $this->ruleCalls);

        // A primary factor waiting for MFA is still a guest to the route.
        $this->app->container()->make(SessionManager::class)->store()->setPendingMfa(['guard' => 'web']);
        self::assertSame(401, $this->kernel->handle($this->request('/app/broadcast/authorize', $csrf))->status());
        self::assertSame(2, $this->ruleCalls);
    }

    public function testNamedTokenGuardRequiresItsOwnAbilityAndGateStillDecides(): void
    {
        $user = $this->user();
        $this->app->container()->make(RbacManager::class)->assignRole($user, 'subscriber');
        $token = $this->auth->tokens('api')->issue($user, 'broadcast', ['broadcast.subscribe'])->token();
        $request = $this->request('/app/api/broadcast/authorize', bearer: $token);
        self::assertSame(200, $this->kernel->handle($request)->status());
        self::assertSame(1, $this->ruleCalls);
        $narrow = $this->auth->tokens('api')->issue($user, 'narrow', ['other.ability'])->token();
        self::assertSame(403, $this->kernel->handle(
            $this->request('/app/api/broadcast/authorize', bearer: $narrow))->status());
        self::assertSame(1, $this->ruleCalls);
        self::assertSame(401, $this->kernel->handle(
            $this->request('/app/api/broadcast/authorize'))->status());
        self::assertSame(1, $this->ruleCalls);

        $this->app->container()->make(AuthorizationManager::class)
            ->define('orders.view', static fn (Authenticatable $identity): bool => false);
        self::assertSame(403, $this->kernel->handle(
            $this->request('/app/api/broadcast/authorize', bearer: $token))->status());
        self::assertSame(2, $this->ruleCalls);
    }

    public function testMalformedOrUnknownChannelDeniesWithoutInvokingRule(): void
    {
        $user = $this->user();
        $this->app->container()->make(RbacManager::class)->assignRole($user, 'subscriber');
        $this->auth->login($user);
        $csrf = $this->app->container()->make(CsrfTokenManager::class)->token();
        foreach (["orders.1\r\nX", 'unknown.1', 'orders..1'] as $channel) {
            $response = $this->kernel->handle(
                $this->request('/app/broadcast/authorize', $csrf, channel: $channel));
            self::assertSame(403, $response->status());
            self::assertStringNotContainsString($channel, $response->content());
        }
        self::assertSame(0, $this->ruleCalls);
    }

    public function testClassAuthorizerResolvesLazilyThroughContainer(): void
    {
        $this->broadcast->privateChannel('teams.{team}', BroadcastTeamAuthorizer::class);
        self::assertSame(0, BroadcastTeamAuthorizer::$constructed);
        $user = $this->user();
        $this->app->container()->make(RbacManager::class)->assignRole($user, 'subscriber');
        $this->auth->login($user);
        $csrf = $this->app->container()->make(CsrfTokenManager::class)->token();
        self::assertSame(200, $this->kernel->handle(
            $this->request('/app/broadcast/authorize', $csrf, channel: 'teams.1'))->status());
        self::assertSame(1, BroadcastTeamAuthorizer::$constructed);
        self::assertSame(403, $this->kernel->handle(
            $this->request('/app/broadcast/authorize', $csrf, channel: 'teams.2'))->status());
        self::assertSame(2, BroadcastTeamAuthorizer::$constructed);
    }
}

/** A modern, persisted Auth identity for mounted route integration. */
final class BroadcastUser extends Model implements Authenticatable
{
    protected string $table = 'broadcast_users';
    protected array $fillable = ['email', 'password'];
    protected array $hidden = ['password'];
    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}

/** Proves class rules receive DI and no handler is built at registration. */
final class BroadcastTeamAuthorizer implements ChannelAuthorizer
{
    public static int $constructed = 0;
    public function __construct(private AuthorizationManager $authorization) { ++self::$constructed; }
    public function authorize(Authenticatable $identity, array $parameters): bool
    {
        return $this->authorization->forIdentity($identity)->allows('orders.view')
            && (string) $identity->authIdentifier() === $parameters['team'];
    }
}
