<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Http\Exception\MethodNotAllowedHttpException;
use App\Http\Exception\NotFoundHttpException;
use App\Http\Request;
use App\Routing\MatchedRoute;
use App\Routing\MiddlewareRegistry;
use App\Routing\Route;
use App\Routing\RouteMatcher;
use App\Routing\RouteRegistry;
use PHPUnit\Framework\TestCase;
use Throwable;

final class RoutingTest extends TestCase
{
    protected function tearDown(): void
    {
        Route::setResolver(null);
    }

    public function testCoreRouteNameUsesTheSameModernRegistry(): void
    {
        $routes = new RouteRegistry();
        Route::setResolver(static fn (): RouteRegistry => $routes);

        self::assertSame(\App\Core\Route::class,
            (new \ReflectionClass(\App\Core\Route::class))->getName());
        \App\Core\Route::path('/core')->get(static fn (): string => 'Welcome');

        self::assertSame($routes, \App\Core\Route::registry());
        self::assertSame('/core', (new RouteMatcher())
            ->match($routes, new Request('GET', '/core'))->route->uri());
    }

    public function testFacadeDelegatesToOneRegistryAndSupportsHttpMethods(): void
    {
        $routes = new RouteRegistry();
        Route::setResolver(static fn (): RouteRegistry => $routes);

        $path = Route::path('/verbs');
        self::assertSame([], $routes->all());
        $path->get(static fn (): string => 'get');
        $path->post(static fn (): string => 'post');
        $path->put(static fn (): string => 'put');
        $path->patch(static fn (): string => 'patch');
        $path->delete(static fn (): string => 'delete');
        $path->options(static fn (): string => 'options');

        self::assertSame($routes, Route::registry());
        self::assertCount(6, $routes->all());
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            self::assertInstanceOf(MatchedRoute::class, (new RouteMatcher())->match($routes, new Request($method, '/verbs')));
        }
    }

    public function testPublicRouteApiRequiresBootstrapAndResourceUsesTheSharedRegistry(): void
    {
        $this->assertRoutingFailure(static fn () => Route::path('/users'), 'bootstrap');

        $routes = new RouteRegistry();
        Route::setResolver(static fn (): RouteRegistry => $routes);
        Route::resource('/users', RoutingResourceController::class);

        self::assertSame('/users/9', $routes->url('users.show', ['id' => 9]));
        self::assertCount(5, $routes->all());
    }

    public function testRequiredParametersMatchOneSegmentAndPreserveOrder(): void
    {
        $routes = new RouteRegistry();
        $routes->get('/users/{id}', static fn (): string => 'user');
        $routes->get('/posts/{post}/comments/{comment}', static fn (): string => 'comment');
        $matcher = new RouteMatcher();

        self::assertSame(['id' => '15'], $matcher->match($routes, new Request('GET', '/users/15'))->parameters);
        self::assertSame(['post' => '8', 'comment' => '3'],
            $matcher->match($routes, new Request('GET', '/posts/8/comments/3'))->parameters);
        self::assertSame(['id' => 'Ada Lovelace'],
            $matcher->match($routes, new Request('GET', '/users/Ada%20Lovelace'))->parameters);
        $this->assertNotFound(static fn () => $matcher->match($routes, new Request('GET', '/users/15/posts')));
        $this->assertNotFound(static fn () => $matcher->match($routes, new Request('GET', '/users/')));
        $this->assertNotFound(static fn () => $matcher->match($routes, new Request('GET', '/users/a%2Fb')));
    }

    public function testNamedRoutesGenerateEncodedPathsAndRejectMissingOrUnknownNames(): void
    {
        $routes = new RouteRegistry();
        $routes->get('/dashboard', static fn (): string => 'dashboard')->named('dashboard');
        $routes->get('/users/{id}', static fn (): string => 'user')->named('users.show');

        self::assertTrue($routes->hasName('dashboard'));
        self::assertFalse($routes->hasName('missing'));
        self::assertSame('/dashboard', $routes->url('dashboard'));
        self::assertSame('/users/10', $routes->url('users.show', ['id' => 10]));
        self::assertSame('/users/Ada%20Lovelace', $routes->url('users.show', ['id' => 'Ada Lovelace']));

        $this->assertRoutingFailure(static fn () => $routes->url('users.show'), 'id');
        $this->assertRoutingFailure(static fn () => $routes->url('missing'), 'missing');
        $this->assertRoutingFailure(static fn () => $routes->url('users.show', ['id' => 'a/b']), 'unsafe');
        $this->assertRoutingFailure(static fn () => $routes->url('users.show', ['id' => "a\r\nb"]), 'unsafe');
        $this->assertRoutingFailure(static fn () => $routes->url('users.show', ['id' => '..']), 'unsafe');
    }

    public function testModernRegistrationRejectsDuplicateMethodUriAndName(): void
    {
        $routes = new RouteRegistry();
        $routes->get('/users', static fn (): string => 'first')->named('users.index');
        $this->assertRoutingFailure(
            static fn () => $routes->get('/users', static fn (): string => 'second'),
            '/users'
        );
        $this->assertRoutingFailure(
            static fn () => $routes->get('/other', static fn (): string => 'other')->named('users.index'),
            'users.index'
        );
    }

    public function testPackageContextRejectsLegacyRouteAndNameCollisions(): void
    {
        $routes = new RouteRegistry();
        $routes->addLegacy('GET', '/shared', static fn (): string => 'application', 'shared', []);

        $routes->beginPackageContext('Weather');
        try {
            $this->assertRoutingFailure(
                static fn () => $routes->addLegacy('GET', '/shared', static fn (): string => 'package', null, []),
                'GET /shared'
            );
            $this->assertRoutingFailure(
                static fn () => $routes->addLegacy('GET', '/other', static fn (): string => 'package', 'shared', []),
                'shared'
            );
            self::assertCount(1, $routes->all());
        } finally {
            $routes->endPackageContext();
        }

        // Existing application legacy semantics remain available outside a Package.
        $routes->addLegacy('GET', '/shared', static fn (): string => 'replacement', 'shared', []);
        self::assertCount(1, $routes->all());
    }

    public function testPackageMiddlewareContextCannotReplaceApplicationAlias(): void
    {
        $middleware = new MiddlewareRegistry();
        $middleware->alias('auth', RoutingResourceController::class);
        $middleware->beginPackageContext('enabled Packages');
        try {
            $this->assertRoutingFailure(
                static fn () => $middleware->alias('auth', \stdClass::class),
                'auth'
            );
            $middleware->alias('weather', \stdClass::class);
        } finally {
            $middleware->endPackageContext();
        }

        self::assertSame(RoutingResourceController::class, $middleware->resolve('auth'));
        self::assertSame(\stdClass::class, $middleware->resolve('weather'));
        // Ordinary application registration keeps its existing replacement API.
        $middleware->alias('auth', \stdClass::class);
        self::assertSame(\stdClass::class, $middleware->resolve('auth'));
        self::assertFalse((new MiddlewareRegistry())->has('auth'));
    }

    public function testNestedGroupPrefixesComposeAndNamedPathsUseFinalUri(): void
    {
        $routes = new RouteRegistry();
        Route::setResolver(static fn (): RouteRegistry => $routes);
        Route::group()->prefix('/admin')->through('auth')->routes(static function (): void {
            Route::group()->prefix('/users')->through('admin')->routes(static function (): void {
                Route::path('/active')->get(static fn (): string => 'active')
                    ->named('admin.users.active')->through('throttle');
            });
        });

        self::assertSame('/admin/users/active', $routes->url('admin.users.active'));
        $match = (new RouteMatcher())->match($routes, new Request('GET', '/admin/users/active'));
        self::assertSame(['auth', 'admin', 'throttle'], $match->route->middlewares());
        $this->assertNotFound(static fn () => (new RouteMatcher())->match($routes,
            new Request('GET', '/users/active')));
    }

    public function testRegistryGroupCallbackCanRegisterRoutesAndRestoresContext(): void
    {
        $routes = new RouteRegistry();
        $routes->group(['prefix' => '/api', 'through' => 'auth'], static function (RouteRegistry $routes): void {
            $routes->get('/ping', static fn (): string => 'pong')->named('api.ping');
        });
        $routes->get('/outside', static fn (): string => 'outside')->named('outside');
        self::assertSame('/api/ping', $routes->url('api.ping'));
        self::assertSame(['auth'], $routes->all()[0]->middlewares());
        self::assertSame('/outside', $routes->url('outside'));
        self::assertSame([], $routes->all()[1]->middlewares());
    }

    public function testResourceRegistersOnlySixApiRoutesWithFiveNames(): void
    {
        $routes = new RouteRegistry();
        $routes->resource('/users', RoutingResourceController::class);

        self::assertCount(5, $routes->all()); // PUT and PATCH share one definition.
        self::assertSame([
            [['GET'], '/users', [RoutingResourceController::class, 'index'], 'users.index'],
            [['GET'], '/users/{id}', [RoutingResourceController::class, 'show'], 'users.show'],
            [['POST'], '/users', [RoutingResourceController::class, 'store'], 'users.store'],
            [['PUT', 'PATCH'], '/users/{id}', [RoutingResourceController::class, 'update'], 'users.update'],
            [['DELETE'], '/users/{id}', [RoutingResourceController::class, 'destroy'], 'users.destroy'],
        ], array_map(static fn ($route): array => [
            $route->methods(), $route->uri(), $route->action(), $route->nameValue(),
        ], $routes->all()));
        foreach (['index', 'show', 'store', 'update', 'destroy'] as $action) {
            self::assertTrue($routes->hasName('users.' . $action));
        }
        self::assertSame('/users', $routes->url('users.index'));
        self::assertSame('/users/10', $routes->url('users.show', ['id' => 10]));
        self::assertSame('/users/10', $routes->url('users.update', ['id' => 10]));

        $matcher = new RouteMatcher();
        foreach ([
            ['GET', '/users'], ['GET', '/users/10'], ['POST', '/users'],
            ['PUT', '/users/10'], ['PATCH', '/users/10'], ['DELETE', '/users/10'],
        ] as [$method, $uri]) {
            self::assertInstanceOf(MatchedRoute::class, $matcher->match($routes, new Request($method, $uri)));
        }
    }

    public function testMatcherDistinguishesNotFoundFromMethodNotAllowedAndListsMethods(): void
    {
        $routes = new RouteRegistry();
        $routes->get('/users', static fn (): string => 'index');
        $routes->post('/users', static fn (): string => 'store');
        $matcher = new RouteMatcher();

        $this->assertNotFound(static fn () => $matcher->match($routes, new Request('DELETE', '/unknown')));
        try {
            $matcher->match($routes, new Request('DELETE', '/users'));
            self::fail('Expected a method-not-allowed exception.');
        } catch (MethodNotAllowedHttpException $exception) {
            self::assertSame(405, $exception->status());
            self::assertSame('GET, POST', $exception->headers()['Allow'] ?? null);
        }
    }

    public function testHeadUsesGetFallbackButExplicitHeadTakesPrecedence(): void
    {
        $routes = new RouteRegistry();
        $get = $routes->get('/ping', static fn (): string => 'get');
        $matcher = new RouteMatcher();
        self::assertSame($get, $matcher->match($routes, new Request('HEAD', '/ping'))->route);

        $head = $routes->add('HEAD', '/ping', static fn (): string => 'head');
        self::assertSame($head, $matcher->match($routes, new Request('HEAD', '/ping'))->route);
    }

    public function testExactPathPrecedesEarlierParameterizedPath(): void
    {
        $routes = new RouteRegistry();
        $routes->get('/users/{id}', static fn (): string => 'parameter');
        $exact = $routes->get('/users/new', static fn (): string => 'exact');

        self::assertSame($exact, (new RouteMatcher())->match($routes, new Request('GET', '/users/new'))->route);
    }

    public function testInvalidActionsAndParameterPatternsFailAtRegistration(): void
    {
        $routes = new RouteRegistry();
        $this->assertRoutingFailure(static fn () => $routes->get('/bad', []), 'action');
        $this->assertRoutingFailure(static fn () => $routes->get('/users/{id?}/edit', static fn (): string => 'bad'),
            'trailing');
        $this->assertRoutingFailure(static fn () => $routes->get('/users/{id}/{id}', static fn (): string => 'bad'),
            'parameter');
    }

    private function assertNotFound(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected a not-found exception.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(404, $exception->status());
        }
    }

    private function assertRoutingFailure(callable $operation, string $expectedMessagePart): void
    {
        $caught = null;
        try {
            $operation();
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        self::assertInstanceOf(Throwable::class, $caught);
        self::assertStringContainsString($expectedMessagePart, $caught->getMessage());
    }
}

final class RoutingResourceController
{
    public function index(): void {}
    public function show(): void {}
    public function store(): void {}
    public function update(): void {}
    public function destroy(): void {}
}
