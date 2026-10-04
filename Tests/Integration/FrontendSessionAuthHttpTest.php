<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\Auth;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Auth\PasswordHasher;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\Routing\Route;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionManager;
use App\Session\SessionServiceProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** A disposable identity exercises the existing Session guard through a SPA. */
final class FrontendSessionIdentity extends Model implements Authenticatable
{
    protected string $table = 'frontend_session_users';
    protected array $fillable = ['email', 'password', 'name'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return (int) $this->getAttribute('id'); }
    public function authPasswordHash(): string { return (string) $this->getAttribute('password'); }
}

/** No frontend-only guard, token store, or CSRF exemption is involved. */
final class FrontendSessionAuthHttpTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private Kernel $kernel;
    private SessionManager $sessions;
    private FrontendSessionIdentity $user;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for frontend Session Auth integration.');
        }
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env"=>"testing","debug"=>false];');
        $this->project->write('Config/Database.php',
            '<?php return ["default"=>"main","connections"=>["main"=>["driver"=>"sqlite","database"=>":memory:"]]];');
        $this->project->write('Config/Session.php', '<?php return ["driver"=>"array"];');
        $this->project->write('Config/Csrf.php',
            '<?php return ["enabled"=>true,"field"=>"_csrf","header"=>"X-CSRF-Token","except"=>[]];');
        $this->project->write('Config/Api.php',
            '<?php return ["enabled"=>true,"paths"=>["/api"],"cors"=>["enabled"=>false]];');
        $this->project->write('Config/Frontend.php',
            '<?php return ["spa"=>["enabled"=>true,"prefix"=>"/frontend","view"=>"Frontend.App","except"=>[]]];');
        $this->project->write('Config/Auth.php', '<?php return ' . var_export([
            'default' => 'web',
            'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
            'identities' => ['users' => ['driver' => 'model', 'model' => FrontendSessionIdentity::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
            'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                'rehash_on_login' => true, 'max_bytes' => 4096],
            'browser' => ['login_path' => '/frontend/login', 'authenticated_path' => '/frontend'],
        ], true) . ';');
        $this->project->write('Project/Views/Frontend/App.squehub.php',
            '<!doctype html><html><head><meta name="csrf-token" content="{{ csrf_token() }}"></head>'
            . '<body><div id="frontend-app">Session frontend</div></body></html>');

        $this->app = new Application($this->project->path());
        foreach ([DatabaseServiceProvider::class, SessionServiceProvider::class,
            CsrfServiceProvider::class, HttpServiceProvider::class,
            RoutingServiceProvider::class, AuthServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $this->kernel = $this->app->container()->make(Kernel::class);
        $this->sessions = $this->app->container()->make(SessionManager::class);
        $this->app->container()->make(DatabaseManager::class)->schema()->create(
            'frontend_session_users', static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('password');
                $table->string('name');
                $table->datetime('created_at')->nullable();
                $table->datetime('updated_at')->nullable();
            }
        );
        $this->user = FrontendSessionIdentity::create([
            'email' => 'ada@example.test', 'name' => 'Ada',
            'password' => $this->app->container()->make(PasswordHasher::class)->hash('correct'),
        ]);

        Route::path('/api/login')->post(static function (Request $request): JsonResponse {
            $authenticated = Auth::attempt([
                'email' => (string) $request->input('email'),
                'password' => (string) $request->input('password'),
            ]);
            return new JsonResponse(['authenticated' => $authenticated], $authenticated ? 200 : 401);
        });
        Route::path('/api/me')->get(static fn (): array => ['id' => Auth::id()])->through('auth');
        Route::path('/api/logout')->post(static function (): array {
            Auth::logout();
            return ['logged_out' => true];
        })->through('auth');
    }

    protected function tearDown(): void
    {
        \App\Auth\Auth::setResolver(null);
        Database::setResolver(null);
        Session::setResolver(null);
        Csrf::setResolver(null);
        Route::setResolver(null);
        if (isset($this->project)) $this->project->remove();
    }

    /** Render the selected shell to acquire its current Session-bound token. */
    private function shellToken(): string
    {
        $shell = $this->kernel->handle(new Request('GET', '/frontend/dashboard',
            headers: ['Accept' => 'text/html']));
        self::assertSame(200, $shell->status(), $shell->content());
        self::assertSame('private, no-store', $shell->header('Cache-Control'));
        self::assertSame('Accept', $shell->header('Vary'));
        self::assertMatchesRegularExpression('/<meta name="csrf-token" content="([0-9a-f]{64})">/',
            $shell->content());
        preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})">/', $shell->content(), $match);
        return $match[1];
    }

    private function login(?string $token, array $extraHeaders = []): \App\Http\Response
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json',
            ...$extraHeaders];
        if ($token !== null) $headers['X-CSRF-Token'] = $token;
        return $this->kernel->handle(new Request('POST', '/api/login', headers: $headers,
            rawBody: '{"email":"ada@example.test","password":"correct"}'));
    }

    public function testSameOriginShellLoginAuthenticatedRequestAndLogout(): void
    {
        $token = $this->shellToken();
        $sessionBeforeLogin = $this->sessions->store()->id();
        $this->sessions->store()->close();
        $login = $this->login($token);
        self::assertSame(200, $login->status(), $login->content());
        self::assertSame(['authenticated' => true], json_decode($login->content(), true));
        self::assertNotSame($sessionBeforeLogin, $this->sessions->store()->id());
        self::assertSame($token, Csrf::manager()->token());

        $this->sessions->store()->close();
        $me = $this->kernel->handle(new Request('GET', '/api/me',
            headers: ['Accept' => 'application/json']));
        self::assertSame(200, $me->status());
        self::assertSame(['id' => (int) $this->user->getAttribute('id')],
            json_decode($me->content(), true));

        $this->sessions->store()->close();
        $logout = $this->kernel->handle(new Request('POST', '/api/logout',
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $token]));
        self::assertSame(200, $logout->status(), $logout->content());
        self::assertSame(['logged_out' => true], json_decode($logout->content(), true));
        self::assertNull($this->sessions->store()->csrfToken());

        $this->sessions->store()->close();
        self::assertSame(401, $this->kernel->handle(new Request('GET', '/api/me',
            headers: ['Accept' => 'application/json']))->status());
        self::assertSame(403, $this->login($token)->status());
        $newToken = $this->shellToken();
        self::assertNotSame($token, $newToken);
    }

    public function testCsrfFailureAndBearerHeaderDoNotBypassSessionProtection(): void
    {
        $token = $this->shellToken();
        $this->sessions->store()->close();
        self::assertSame(403, $this->login(null)->status());
        self::assertSame(403, $this->login('invalid')->status());
        self::assertSame(403, $this->login(null,
            ['Authorization' => 'Bearer unrelated-api-token'])->status());
        self::assertFalse(Auth::check());
        self::assertSame(200, $this->login($token)->status());
    }

    public function testSameOriginProxyPatternDoesNotEnableCredentialedCors(): void
    {
        $token = $this->shellToken();
        $this->sessions->store()->close();
        $sameOrigin = $this->login($token, ['Origin' => 'https://app.example.test']);
        self::assertSame(200, $sameOrigin->status());
        self::assertNull($sameOrigin->header('Access-Control-Allow-Origin'));
        self::assertSame(200, $this->kernel->handle(new Request('GET', '/api/me',
            headers: ['Accept' => 'application/json'],
            server: ['HTTP_HOST' => 'app.example.test']))->status());
    }
}
