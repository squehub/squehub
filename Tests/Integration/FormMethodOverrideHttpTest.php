<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionServiceProvider;
use Closure;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Records the effective method after global CSRF and before a route action. */
final class FormMethodProbeMiddleware
{
    /** @var list<string> */
    public array $methods = [];

    public function handle(Request $request, Closure $next): Response
    {
        $this->methods[] = $request->method();
        return $next($request);
    }
}

/** Exercises real Kernel matching and CSRF for POST browser forms with tunneled methods. */
final class FormMethodOverrideHttpTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private Kernel $kernel;
    private FormMethodProbeMiddleware $probe;
    private int $actions = 0;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->app = new Application($this->project->path());
        foreach ([SessionServiceProvider::class, CsrfServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $this->kernel = $this->app->container()->make(Kernel::class);
        $routes = $this->app->container()->make(RouteRegistry::class);
        $this->probe = new FormMethodProbeMiddleware();
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $routes->add($method, '/resource', function (Request $request): string {
                ++$this->actions;
                return $request->method() . ':' . $request->transportMethod();
            })->through($this->probe);
        }
    }

    protected function tearDown(): void
    {
        Csrf::setResolver(null);
        Session::setResolver(null);
        Route::setResolver(null);
        $this->project->remove();
    }

    public function testSpoofedFormsMatchTheirRouteAndRouteMiddleware(): void
    {
        $token = csrf_token();
        foreach (['put' => 'PUT', 'PATCH' => 'PATCH', 'delete' => 'DELETE'] as $submitted => $expected) {
            $request = new Request('POST', '/resource', form: [
                '_method' => $submitted, '_csrf' => $token,
            ], headers: ['Content-Type' => 'application/x-www-form-urlencoded']);
            $response = $this->kernel->handle($request);
            self::assertSame(200, $response->status());
            self::assertSame($expected . ':POST', $response->content());
        }
        self::assertSame(['PUT', 'PATCH', 'DELETE'], $this->probe->methods);
        self::assertSame(3, $this->actions);
    }

    public function testGlobalCsrfStillRejectsSpoofedDeleteBeforeRouteMiddleware(): void
    {
        $token = csrf_token();
        foreach ([[], ['_csrf' => 'invalid']] as $extra) {
            $response = $this->kernel->handle(new Request('POST', '/resource',
                form: ['_method' => 'DELETE', ...$extra],
                headers: ['Content-Type' => 'multipart/form-data; boundary=example']));
            self::assertSame(403, $response->status());
        }
        self::assertSame([], $this->probe->methods);
        self::assertSame(0, $this->actions);

        $allowed = $this->kernel->handle(new Request('POST', '/resource', form: [
            '_method' => 'DELETE', '_csrf' => $token,
        ], headers: ['Content-Type' => 'multipart/form-data; boundary=example']));
        self::assertSame(200, $allowed->status());
        self::assertSame('DELETE:POST', $allowed->content());
        self::assertSame(['DELETE'], $this->probe->methods);
        self::assertSame(1, $this->actions);
    }

    public function testInvalidAndNonFormOverridesCannotReachDeleteRoute(): void
    {
        $token = csrf_token();
        foreach ([
            new Request('GET', '/resource', query: ['_method' => 'DELETE']),
            new Request('POST', '/resource', form: ['_method' => 'TRACE', '_csrf' => $token]),
            new Request('POST', '/resource', form: ['_method' => 'DELETE', '_csrf' => $token],
                headers: ['Content-Type' => 'application/json', 'X-CSRF-Token' => $token],
                rawBody: '{"_method":"DELETE"}'),
        ] as $request) {
            self::assertSame(405, $this->kernel->handle($request)->status());
        }
        self::assertSame([], $this->probe->methods);
        self::assertSame(0, $this->actions);
    }
}
