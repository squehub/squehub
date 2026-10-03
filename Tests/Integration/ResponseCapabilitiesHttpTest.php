<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Http\FileResponse;
use App\Http\Request;
use App\Http\Response;
use App\Http\StreamResponse;
use App\Plugins\TestCase;
use Closure;

/** The real Kernel keeps deferred bodies and typed cookies intact. */
final class ResponseCapabilityHeaderMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request)->withHeader('X-Response-Stage', 'middleware');
    }
}

/** Exercises routing, middleware, and the isolated test client's cookie jar. */
final class ResponseCapabilitiesHttpTest extends TestCase
{
    public static int $producerRuns = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$producerRuns = 0;
        $this->testApplication()->write('Project/Files/private.bin', '0123456789');
        $source = var_export($this->testApplication()->path('Project/Files/private.bin'), true);
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Plugins\Route::path('/response/file')->get(static function (\App\Http\Request $request): \App\Http\Response {
    return response()->download(__SOURCE__, request: $request)
        ->withCookie(new \App\Plugins\Cookie('download', 'ready'));
})->through(new \SqueHub\Tests\Integration\ResponseCapabilityHeaderMiddleware());
\App\Plugins\Route::path('/response/stream')->get(static function (): \App\Http\Response {
    return response()->stream(static function (): iterable {
        ++\SqueHub\Tests\Integration\ResponseCapabilitiesHttpTest::$producerRuns;
        yield 'A';
        yield 'B';
    });
})->through(new \SqueHub\Tests\Integration\ResponseCapabilityHeaderMiddleware());
\App\Plugins\Route::path('/cookie/set')->get(static fn (): \App\Http\Response =>
    (new \App\Http\Response('set'))
        ->withCookie(new \App\Plugins\Cookie('first', 'one'))
        ->withCookie(new \App\Plugins\Cookie('second', 'two')));
\App\Plugins\Route::path('/cookie/show')->get(static fn (\App\Http\Request $request): string =>
    $request->cookie('first', '?') . '|' . $request->cookie('second', '?'));
\App\Plugins\Route::path('/cookie/delete')->get(static fn (): \App\Http\Response =>
    (new \App\Http\Response('deleted'))
        ->withCookie(\App\Plugins\Cookie::forget('first')));
PHP);
        $routes = $this->testApplication()->path('Project/Routes/Web.php');
        $code = (string) file_get_contents($routes);
        $this->testApplication()->write('Project/Routes/Web.php', str_replace('__SOURCE__', $source, $code));
    }

    public function testFileRangeAndCookieSurviveRoutingKernelAndMiddleware(): void
    {
        $result = $this->get('/response/file', ['Range' => 'bytes=2-4']);
        $response = $result->response();

        self::assertInstanceOf(FileResponse::class, $response);
        self::assertSame(206, $response->status());
        self::assertSame('bytes 2-4/10', $response->header('Content-Range'));
        self::assertSame('middleware', $response->header('X-Response-Stage'));
        self::assertCount(1, $response->cookies());
        self::assertSame('', $response->content());
        self::assertSame('234', $this->emitted($response));
    }

    public function testStreamIsLazyThroughKernelAndMiddleware(): void
    {
        $result = $this->get('/response/stream');
        $response = $result->response();
        self::assertInstanceOf(StreamResponse::class, $response);
        self::assertSame('middleware', $response->header('X-Response-Stage'));
        self::assertSame(0, self::$producerRuns);
        self::assertSame('', $response->content());
        self::assertSame('AB', $this->emitted($response));
        self::assertSame(1, self::$producerRuns);
    }

    public function testTestClientKeepsDistinctCookiesAndHonorsDeletion(): void
    {
        $this->get('/cookie/set')->assertOk();
        $this->get('/cookie/show')->assertOk()->assertContains('one|two');
        $this->get('/cookie/delete')->assertOk();
        self::assertSame('?|two', $this->get('/cookie/show')->content());
    }

    private function emitted(Response $response): string
    {
        ob_start();
        try {
            $response->send();
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
