<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Auth;
use App\Auth\AuthManager;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Auth\PasswordHasher;
use App\Authorization\Authorization;
use App\Authorization\AuthorizationDecision;
use App\Authorization\AuthorizationManager;
use App\Authorization\AuthorizationServiceProvider;
use App\Authorization\Middleware\RequireAbility;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Logging\Drivers\ArrayLogger;
use App\Logging\Log;
use App\Logging\LoggingServiceProvider;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionServiceProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Real HTTP, Auth, and SQLite checks for the authorization boundary. */
final class AuthorizationIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private AuthManager $auth;
    private AuthorizationManager $authorization;
    private DatabaseManager $database;
    private Kernel $kernel;
    private Diagnostics $diagnostics;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env" => "production", "debug" => false];');
        $this->project->write('Config/Database.php', '<?php return ["default" => "main", "connections" => ["main" => ["driver" => "sqlite", "database" => ":memory:"]]];');
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/Csrf.php', '<?php return ["enabled" => true, "field" => "_csrf", "header" => "X-CSRF-Token", "except" => []];');
        $this->project->write('Config/Logging.php', '<?php return ["driver" => "array", "level" => "debug"];');
        $this->project->write('Config/Auth.php', '<?php return ' . var_export(self::authConfig(), true) . ';');
        $this->project->write('Config/Authorization.php', '<?php return ["abilities" => [], "policies" => []];');
        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, LoggingServiceProvider::class,
            DatabaseServiceProvider::class, SessionServiceProvider::class, CsrfServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class, AuthServiceProvider::class,
            AuthorizationServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $services = $this->app->container();
        $this->auth = $services->make(AuthManager::class);
        $this->authorization = $services->make(AuthorizationManager::class);
        $this->database = $services->make(DatabaseManager::class);
        $this->kernel = $services->make(Kernel::class);
        $this->diagnostics = $services->make(Diagnostics::class);
        $this->database->schema()->create('authorization_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        Authorization::setResolver(null);
        Auth::setResolver(null);
        Log::setResolver(null);
        Session::setResolver(null);
        Database::setResolver(null);
        Csrf::setResolver(null);
        Route::setResolver(null);
        if (isset($this->project)) $this->project->remove();
    }

    private static function authConfig(): array
    {
        return [
            'default' => 'web',
            'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
            'identities' => ['users' => ['driver' => 'model', 'model' => AuthorizationUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
            'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                'rehash_on_login' => false, 'max_bytes' => 4096],
            'browser' => ['login_path' => null, 'authenticated_path' => null],
        ];
    }

    private function user(string $name = 'Ada'): AuthorizationUser
    {
        return AuthorizationUser::create(['email' => strtolower($name) . '@example.test',
            'name' => $name, 'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    private function json(string $uri): Request
    {
        return new Request('GET', $uri, [], [], [], [], ['Accept' => 'application/json']);
    }

    public function testAuthThenAbilityMiddlewareGuestAndAuthenticatedOutcomes(): void
    {
        $this->authorization->define('reports.view', static fn (AuthorizationUser $user): bool => $user->name === 'Ada');
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/reports', static fn (): string => 'report')
            ->through(['auth', RequireAbility::named('reports.view')]);
        $routes->get('/ability', static fn (): string => 'report')
            ->through(RequireAbility::named('reports.view'));
        $guest = $this->kernel->handle($this->json('/reports'));
        self::assertSame(401, $guest->status());
        self::assertSame(0, $this->diagnostics->snapshot()['authorization']['checks']);
        $withoutAuth = $this->kernel->handle($this->json('/ability'));
        self::assertSame(403, $withoutAuth->status());
        self::assertSame('{"message":"This action is not authorized."}', $withoutAuth->content());
        self::assertSame(['checks' => 1, 'allowed' => 0, 'denied' => 1, 'errors' => 0],
            $this->diagnostics->snapshot()['authorization']);

        $this->auth->login($this->user());
        $allowed = $this->kernel->handle($this->json('/reports'));
        self::assertSame(200, $allowed->status());
        self::assertSame('report', $allowed->content());
        self::assertSame(['checks' => 1, 'allowed' => 1, 'denied' => 0, 'errors' => 0],
            $this->diagnostics->snapshot()['authorization']);
        self::assertSame([], $this->app->container()->make(ArrayLogger::class)->records());
    }

    public function testCurrentIdentityCacheAndExplicitContextDoNotImpersonate(): void
    {
        $ada = $this->user();
        $bea = $this->user('Bea');
        $this->auth->login($ada);
        $this->authorization->define('reports.view', static fn (AuthorizationUser $user): bool => $user->name === 'Ada');
        $this->auth->resetRequestState();
        $this->diagnostics->begin(new Request('GET', '/probe'));
        self::assertTrue($this->authorization->allows('reports.view'));
        self::assertTrue($this->authorization->allows('reports.view'));
        self::assertSame(1, $this->diagnostics->queryCount());
        $current = $this->auth->user();
        self::assertFalse($this->authorization->forIdentity($bea)->allows('reports.view'));
        self::assertSame($current, $this->auth->user());
        self::assertSame(1, $this->diagnostics->queryCount());
    }

    public function testNestedGroupAcceptsObjectAndInvokableMiddlewareInOrder(): void
    {
        $this->auth->login($this->user());
        $this->authorization->define('reports.view', static fn (AuthorizationUser $user): bool => true);
        $outer = new class {
            public function __invoke(Request $request, \Closure $next): \App\Http\Response
            {
                return $next($request)->withHeader('X-Outer', 'visited');
            }
        };
        Route::group()->prefix('/secure')->through(['auth', $outer])->routes(static function (): void {
            Route::path('/reports')->get(static fn (): string => 'inside')
                ->through(RequireAbility::named('reports.view'));
        });

        $route = $this->app->container()->make(RouteRegistry::class)->all()[0];
        self::assertCount(3, $route->middlewares());
        $response = $this->kernel->handle($this->json('/secure/reports'));
        self::assertSame(200, $response->status());
        self::assertSame('inside', $response->content());
        self::assertSame('visited', $response->header('X-Outer'));
    }

    public function testPolicyDenialEscapesHtmlAndConfigurationErrorsStay500(): void
    {
        $this->auth->login($this->user());
        $this->authorization->policy(AuthorizationDocument::class, AuthorizationDocumentPolicy::class);
        $document = new AuthorizationDocument(false);
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/document', static function () use ($document): string {
            \authorize()->require('update', $document);
            return 'must not run';
        });
        $routes->get('/missing-ability', static fn (): bool => \authorize()->allows('absent.ability'));
        $html = $this->kernel->handle(new Request('GET', '/document'));
        self::assertSame(403, $html->status());
        self::assertStringContainsString('&lt;script&gt;', $html->content());
        self::assertStringNotContainsString('<script>', $html->content());
        $json = $this->kernel->handle($this->json('/document'));
        self::assertSame(403, $json->status());
        self::assertSame(['message' => 'Locked <script>'], json_decode($json->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('<script>', $json->content());
        self::assertSame([], $this->app->container()->make(ArrayLogger::class)->records());

        $broken = $this->kernel->handle($this->json('/missing-ability'));
        self::assertSame(500, $broken->status());
        self::assertSame('{"error":"Internal Server Error"}', $broken->content());
        self::assertCount(1, $this->app->container()->make(ArrayLogger::class)->records());
    }

    public function testCsrfAndAuthorizationRunBeforeControllerValidation(): void
    {
        $this->auth->login($this->user());
        $this->authorization->define('forms.submit', static fn (AuthorizationUser $user): bool => false);
        $called = false;
        $this->app->container()->make(RouteRegistry::class)
            ->post('/form', static function (Request $request) use (&$called): string {
                $called = true;
                $request->validate(['name' => 'required']);
                return 'unexpected';
            })->through(RequireAbility::named('forms.submit'));

        $badToken = $this->kernel->handle(new Request('POST', '/form', [], [], [], [], ['Accept' => 'application/json']));
        self::assertSame(403, $badToken->status());
        self::assertSame('{"message":"CSRF verification failed."}', $badToken->content());
        self::assertSame(0, $this->diagnostics->snapshot()['authorization']['checks']);
        $token = \csrf_token();
        $denied = $this->kernel->handle(new Request('POST', '/form', [], ['_csrf' => $token], [], [],
            ['Accept' => 'application/json']));
        self::assertSame(403, $denied->status());
        self::assertSame('{"message":"This action is not authorized."}', $denied->content());
        self::assertFalse($called);
        self::assertSame(1, $this->diagnostics->snapshot()['authorization']['checks']);
    }
}

/** Auth fixture is a real modern Model so guard query caching is exercised. */
final class AuthorizationUser extends Model implements Authenticatable
{
    protected string $table = 'authorization_users';
    protected array $fillable = ['email', 'password', 'name'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}

/** Resource fixture deliberately has no Model or Database dependency. */
final class AuthorizationDocument
{
    public function __construct(public bool $editable)
    {
    }
}

/** Application-owned policy returns a safe-to-escape authored denial. */
final class AuthorizationDocumentPolicy
{
    public function update(AuthorizationUser $user, AuthorizationDocument $document): AuthorizationDecision
    {
        return $document->editable ? AuthorizationDecision::allow()
            : AuthorizationDecision::deny('Locked <script>');
    }
}
