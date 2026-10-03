<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Http\ExceptionHandler;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\LegacyRouterDispatcher;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Router;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__, 2) . '/Router.php';
require_once dirname(__DIR__) . '/Fixtures/Controllers/CharacterizationController.php';
require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class LegacyHttpDispatcherTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->app = new Application($this->project->path());
        $this->app->config()->set('app.debug', false);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testExactClosureParametersNamesAndControllerHandler(): void
    {
        $router = new Router();
        $router->add('GET', '/hello', fn (): string => 'hello', 'hello');
        $router->add('GET', '/users/{id}', 'CharacterizationController@show', 'user.show');
        self::assertSame('/users/7', $router->route('user.show', ['id' => 7]));
        self::assertSame('hello', $this->kernel($router)->handle(new Request('GET', '/hello'))->content());
        self::assertSame('controller:7', $this->kernel($router)->handle(new Request('GET', '/users/7'))->content());
    }

    public function testMiddlewareAndNotFoundRemainCompatible(): void
    {
        $router = new Router();
        $calls = [];
        $router->add('GET', '/guarded', static function () use (&$calls): string {
            $calls[] = 'handler';
            return 'allowed';
        }, null, [static function (array $params, callable $next) use (&$calls): mixed {
            $calls[] = 'middleware';
            return $next();
        }]);
        $router->setNotFoundHandler(fn (): string => 'legacy 404');
        self::assertSame('allowed', $this->kernel($router)->handle(new Request('GET', '/guarded'))->content());
        self::assertSame(['middleware', 'handler'], $calls);
        $missing = $this->kernel($router)->handle(new Request('POST', '/guarded'));
        self::assertSame(404, $missing->status());
        self::assertSame('legacy 404', $missing->content());
    }

    public function testEchoReturnArrayNullAndExplicitResponse(): void
    {
        $router = new Router();
        $router->add('GET', '/mixed', static function (): string { echo 'echo:'; return 'return'; });
        $router->add('GET', '/json', static function (): array { echo 'discard'; return ['ok' => true]; });
        $router->add('GET', '/empty', static function (): void {});
        $explicit = new Response('explicit', 201, ['X-Test' => 'yes']);
        $router->add('GET', '/response', static function () use ($explicit): Response {
            echo 'discard';
            return $explicit;
        });
        $kernel = $this->kernel($router);
        self::assertSame('echo:return', $kernel->handle(new Request('GET', '/mixed'))->content());
        $json = $kernel->handle(new Request('GET', '/json'));
        self::assertInstanceOf(JsonResponse::class, $json);
        self::assertSame(['ok' => true], json_decode($json->content(), true));
        self::assertSame('', $kernel->handle(new Request('GET', '/empty'))->content());
        self::assertSame($explicit, $kernel->handle(new Request('GET', '/response')));
    }

    public function testLegacyViewOutputBecomesResponseBody(): void
    {
        $viewDir = BASE_DIR . '/http-view-fixture/';
        mkdir($viewDir);
        file_put_contents($viewDir . 'phase3.squehub.php', 'Hello {{ $name }}');
        $paths = new ReflectionProperty(View::class, 'viewPaths');
        $previous = $paths->getValue();
        $paths->setValue(null, [$viewDir]);
        try {
            $router = new Router();
            $router->add('GET', '/view', static fn () => View::render('phase3', ['name' => 'Ada']));
            self::assertSame('Hello Ada', $this->kernel($router)->handle(new Request('GET', '/view'))->content());
        } finally {
            $paths->setValue(null, $previous);
        }
    }

    public function testEchoBeforeExceptionDoesNotEscapeBuffer(): void
    {
        $router = new Router();
        $router->add('GET', '/error', static function (): void {
            echo 'private echo';
            throw new RuntimeException('private exception');
        });
        $response = $this->kernel($router)->handle(new Request('GET', '/error'));
        self::assertSame(500, $response->status());
        self::assertStringNotContainsString('private echo', $response->content());
        self::assertStringNotContainsString('private exception', $response->content());
    }

    private function kernel(Router $router): Kernel
    {
        return new Kernel(new LegacyRouterDispatcher($router), new ResponseNormalizer(), new ExceptionHandler($this->app));
    }
}
