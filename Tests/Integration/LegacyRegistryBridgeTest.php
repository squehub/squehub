<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Routing\Route;
use App\Routing\RouteRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Router;

require_once dirname(__DIR__, 2) . '/Router.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

final class LegacyRegistryBridgeTest extends TestCase
{
    public function testLegacyRegistrationMirrorsNamedRoutesIntoApplicationRegistry(): void
    {
        $registry = new RouteRegistry();
        $router = new Router($registry);

        $router->add(['GET', 'POST'], '/users/{id}', fn (string $id): string => $id, 'users.show');

        self::assertTrue($registry->hasName('users.show'));
        self::assertSame('/users/42', $registry->url('users.show', ['id' => 42]));
        self::assertSame('/users/42', $router->route('users.show', ['id' => 42]));
        self::assertSame('42', $this->dispatch($router, 'GET', '/users/42'));
        self::assertSame('42', $this->dispatch($router, 'POST', '/users/42'));
    }

    public function testLegacyGroupMetadataIsAppliedBeforeMirroring(): void
    {
        $registry = new RouteRegistry();
        $router = new Router($registry);

        $router->group(['prefix' => '/api'], static function (Router $outer): void {
            $outer->group(['prefix' => '/admin'], static function (Router $inner): void {
                $inner->add('GET', '/users', fn (): string => 'users', 'admin.users');
            });
        });

        // Legacy inner prefixes replace outer prefixes; v2 Route groups compose.
        self::assertSame('/admin/users', $registry->url('admin.users'));
        self::assertSame('users', $this->dispatch($router, 'GET', '/admin/users'));
    }

    public function testLegacyDuplicateNameAndMethodUriUseLatestRegistration(): void
    {
        $registry = new RouteRegistry();
        $router = new Router($registry);
        $router->add('GET', '/first', fn (): string => 'first', 'current');
        $router->add('GET', '/second', fn (): string => 'second', 'current');
        $router->add('GET', '/second', fn (): string => 'replacement', 'current');

        self::assertSame('/second', $registry->url('current'));
        self::assertSame('replacement', $this->dispatch($router, 'GET', '/second'));
        self::assertCount(2, $registry->all());
        $action = $registry->all()[1]->action();
        self::assertSame('replacement', $action());
        self::assertNull($registry->all()[0]->nameValue());
    }

    public function testRouteHelperPrefersActiveRegistryAndKeepsStandaloneFallback(): void
    {
        $previousRegistry = Route::registry();
        $hadRouter = array_key_exists('router', $GLOBALS);
        $previousRouter = $GLOBALS['router'] ?? null;
        $router = new Router();
        $router->add('GET', '/legacy', fn (): string => 'legacy', 'legacy');
        $GLOBALS['router'] = $router;

        try {
            Route::setResolver(null);
            self::assertSame('/legacy', route('legacy'));
            self::assertSame('#', route('missing'));

            $registry = new RouteRegistry();
            $registry->get('/modern/{id}', fn (): string => 'modern')->named('modern.show');
            Route::setResolver(static fn (): RouteRegistry => $registry);
            self::assertSame('/modern/7', route('modern.show', ['id' => 7]));
            $this->expectException(InvalidArgumentException::class);
            route('legacy');
        } finally {
            Route::setResolver($previousRegistry === null ? null : static fn (): RouteRegistry => $previousRegistry);
            if ($hadRouter) {
                $GLOBALS['router'] = $previousRouter;
            } else {
                unset($GLOBALS['router']);
            }
        }
    }

    private function dispatch(Router $router, string $method, string $path): string
    {
        ob_start();
        try {
            $router->dispatch($method, $path);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
