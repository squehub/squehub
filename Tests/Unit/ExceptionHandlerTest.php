<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Foundation\Application;
use App\Http\Exception\HttpException;
use App\Http\Exception\MethodNotAllowedHttpException;
use App\Http\Exception\NotFoundHttpException;
use App\Http\ExceptionHandler;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Routing\RouteRegistry;
use App\Security\Csrf\CsrfException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class ExceptionHandlerTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->app = new Application($this->project->path());
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testProductionHtmlAndJsonHideInternalDetails(): void
    {
        $this->app->config()->set('app.debug', false);
        $handler = new ExceptionHandler($this->app);
        $exception = new RuntimeException('private database detail');
        $html = $handler->render($exception, new Request());
        self::assertSame(500, $html->status());
        self::assertStringContainsString('Internal Server Error', $html->content());
        self::assertStringNotContainsString('private database detail', $html->content());
        $json = $handler->render($exception, new Request('GET', '/', [], [], [], [], ['Accept' => 'application/json']));
        self::assertInstanceOf(JsonResponse::class, $json);
        self::assertSame(['error' => 'Internal Server Error'], json_decode($json->content(), true));
    }

    public function testDebugIncludesUsefulDetailsButRedactsKnownSecrets(): void
    {
        $this->app->config()->set('app.debug', true);
        $this->app->config()->set('database.password', 'db-secret-123');
        $this->app->config()->set('database.connections.reporting.password', 'reporting-secret-789');
        $this->project->write('Project/Views/Default/Error/500.php',
            (string) file_get_contents(dirname(__DIR__, 2) . '/Project/Views/Default/Error/500.php'));
        $request = new Request('GET', '/', [], [], [], [], ['Authorization' => 'Bearer token-secret-456']);
        $response = (new ExceptionHandler($this->app))->render(
            new RuntimeException('Failed for db-secret-123, reporting-secret-789 and token-secret-456 <script>'), $request
        );
        self::assertSame(500, $response->status());
        self::assertStringContainsString('RuntimeException', $response->content());
        self::assertStringContainsString('[redacted]', $response->content());
        self::assertStringContainsString('&lt;script&gt;', $response->content());
        self::assertStringNotContainsString('db-secret-123', $response->content());
        self::assertStringNotContainsString('reporting-secret-789', $response->content());
        self::assertStringNotContainsString('token-secret-456', $response->content());
        self::assertStringContainsString('Development diagnostics', $response->content());
        self::assertStringContainsString('<pre>', $response->content());
    }

    public function testHttpExceptionsPreserveStatusAndSafeHeaders(): void
    {
        $this->app->config()->set('app.debug', false);
        $handler = new ExceptionHandler($this->app);
        self::assertSame(404, $handler->render(new NotFoundHttpException('secret path'), new Request())->status());
        $method = $handler->render(new MethodNotAllowedHttpException('private', ['Allow' => 'GET, POST']), new Request());
        self::assertSame(405, $method->status());
        self::assertSame('GET, POST', $method->header('Allow'));
        self::assertStringNotContainsString('private', $method->content());
        $this->expectException(InvalidArgumentException::class);
        new HttpException(403, 'bad', ['X-Unsafe' => "a\r\nb"]);
    }

    public function testNotFoundUsesApplicationTemplateWithoutDebugTrace(): void
    {
        $this->app->config()->set('app.debug', true);
        $this->project->write('Project/Views/Default/Error/404.php',
            '<!doctype html><title>Custom missing page</title><h1>Go home</h1>'
            . '<?php if (isset($squehubErrorDebug)) echo "DETAILS"; ?>');
        $handler = new ExceptionHandler($this->app);

        $html = $handler->render(new NotFoundHttpException(), new Request('GET', '/missing'));
        self::assertSame(404, $html->status());
        self::assertSame('no-store', $html->header('Cache-Control'));
        self::assertStringContainsString('Custom missing page', $html->content());
        self::assertStringNotContainsString('DETAILS', $html->content());
        self::assertStringNotContainsString('RouteMatcher.php', $html->content());
        self::assertStringNotContainsString('NotFoundHttpException', $html->content());

        $json = $handler->render(new NotFoundHttpException(),
            new Request('GET', '/missing', [], [], [], [], ['Accept' => 'application/json']));
        self::assertInstanceOf(JsonResponse::class, $json);
        self::assertSame('no-store', $json->header('Cache-Control'));
        self::assertSame(['error' => 'Not Found'], json_decode($json->content(), true));
    }

    public function testFallbackTemplateGetsOnlyRedactedDebugDetails(): void
    {
        $this->project->write('Project/Views/Default/Error/403.php', <<<'PHP'
<?php
echo isset($squehubErrorDebug)
    ? htmlspecialchars((string) $squehubErrorDebug['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    : 'generic';
PHP);
        $handler = new ExceptionHandler($this->app);
        $this->app->config()->set('database.password', 'private-password');
        $request = new Request('GET', '/forbidden');

        $this->app->config()->set('app.debug', true);
        $debug = $handler->render(new HttpException(403, 'bad <tag> private-password'), $request);
        self::assertSame(403, $debug->status());
        self::assertStringContainsString('bad &lt;tag&gt; [redacted]', $debug->content());
        self::assertStringNotContainsString('private-password', $debug->content());

        $quiet = $handler->render(new CsrfException(), $request);
        self::assertSame('generic', $quiet->content());

        $this->app->config()->set('app.debug', false);
        $production = $handler->render(new HttpException(403, 'private-password'), $request);
        self::assertSame('generic', $production->content());
    }

    public function testNotFoundFallsBackWhenCustomTemplateFails(): void
    {
        $this->app->config()->set('app.debug', true);
        $this->project->write('Project/Views/Default/Error/404.php',
            '<?php throw new \\RuntimeException("private template detail");');

        $response = (new ExceptionHandler($this->app))->render(new NotFoundHttpException(), new Request());
        self::assertSame(404, $response->status());
        self::assertStringContainsString('<h1>Not Found</h1>', $response->content());
        self::assertStringNotContainsString('private template detail', $response->content());
        self::assertStringNotContainsString('RouteMatcher.php', $response->content());
    }

    public function testCustomNotFoundHandlerCannotCacheAMissingPage(): void
    {
        $routes = new RouteRegistry();
        $routes->setErrorHandler(404, static fn (): string => '<h1>Missing</h1>');
        $response = (new ExceptionHandler($this->app, $routes))->render(
            new HttpException(404, 'missing', ['cache-control' => 'public, max-age=3600']),
            new Request('GET', '/missing')
        );

        self::assertSame(404, $response->status());
        self::assertSame('<h1>Missing</h1>', $response->content());
        self::assertSame('no-store', $response->header('Cache-Control'));
    }

    public function testProductionUsesStatusTemplatesAndCustomHandlerTakesPrecedence(): void
    {
        $this->app->config()->set('app.debug', false);
        $this->project->write('Project/Views/Default/Error/500.php', '<h1>Application 500 page</h1>');
        $this->project->write('Project/Views/Default/Error/403.php', '<h1>Application 403 page</h1>');
        $routes = new RouteRegistry();
        $handler = new ExceptionHandler($this->app, $routes);

        $server = $handler->render(new RuntimeException('private database detail'), new Request());
        self::assertSame(500, $server->status());
        self::assertStringContainsString('Application 500 page', $server->content());
        self::assertStringNotContainsString('private database detail', $server->content());
        $forbidden = $handler->render(new HttpException(403), new Request());
        self::assertSame(403, $forbidden->status());
        self::assertStringContainsString('Application 403 page', $forbidden->content());

        $routes->setErrorHandler(500, static fn (): string => '<h1>Chosen 500 page</h1>');
        $chosen = $handler->render(new RuntimeException('private detail'), new Request());
        self::assertSame(500, $chosen->status());
        self::assertStringContainsString('Chosen 500 page', $chosen->content());
        self::assertStringNotContainsString('Application 500 page', $chosen->content());

        $json = $handler->render(new RuntimeException('private detail'),
            new Request('GET', '/', [], [], [], [], ['Accept' => 'application/json']));
        self::assertInstanceOf(JsonResponse::class, $json);
        self::assertSame(['error' => 'Internal Server Error'], json_decode($json->content(), true));
    }

    public function testCustomForbiddenPageAlsoHandlesCsrfWithoutChangingJsonFailures(): void
    {
        $routes = new RouteRegistry();
        $routes->setErrorHandler(403, static fn (): string => '<h1>Request blocked</h1>');
        $handler = new ExceptionHandler($this->app, $routes);

        $html = $handler->render(new CsrfException(), new Request('POST', '/form'));
        self::assertSame(403, $html->status());
        self::assertSame('<h1>Request blocked</h1>', $html->content());

        $json = $handler->render(new CsrfException(),
            new Request('POST', '/form', [], [], [], [], ['Accept' => 'application/json']));
        self::assertInstanceOf(JsonResponse::class, $json);
        self::assertSame(['message' => 'CSRF verification failed.'], json_decode($json->content(), true));
    }
}
