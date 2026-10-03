<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Routing\MiddlewareRegistry;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionManager;
use App\Session\SessionServiceProvider;
use App\Validation\ValidationServiceProvider;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

final class CsrfExecutionTrace
{
    public int $middleware = 0;
    public int $controller = 0;
}

final class CsrfRouteProbe
{
    public function __construct(private CsrfExecutionTrace $trace) {}

    public function handle(Request $request, Closure $next): Response
    {
        ++$this->trace->middleware;
        return $next($request);
    }
}

/** Exercises the canonical Kernel guard with real routes and both drivers. */
final class CsrfHttpTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private Kernel $kernel;
    private RouteRegistry $routes;
    private CsrfExecutionTrace $trace;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->boot();
        $this->trace = new CsrfExecutionTrace();
        $this->app->container()->instance(CsrfExecutionTrace::class, $this->trace);
        $this->app->container()->make(MiddlewareRegistry::class)->alias('csrf-probe', CsrfRouteProbe::class);
        $this->routes->add(['POST', 'PUT', 'PATCH', 'DELETE'], '/submit',
            function (Request $request): array {
                ++$this->trace->controller;
                return ['name' => $request->input('name', '')];
            })->through('csrf-probe');
        $this->routes->add(['GET', 'HEAD', 'OPTIONS'], '/safe',
            function (): string {
                ++$this->trace->controller;
                return 'safe';
            });
    }

    protected function tearDown(): void
    {
        Csrf::setResolver(null);
        Session::setResolver(null);
        Route::setResolver(null);
        $this->project->remove();
    }

    private function boot(): void
    {
        $this->app = new Application($this->project->path());
        $this->app->register(SessionServiceProvider::class);
        $this->app->register(ValidationServiceProvider::class);
        $this->app->register(CsrfServiceProvider::class);
        $this->app->register(HttpServiceProvider::class);
        $this->app->register(RoutingServiceProvider::class);
        $this->app->bootstrap();
        $this->kernel = $this->app->container()->make(Kernel::class);
        $this->routes = $this->app->container()->make(RouteRegistry::class);
    }

    public function testSafeMethodsDoNotIssueOrRequireToken(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            self::assertSame(200, $this->kernel->handle(new Request($method, '/safe'))->status());
        }
        self::assertSame(3, $this->trace->controller);
        self::assertNull(session()->csrfToken());
    }

    public function testUnsafeMethodsRejectMissingInvalidQueryAndNonStringTokens(): void
    {
        $token = csrf_token();
        session()->put('theme', 'dark');
        session()->flash('status', 'saved');
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach ([
                new Request($method, '/submit'),
                new Request($method, '/submit', ['_csrf' => $token]),
                new Request($method, '/submit', [], ['_csrf' => 'wrong']),
                new Request($method, '/submit', [], ['_csrf' => ['unexpected']]),
            ] as $request) {
                $response = $this->kernel->handle($request);
                self::assertSame(403, $response->status());
                self::assertStringNotContainsString($token, $response->content());
            }
        }
        self::assertSame(0, $this->trace->middleware);
        self::assertSame(0, $this->trace->controller);
        self::assertSame('dark', session()->get('theme'));
        self::assertSame('saved', session()->get('status'));
        self::assertSame([], old());
    }

    public function testBodyHeaderAndLegacyFieldReachRouteMiddlewareAndController(): void
    {
        $token = csrf_token();
        $requests = [
            new Request('POST', '/submit', [], ['_csrf' => $token, 'name' => 'body']),
            new Request('PUT', '/submit', [], ['name' => 'header'], [], [], ['X-CSRF-Token' => $token]),
            new Request('PATCH', '/submit', [], ['_token' => $token, 'name' => 'legacy']),
            new Request('DELETE', '/submit', [], ['_csrf' => $token, 'name' => 'delete']),
        ];
        foreach ($requests as $request) {
            self::assertSame(200, $this->kernel->handle($request)->status());
        }
        self::assertSame(4, $this->trace->middleware);
        self::assertSame(4, $this->trace->controller);
    }

    public function testPresentHeaderTakesPrecedenceOverBody(): void
    {
        $token = csrf_token();
        $badHeader = new Request('POST', '/submit', [], ['_csrf' => $token], [], [], ['x-csrf-token' => 'wrong']);
        self::assertSame(403, $this->kernel->handle($badHeader)->status());
        $goodHeader = new Request('POST', '/submit', [], ['_csrf' => 'wrong'], [], [], ['X-CSRF-Token' => $token]);
        self::assertSame(200, $this->kernel->handle($goodHeader)->status());
        self::assertSame(1, $this->trace->controller);
    }

    public function testJsonFailureIsSafeAndValidHeaderAllowsJsonAndMultipart(): void
    {
        $token = csrf_token();
        $json = new Request('POST', '/submit', [], [], [], [],
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'], [], '{"name":"JSON"}');
        $failure = $this->kernel->handle($json);
        self::assertSame(403, $failure->status());
        self::assertSame(['message' => 'CSRF verification failed.'], json_decode($failure->content(), true));
        self::assertStringNotContainsString($token, $failure->content());
        $jsonWithHeader = new Request('POST', '/submit', [], [], [], [],
            ['Content-Type' => 'application/json', 'X-CSRF-Token' => $token], [], '{"name":"JSON"}');
        self::assertSame(200, $this->kernel->handle($jsonWithHeader)->status());
        $jsonWithBody = new Request('POST', '/submit', [], [], [], [],
            ['Content-Type' => 'application/json'], [], json_encode(['_csrf' => $token, 'name' => 'JSON']));
        self::assertSame(200, $this->kernel->handle($jsonWithBody)->status());
        $multipart = new Request('POST', '/submit', [], ['_csrf' => $token, 'name' => 'form'], [],
            ['upload' => ['tmp_name' => null, 'error' => UPLOAD_ERR_NO_FILE]],
            ['Content-Type' => 'multipart/form-data; boundary=test']);
        self::assertSame(200, $this->kernel->handle($multipart)->status());
    }

    public function testHtmlAndDebugFailuresNeverRenderTokenMaterial(): void
    {
        $token = csrf_token();
        $this->app->config()->set('app.debug', true);
        $response = $this->kernel->handle(new Request('POST', '/submit', [], ['_csrf' => 'invalid']));
        self::assertSame(403, $response->status());
        self::assertStringContainsString('<h1>Forbidden</h1>', $response->content());
        self::assertStringContainsString('CSRF verification failed.', $response->content());
        self::assertStringNotContainsString($token, $response->content());
        self::assertStringNotContainsString('invalid', $response->content());
        self::assertStringNotContainsString('Stack trace', $response->content());
    }

    public function testCsrfFailurePrecedesControllerValidationAndNeverFlashesInput(): void
    {
        $this->routes->post('/validate', function (Request $request): array {
            ++$this->trace->controller;
            return $request->validate(['name' => 'required|string']);
        });
        $token = csrf_token();
        $invalid = new Request('POST', '/validate', [], ['email' => 'v@example.com', 'password' => 'private']);
        self::assertSame(403, $this->kernel->handle($invalid)->status());
        self::assertSame(0, $this->trace->controller);
        self::assertSame([], old());
        $validated = new Request('POST', '/validate', [], ['_csrf' => $token]);
        self::assertSame(422, $this->kernel->handle($validated)->status());
        self::assertSame(1, $this->trace->controller);
    }

    public function testUnknownUnsafeRouteIsRejectedBeforeMatchingAndSafeMissingRouteStays404(): void
    {
        self::assertSame(403, $this->kernel->handle(new Request('POST', '/missing'))->status());
        $this->routes->post('/api/resource', static fn (): string => 'api');
        self::assertSame(403, $this->kernel->handle(new Request('POST', '/api/resource'))->status());
        self::assertSame(404, $this->kernel->handle(new Request('GET', '/missing'))->status());
        self::assertSame(403, $this->kernel->handle(new Request('DELETE', '/safe'))->status());
        self::assertSame(0, $this->trace->controller);
    }

    public function testExclusionsAreExplicitAndDoNotMatchNearMissOrAmbiguousPaths(): void
    {
        $this->project->write('Config/Csrf.php', '<?php return ["except" => ["/webhooks/provider", "/hooks/*"]];');
        $this->boot();
        foreach (['/webhooks/provider', '/hooks/item', '/hooks/item/child'] as $route) {
            $this->routes->post($route, static fn (): string => 'excluded');
        }
        foreach (['/webhooks/provider', '/webhooks/provider?source=remote', '/hooks/item', '/hooks/item/child'] as $path) {
            self::assertSame(200, $this->kernel->handle(new Request('POST', $path))->status());
        }
        foreach (['/hooksmith/admin', '/hooks', '/hooks//item', '/hooks/%2e%2e/admin'] as $path) {
            $this->routes->post($path, static fn (): string => 'protected');
            self::assertSame(403, $this->kernel->handle(new Request('POST', $path))->status());
        }
    }

    public function testInvalidExclusionsFailBootAndExplicitDisableAllowsUnsafeRequest(): void
    {
        foreach (['/hooks/**', '/hooks/../admin', '/hooks/%2e%2e', '/hooks/', '/hooks/bad path', 3] as $pattern) {
            $this->project->write('Config/Csrf.php', '<?php return ["except" => [' . var_export($pattern, true) . ']];');
            try {
                $this->boot();
                self::fail('Invalid exclusion was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach (['["enabled" => "false"]', '["header" => "bad header"]', '["except" => "all"]'] as $invalid) {
            $this->project->write('Config/Csrf.php', '<?php return ' . $invalid . ';');
            try {
                $this->boot();
                self::fail('Invalid CSRF configuration was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->project->write('Config/Csrf.php', '<?php return ["enabled" => false];');
        $this->boot();
        $this->routes->post('/disabled', static fn (): string => 'allowed');
        self::assertSame(200, $this->kernel->handle(new Request('POST', '/disabled'))->status());
    }

    public function testNativeSessionPersistsIssuedTokenAcrossRequestBoundaries(): void
    {
        $project = new TemporaryProject();
        $directory = $project->path('sessions');
        mkdir($directory);
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . 'session_save_path(' . var_export($directory, true) . '); '
            . '$sessions = new \\App\\Session\\SessionManager(new \\App\\Config\\Repository(["session" => ["driver" => "native", "name" => "csrf_test"]])); '
            . '$tokens = new \\App\\Security\\Csrf\\CsrfTokenManager($sessions); '
            . '$middleware = new \\App\\Security\\Csrf\\CsrfMiddleware(new \\App\\Config\\Repository(), $tokens); '
            . '$issued = $middleware->handle(new \\App\\Http\\Request("GET", "/form"), '
            . 'static fn (\\App\\Http\\Request $request): \\App\\Http\\Response => new \\App\\Http\\Response($tokens->token()))->content(); '
            . '$sessions->store()->close(); '
            . '$next = static fn (\\App\\Http\\Request $request): \\App\\Http\\Response => new \\App\\Http\\Response("pass"); '
            . '$ok = $middleware->handle(new \\App\\Http\\Request("POST", "/save", [], ["_csrf" => $issued]), $next)->content(); '
            . 'try { $middleware->handle(new \\App\\Http\\Request("POST", "/save", [], ["_csrf" => "bad"]), $next); $bad = false; } '
            . 'catch (\\App\\Security\\Csrf\\CsrfException $e) { $bad = true; } '
            . '$hidden = $sessions->store()->all() === []; $sessions->store()->close(); '
            . 'echo json_encode(compact("ok", "bad", "hidden"));';
        try {
            $process = new Process([PHP_BINARY, '-r', $code], $root);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame(['ok' => 'pass', 'bad' => true, 'hidden' => true],
                json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            $project->remove();
        }
    }
}
