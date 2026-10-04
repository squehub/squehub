<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Router;

require_once dirname(__DIR__, 2) . '/Router.php';
require_once dirname(__DIR__) . '/Fixtures/Controllers/CharacterizationController.php';

final class RouterTest extends TestCase
{
    public function testExactClosureAndNamedRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/hello', fn (): string => 'hello', 'hello');
        self::assertSame('/hello', $router->route('hello'));
        self::assertSame('#', $router->route('missing')); // Current fallback.
        self::assertSame('hello', $this->dispatch($router, 'GET', '/hello/'));
    }

    public function testParametersAndControllerAtMethod(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{id}', 'CharacterizationController@show', 'user.show');
        self::assertSame('/users/42', $router->route('user.show', ['id' => 42]));
        self::assertSame('controller:42', $this->dispatch($router, 'GET', '/users/42'));
    }

    public function testGroupPrefixAndMiddlewareBehavior(): void
    {
        $router = new Router();
        $calls = [];
        $middleware = static function (array $params, callable $next) use (&$calls): mixed {
            $calls[] = 'middleware';
            $result = $next();
            $calls[] = 'next:' . ($result === null ? 'null' : 'other');
            return $result;
        };
        $router->group(['prefix' => '/api', 'middleware' => [$middleware]], static function (Router $r) use (&$calls): void {
            $r->add('GET', '/ping', static function () use (&$calls): string {
                $calls[] = 'handler';
                return 'pong';
            });
        });
        self::assertSame('pong', $this->dispatch($router, 'GET', '/api/ping'));
        // Legacy $next() is a no-op. The router calls the handler afterward.
        self::assertSame(['middleware', 'next:null', 'handler'], $calls);
    }

    public function testNestedGroupsCurrentlyReplaceOuterPrefix(): void
    {
        $router = new Router();
        $router->group(['prefix' => '/api'], static function (Router $r): void {
            $r->group(['prefix' => '/admin'], static function (Router $nested): void {
                $nested->add('GET', '/users', fn (): string => 'users');
            });
        });
        // The outer prefix is overwritten by array_merge in the current router.
        self::assertSame('users', $this->dispatch($router, 'GET', '/admin/users'));
    }

    public function testMiddlewareMayShortCircuitBeforeHandler(): void
    {
        $router = new Router();
        $router->add('GET', '/secure', fn (): string => 'handler', null, [fn (): string => 'blocked']);
        self::assertSame('blocked', $this->dispatch($router, 'GET', '/secure'));
    }

    public function testCustomNotFoundHandler(): void
    {
        $router = new Router();
        $router->add('GET', '/known', fn (): string => 'known');
        $router->setNotFoundHandler(fn (): string => 'not-found');
        self::assertSame('not-found', $this->dispatch($router, 'GET', '/unknown'));
    }

    public function testDefaultNotFoundViewRendersFromProjectOnEachRequest(): void
    {
        $viewDirectory = BASE_DIR . '/Project/Views/Default/Error';
        if (!is_dir($viewDirectory)) {
            mkdir($viewDirectory, 0777, true);
        }
        copy(dirname(__DIR__, 2) . '/Project/Views/Default/Error/404.php', $viewDirectory . '/404.php');

        $router = new Router();
        $first = $this->dispatch($router, 'GET', '/unknown');
        self::assertStringContainsString('<title>404 - Page Not Found</title>', $first);
        self::assertStringContainsString('href="/assets/default/favicon/squehub-icon.png"', $first);
        self::assertStringContainsString('<title>404 - Page Not Found</title>',
            $this->dispatch($router, 'GET', '/another-missing-path'));
    }

    private function dispatch(Router $router, string $method, string $uri): string
    {
        ob_start();
        try {
            $router->dispatch($method, $uri);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
