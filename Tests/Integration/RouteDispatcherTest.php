<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Core\View;
use App\Http\Exception\HttpException;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Packages\PackageManager;
use App\Routing\MiddlewareRegistry;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use Closure;
use PHPUnit\Framework\TestCase;
use Router;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__) . '/Fixtures/Controllers/CharacterizationController.php';
require_once dirname(__DIR__) . '/Fixtures/Controllers/PackageProbeController.php';
require_once dirname(__DIR__, 2) . '/Router.php';

final class RouteExecutionTrace { public array $events = []; }

final class FirstRouteMiddleware
{
    public function __construct(private RouteExecutionTrace $trace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->trace->events[] = 'first.before';
        $response = $next($request);
        $this->trace->events[] = 'first.after';
        return $response->withHeader('X-First', 'done');
    }
}

final class SecondRouteMiddleware
{
    public function __construct(private RouteExecutionTrace $trace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->trace->events[] = 'second.before';
        $response = $next($request);
        $this->trace->events[] = 'second.after';
        return $response->withHeader('X-Second', 'done');
    }
}

final class StopRouteMiddleware
{
    public function __construct(private RouteExecutionTrace $trace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->trace->events[] = 'stop';
        return new Response('blocked', 403);
    }
}

final class RoutingGreetingService
{
    public function __construct(private string $prefix) {}
    public function message(string $id): string { return $this->prefix . ':' . $id; }
}

final class InjectedRouteController
{
    public function __construct(private RoutingGreetingService $greeting) {}

    public function show(Request $request, string $id): array
    {
        return ['id' => $id, 'route' => $request->route('id'), 'message' => $this->greeting->message($id)];
    }
}

final class RouteDispatcherTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private RouteRegistry $routes;
    private RouteExecutionTrace $trace;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/Routing.php',
            '<?php return ["middleware" => ["first" => \\SqueHub\\Tests\\Integration\\FirstRouteMiddleware::class]];');
        $this->app = new Application($this->project->path());
        $this->app->register(HttpServiceProvider::class);
        $this->app->register(RoutingServiceProvider::class);
        $this->app->bootstrap();
        $this->routes = $this->app->container()->make(RouteRegistry::class);
        $this->trace = new RouteExecutionTrace();
        $this->app->container()->instance(RouteExecutionTrace::class, $this->trace);
    }

    protected function tearDown(): void
    {
        Route::setResolver(null);
        $this->project->remove();
    }

    public function testMiddlewareRunsInNestedOrderAndResolvesAliasAndClassThroughContainer(): void
    {
        self::assertSame(FirstRouteMiddleware::class,
            $this->app->container()->make(MiddlewareRegistry::class)->resolve('first'));
        Route::group()->through('first')->routes(function (): void {
            Route::path('/order')->get(function (): string {
                $this->trace->events[] = 'controller';
                return 'done';
            })->through(SecondRouteMiddleware::class);
        });

        $response = $this->handle(new Request('GET', '/order'));
        self::assertSame('done', $response->content());
        self::assertSame(200, $response->status());
        self::assertSame('done', $response->header('X-First'));
        self::assertSame('done', $response->header('X-Second'));
        self::assertSame([
            'first.before', 'second.before', 'controller', 'second.after', 'first.after',
        ], $this->trace->events);
    }

    public function testMiddlewareShortCircuitSkipsLaterMiddlewareAndController(): void
    {
        Route::path('/blocked')->get(function (): string {
            $this->trace->events[] = 'controller';
            return 'unreachable';
        })->through([FirstRouteMiddleware::class, StopRouteMiddleware::class, SecondRouteMiddleware::class]);

        $response = $this->handle(new Request('GET', '/blocked'));
        self::assertSame(403, $response->status());
        self::assertSame('blocked', $response->content());
        self::assertSame(['first.before', 'stop', 'first.after'], $this->trace->events);
    }

    public function testControllerConstructorRequestAndRouteParametersAreResolved(): void
    {
        $this->app->container()->instance(RoutingGreetingService::class, new RoutingGreetingService('injected'));
        Route::path('/users/{id}')->get([InjectedRouteController::class, 'show']);
        $request = new Request('GET', '/users/42', ['id' => 'query'], ['id' => 'form']);

        $response = $this->handle($request);
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(['id' => '42', 'route' => '42', 'message' => 'injected:42'],
            json_decode($response->content(), true));
        self::assertSame('42', $request->route('id'));
        self::assertSame(['id' => '42'], $request->route());
        self::assertSame('query', $request->query('id'));
        self::assertSame('form', $request->input('id'));
    }

    public function testClosureResultsUseExistingHttpNormalization(): void
    {
        Route::path('/json')->post(static function (Request $request): array {
            echo 'discard this output';
            return ['name' => $request->input('name')];
        });
        Route::path('/text')->get(static function (): string {
            echo 'prefix:';
            return 'text';
        });
        Route::path('/explicit')->get(static function (): Response {
            echo 'discard this output';
            return new Response('explicit', 202);
        });

        $json = $this->handle(new Request('POST', '/json', [], ['name' => 'Ada']));
        self::assertInstanceOf(JsonResponse::class, $json);
        self::assertSame(['name' => 'Ada'], json_decode($json->content(), true));
        self::assertSame('prefix:text', $this->handle(new Request('GET', '/text'))->content());
        $explicit = $this->handle(new Request('GET', '/explicit'));
        self::assertSame(202, $explicit->status());
        self::assertSame('explicit', $explicit->content());
    }

    public function testKernelReturns404And405WithAllowHeader(): void
    {
        Route::path('/known')->get(static fn (): string => 'known');
        self::assertSame(404, $this->handle(new Request('GET', '/unknown'))->status());
        $method = $this->handle(new Request('POST', '/known'));
        self::assertSame(405, $method->status());
        self::assertSame('GET', $method->header('Allow'));
    }

    public function testMirroredLegacyRouteRetainsOldMiddlewareCallContract(): void
    {
        $router = new Router($this->routes);
        $middleware = function (array $params, callable $next): mixed {
            $this->trace->events[] = 'legacy.middleware:' . $params[0];
            self::assertNull($next()); // Historical legacy $next() is a no-op.
            return null;
        };
        $router->group(['prefix' => '/old', 'middleware' => [$middleware]], function (Router $router): void {
            $router->add('GET', '/items/{id}', function (string $id): string {
                $this->trace->events[] = 'legacy.handler';
                return 'item:' . $id;
            }, 'old.items.show');
        });

        $response = $this->handle(new Request('GET', '/old/items/42'));
        self::assertSame('item:42', $response->content());
        self::assertSame(['legacy.middleware:42', 'legacy.handler'], $this->trace->events);
        self::assertSame('/old/items/42', $this->routes->url('old.items.show', ['id' => 42]));
    }

    public function testMirroredLegacyCustomNotFoundHandlerKeeps404Status(): void
    {
        $router = new Router($this->routes);
        $router->add('GET', '/known', static fn (): string => 'known');
        $router->setNotFoundHandler(static fn (): string => 'legacy missing');

        $missing = $this->handle(new Request('GET', '/unknown'));
        self::assertSame(404, $missing->status());
        self::assertSame('legacy missing', $missing->content());
        $wrongMethod = $this->handle(new Request('POST', '/known'));
        self::assertSame(405, $wrongMethod->status());
        self::assertSame('GET', $wrongMethod->header('Allow'));
    }

    public function testLegacyNotFoundHandlerCanRenderAnApplicationView(): void
    {
        $views = $this->project->path('Project/Views/Errors');
        if (!is_dir($views)) {
            mkdir($views, 0777, true);
        }
        file_put_contents($views . '/404.squehub.php', '<h1>Custom missing view</h1>');
        file_put_contents($views . '/500.squehub.php', '<h1>Custom server view</h1>');
        View::initViewPaths();
        try {
            $router = new Router($this->routes);
            $router->setNotFoundHandler(static function () {
                return View::render('errors.404');
            });
            $router->setErrorHandler(500, static function () {
                return View::render('errors.500');
            });
            $router->add('GET', '/broken', static function (): never {
                throw new RuntimeException('private failure');
            });

            $missing = $this->handle(new Request('GET', '/unknown'));
            self::assertSame(404, $missing->status());
            self::assertStringContainsString('Custom missing view', $missing->content());
            $server = $this->handle(new Request('GET', '/broken'));
            self::assertSame(500, $server->status());
            self::assertStringContainsString('Custom server view', $server->content());
        } finally {
            unlink($views . '/404.squehub.php');
            unlink($views . '/500.squehub.php');
            View::initViewPaths();
        }
    }

    public function testLegacyAndModernErrorCallbacksKeepOriginalStatusAndHeaders(): void
    {
        $router = new Router($this->routes);
        $router->setErrorHandler(500, static fn (): Response => new Response('custom server page', 200));
        Route::error(403, static fn (): string => 'custom forbidden page');
        Route::error(405, static fn (): Response => new Response('custom method page', 200, ['X-Page' => 'custom']));
        $this->routes->get('/break', static function (): never {
            throw new RuntimeException('private failure');
        });
        $this->routes->get('/forbidden', static function (): never {
            throw new HttpException(403);
        });
        $this->routes->get('/known', static fn (): string => 'known');

        $server = $this->handle(new Request('GET', '/break'));
        self::assertSame(500, $server->status());
        self::assertSame('custom server page', $server->content());
        $forbidden = $this->handle(new Request('GET', '/forbidden'));
        self::assertSame(403, $forbidden->status());
        self::assertSame('custom forbidden page', $forbidden->content());
        $method = $this->handle(new Request('POST', '/known'));
        self::assertSame(405, $method->status());
        self::assertSame('custom method page', $method->content());
        self::assertSame('GET', $method->header('Allow'));
        self::assertSame('custom', $method->header('X-Page'));
    }

    public function testCustomNotFoundResponseKeeps404AndJsonDoesNotRenderHtmlPage(): void
    {
        Route::error(404, static fn (): Response => new Response('custom missing page', 200));

        $html = $this->handle(new Request('GET', '/unknown'));
        self::assertSame(404, $html->status());
        self::assertSame('custom missing page', $html->content());

        $json = $this->handle(new Request('GET', '/unknown', [], [], [], [],
            ['Accept' => 'application/json']));
        self::assertInstanceOf(JsonResponse::class, $json);
        self::assertSame(404, $json->status());
        self::assertSame(['error' => 'Not Found'], json_decode($json->content(), true));
    }

    public function testMirroredLegacyControllerAtMethodAndPackageLookup(): void
    {
        $this->project->write('Project/Packages/PhaseFourPackage/PhaseFourPackage.php',
            '<?php namespace Project\\Packages\\PhaseFourPackage; final class PhaseFourPackage extends \\App\\Plugins\\ServiceProvider {}');
        $packages = new PackageManager(new Application($this->project->path()));
        $packages->apply($packages->planEnable('PhaseFourPackage'));
        $this->app = new Application($this->project->path());
        $this->app->register(HttpServiceProvider::class);
        $this->app->register(RoutingServiceProvider::class);
        $this->app->bootstrap();
        $this->routes = $this->app->container()->make(RouteRegistry::class);

        $router = new Router($this->routes);
        $router->add('GET', '/legacy-controller/{id}', 'CharacterizationController@show');
        $router->add('GET', '/package-controller/{id}', 'PackageProbeController@show');

        self::assertSame('controller:7',
            $this->handle(new Request('GET', '/legacy-controller/7'))->content());
        self::assertSame('package:8',
            $this->handle(new Request('GET', '/package-controller/8'))->content());
    }

    private function handle(Request $request): Response
    {
        return $this->app->container()->make(Kernel::class)->handle($request);
    }
}
