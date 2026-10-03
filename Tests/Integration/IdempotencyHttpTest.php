<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Auth;
use App\Auth\AuthManager;
use App\Auth\Contracts\Authenticatable;
use App\Authorization\Authorization;
use App\Authorization\AuthorizationManager;
use App\Authorization\Middleware\RequireAbility;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Idempotency\Idempotency;
use App\Idempotency\Middleware\IdempotentRequests;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Actual Kernel, auth, authorization, validation, and safe replay boundaries. */
final class IdempotencyHttpTest extends TestCase
{
    private TestApplication $fixture;
    private Kernel $kernel;
    private AuthManager $auth;
    private RouteRegistry $routes;
    private IdempotencyHttpUser $first;
    private IdempotencyHttpUser $second;

    protected function setUp(): void
    {
        $this->fixture = TestApplication::temporary([
            'csrf' => ['enabled' => false],
            'idempotency' => ['driver' => 'array', 'namespace' => 'http-test'],
            'api' => ['enabled' => true, 'paths' => ['/api']],
            'auth' => [
                'default' => 'web',
                'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
                'identities' => ['users' => ['driver' => 'model', 'model' => IdempotencyHttpUser::class,
                    'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
                'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                    'rehash_on_login' => false],
            ],
        ]);
        $app = $this->fixture->application();
        $container = $app->container();
        $container->make(DatabaseManager::class)->schema()->create('idempotency_test_users',
            static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('password');
            });
        $this->first = IdempotencyHttpUser::create(['email' => 'first@example.test',
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->second = IdempotencyHttpUser::create(['email' => 'second@example.test',
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->kernel = $container->make(Kernel::class);
        $this->auth = $container->make(AuthManager::class);
        $this->routes = $container->make(RouteRegistry::class);
        $this->auth->login($this->first);
    }

    protected function tearDown(): void
    {
        try { $this->fixture->cleanup(); }
        finally {
            Auth::setResolver(null);
            Authorization::setResolver(null);
            Database::setResolver(null);
            Idempotency::setResolver(null);
            Route::setResolver(null);
        }
    }

    private function request(string $key = 'key-12345678', string $body = '{"amount":5}',
        string $path = '/api/orders', string $method = 'POST', array $headers = []): Request
    {
        return new Request($method, $path, headers: [
            'Accept' => 'application/json', 'Content-Type' => 'application/json',
            'Idempotency-Key' => $key, ...$headers,
        ], rawBody: $body);
    }

    public function testReplayConflictRouteMethodAndTrustedIdentityScope(): void
    {
        $runs = 0;
        foreach ([['POST', '/api/orders'], ['PATCH', '/api/orders'], ['POST', '/api/payments']] as [$method, $path]) {
            $this->routes->add($method, $path, static function () use (&$runs): Response {
                ++$runs;
                return new Response('{"run":' . $runs . '}', 201,
                    ['Content-Type' => 'application/json', 'X-Private-Trace' => 'secret']);
            })->through(['auth', IdempotentRequests::authenticated()]);
        }
        $first = $this->kernel->handle($this->request());
        self::assertSame(201, $first->status(), $first->content());
        self::assertSame('{"run":1}', $first->content());
        self::assertSame('false', $first->header('Idempotency-Replayed'));
        $replayed = $this->kernel->handle($this->request());
        self::assertSame(201, $replayed->status());
        self::assertSame($first->content(), $replayed->content());
        self::assertSame('application/json', $replayed->header('Content-Type'));
        self::assertNull($replayed->header('X-Private-Trace'));
        self::assertSame('no-store', $replayed->header('Cache-Control'));
        self::assertSame('true', $replayed->header('Idempotency-Replayed'));
        self::assertSame(1, $runs);

        $conflict = $this->kernel->handle($this->request(body: '{"amount":6}'));
        self::assertSame(409, $conflict->status());
        self::assertSame('idempotency_conflict', json_decode($conflict->content(), true)['error']['code']);
        self::assertSame(1, $runs);
        self::assertSame(201, $this->kernel->handle($this->request(path: '/api/payments'))->status());
        self::assertSame(201, $this->kernel->handle($this->request(method: 'PATCH'))->status());
        self::assertSame(3, $runs);

        $this->auth->login($this->second);
        self::assertSame(201, $this->kernel->handle($this->request())->status());
        self::assertSame(4, $runs);
        $this->auth->login($this->first);
        self::assertSame('true', $this->kernel->handle($this->request())->header('Idempotency-Replayed'));
        self::assertSame(4, $runs);
    }

    public function testHeaderValidationAuthenticationAndAuthorizationAreRechecked(): void
    {
        $allowed = true;
        $this->fixture->application()->container()->make(AuthorizationManager::class)
            ->define('order.create', static function (IdempotencyHttpUser $user) use (&$allowed): bool { return $allowed; });
        $runs = 0;
        $this->routes->post('/api/orders', static function () use (&$runs): Response {
            ++$runs;
            return new Response('created', 201);
        })->through(['auth', RequireAbility::named('order.create'), IdempotentRequests::authenticated()]);
        self::assertSame(400, $this->kernel->handle($this->request('short'))->status());
        self::assertSame(400, $this->kernel->handle($this->request(headers: ['idempotency-key' => 'other-key']))->status());
        self::assertSame(0, $runs);
        self::assertSame(201, $this->kernel->handle($this->request())->status());
        self::assertSame(1, $runs);
        $allowed = false;
        self::assertSame(403, $this->kernel->handle($this->request())->status());
        self::assertSame(1, $runs);
        $allowed = true;
        $this->auth->logout();
        self::assertSame(401, $this->kernel->handle($this->request())->status());
        self::assertSame(1, $runs);
    }

    public function testInProgressValidationServerFailureAndUnreplayableResult(): void
    {
        $runs = 0;
        $nested = null;
        $this->routes->post('/api/orders', function () use (&$runs, &$nested): Response {
            ++$runs;
            if ($runs === 1) $nested = $this->kernel->handle($this->request());
            return new Response('done', 200, ['Content-Type' => 'text/plain']);
        })->through(['auth', IdempotentRequests::authenticated()]);
        $outer = $this->kernel->handle($this->request());
        self::assertSame(200, $outer->status(), $outer->content());
        self::assertInstanceOf(Response::class, $nested);
        self::assertSame(409, $nested->status());
        self::assertSame('1', $nested->header('Retry-After'));
        self::assertSame(1, $runs);
        self::assertSame('true', $this->kernel->handle($this->request())->header('Idempotency-Replayed'));

        $validationRuns = 0;
        $this->routes->post('/api/validation', static function (Request $request) use (&$validationRuns): Response {
            ++$validationRuns;
            $request->validate(['required' => 'required']);
            return new Response('never');
        })->through(['auth', IdempotentRequests::authenticated()]);
        for ($i = 0; $i < 2; ++$i) {
            self::assertSame(422, $this->kernel->handle($this->request(path: '/api/validation'))->status());
        }
        self::assertSame(2, $validationRuns);

        $failures = 0;
        $this->routes->post('/api/failure', static function () use (&$failures): never {
            ++$failures;
            throw new RuntimeException('server failed');
        })->through(['auth', IdempotentRequests::authenticated()]);
        for ($i = 0; $i < 2; ++$i) {
            self::assertSame(500, $this->kernel->handle($this->request(path: '/api/failure'))->status());
        }
        self::assertSame(2, $failures);

        $unreplayableRuns = 0;
        $this->routes->post('/api/unreplayable', static function () use (&$unreplayableRuns): Response {
            ++$unreplayableRuns;
            return new Response('created', 201, ['Set-Cookie' => 'session=secret']);
        })->through(['auth', IdempotentRequests::authenticated()]);
        self::assertSame(201, $this->kernel->handle($this->request(path: '/api/unreplayable'))->status());
        $duplicate = $this->kernel->handle($this->request(path: '/api/unreplayable'));
        self::assertSame(409, $duplicate->status());
        self::assertSame('idempotency_unreplayable', json_decode($duplicate->content(), true)['error']['code']);
        self::assertSame(1, $unreplayableRuns);
    }

    public function testLeaseExpiryAfterMutationLeavesAmbiguousFailureAndAllowsRetry(): void
    {
        // A paused owner may mutate the outside world and then lose its lease.
        // The failed completion cannot retroactively undo that first mutation.
        $this->fixture->application()->config()->set('idempotency.lease_seconds', 1);
        $runs = 0;
        $this->routes->post('/api/slow', static function () use (&$runs): Response {
            ++$runs;
            if ($runs === 1) sleep(2);
            return new Response('done', 201);
        })->through(['auth', IdempotentRequests::authenticated()]);
        self::assertSame(500, $this->kernel->handle($this->request(path: '/api/slow'))->status());
        self::assertSame(1, $runs);
        self::assertSame(201, $this->kernel->handle($this->request(path: '/api/slow'))->status());
        self::assertSame(2, $runs);
    }

    public function testUnavailableSelectedDatabaseFailsBeforeHandlerWithoutFallback(): void
    {
        $this->fixture->application()->config()->set('idempotency.driver', 'database');
        $this->fixture->application()->config()->set('idempotency.namespace', 'missing-table-test');
        $runs = 0;
        $this->routes->post('/api/missing-store', static function () use (&$runs): Response {
            ++$runs;
            return new Response('unexpected');
        })->through(['auth', IdempotentRequests::authenticated()]);
        $response = $this->kernel->handle($this->request(path: '/api/missing-store'));
        self::assertSame(500, $response->status());
        self::assertSame(0, $runs);
        self::assertStringNotContainsString('missing-table-test', $response->content());
    }
}

final class IdempotencyHttpUser extends Model implements Authenticatable
{
    protected string $table = 'idempotency_test_users';
    protected array $fillable = ['email', 'password'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}
