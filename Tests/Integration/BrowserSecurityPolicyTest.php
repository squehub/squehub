<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Foundation\Application;
use App\Http\BrowserSecurityPolicy;
use App\Http\FileResponse;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\RedirectResponse;
use App\Http\Request;
use App\Http\Response;
use App\Http\StreamResponse;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises browser headers at the same Kernel boundary as ordinary and error responses. */
final class BrowserSecurityPolicyTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->projects as $project) $project->remove();
        } finally {
            \App\Routing\Route::setResolver(null);
            parent::tearDown();
        }
    }

    public function testDisabledDefaultAndApplicationIsolation(): void
    {
        $plain = $this->application();
        $secured = $this->application($this->browser());
        foreach ([$plain, $secured] as $app) {
            $app->container()->make(RouteRegistry::class)->get('/page',
                static fn (): Response => self::html('page'));
        }
        $sameRequest = $this->request();
        $default = $this->handle($plain, $sameRequest);
        self::assertNull($default->header('Content-Security-Policy'));
        self::assertNull($default->header('Referrer-Policy'));
        self::assertNull($default->header('X-Content-Type-Options'));

        $enabled = $this->handle($secured, $sameRequest);
        self::assertSame("default-src 'self'; frame-ancestors 'none'",
            $enabled->header('Content-Security-Policy'));
        self::assertSame('DENY', $enabled->header('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $enabled->header('Referrer-Policy'));
        self::assertSame('nosniff', $enabled->header('X-Content-Type-Options'));
        self::assertNull($enabled->header('Strict-Transport-Security'));
        self::assertNull($this->handle($plain, $sameRequest)->header('Content-Security-Policy'));
    }

    public function testHtmlErrorsRedirectsAndEndpointHeaderCollisions(): void
    {
        $app = $this->application($this->browser());
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/page', static fn (): Response => self::html('page', [
            'Content-Security-Policy' => "default-src *",
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'origin',
        ]));
        // OAuthProvider::redirect() supplies these endpoint-specific headers.
        $routes->get('/oauth-redirect', static fn (): RedirectResponse => new RedirectResponse(
            'https://id.example.test/authorize', 302,
            ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']));
        $routes->get('/go', static fn (): RedirectResponse => new RedirectResponse('/page'));
        $routes->get('/broken', static function (): void { throw new RuntimeException('internal marker'); });

        $page = $this->handle($app, $this->request('/page'));
        self::assertSame("default-src 'self'; frame-ancestors 'none'",
            $page->header('Content-Security-Policy'));
        self::assertSame('DENY', $page->header('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $page->header('Referrer-Policy'));
        $oauthRedirect = $this->handle($app, $this->request('/oauth-redirect'));
        self::assertSame(302, $oauthRedirect->status());
        self::assertSame('no-referrer', $oauthRedirect->header('Referrer-Policy'));
        self::assertSame('no-store', $oauthRedirect->header('Cache-Control'));

        $redirect = $this->handle($app, $this->request('/go'));
        self::assertSame(302, $redirect->status());
        self::assertSame('/page', $redirect->header('Location'));
        self::assertNull($redirect->header('Content-Security-Policy'));
        self::assertSame('strict-origin-when-cross-origin', $redirect->header('Referrer-Policy'));
        self::assertSame('nosniff', $redirect->header('X-Content-Type-Options'));
        self::assertSame('max-age=31536000; includeSubDomains',
            $this->handle($app, $this->request('/go', https: true))
                ->header('Strict-Transport-Security'));

        foreach (['/missing' => 404, '/broken' => 500] as $path => $status) {
            $error = $this->handle($app, $this->request($path));
            self::assertSame($status, $error->status());
            self::assertSame("default-src 'self'; frame-ancestors 'none'",
                $error->header('Content-Security-Policy'));
            self::assertSame('DENY', $error->header('X-Frame-Options'));
            self::assertStringNotContainsString('internal marker', $error->content());
        }
    }

    public function testHstsUsesOnlyDirectOrTrustedEffectiveHttpsAndResetsBetweenApps(): void
    {
        $trusted = $this->application($this->browser(), [
            'proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded',
        ]);
        $direct = $this->application($this->browser());
        foreach ([$trusted, $direct] as $app) {
            $app->container()->make(RouteRegistry::class)->get('/page',
                static fn (): Response => self::html('page', [
                    'Strict-Transport-Security' => 'max-age=999999',
                ]));
        }
        $forwarded = $this->request('/page', '10.0.0.8',
            ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https']);
        $expected = 'max-age=31536000; includeSubDomains';
        self::assertSame($expected,
            $this->handle($trusted, $forwarded)->header('Strict-Transport-Security'));
        self::assertNull($this->handle($direct, $forwarded)->header('Strict-Transport-Security'));
        self::assertSame($expected,
            $this->handle($direct, $this->request('/page', '198.51.100.8', [], true))
                ->header('Strict-Transport-Security'));
        self::assertNull($this->handle($trusted, $this->request('/page', '198.51.100.8',
            ['X-Forwarded-Proto' => 'https']))->header('Strict-Transport-Security'));
        self::assertNull($this->handle($trusted, $this->request('/page'))->header('Strict-Transport-Security'));
    }

    public function testApiDownloadRangeAndStreamRemainNonHtml(): void
    {
        $app = $this->application($this->browser(), [], true);
        $project = $this->projects[count($this->projects) - 1];
        $project->write('sample.bin', 'abcdef');
        $file = $project->path('sample.bin');
        $runs = 0;
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/api/data', static fn (): JsonResponse => new JsonResponse(['ok' => true]));
        $routes->get('/download', static fn (Request $request): FileResponse =>
            new FileResponse($file, request: $request));
        $routes->get('/html-download', static fn (): FileResponse =>
            new FileResponse($file, contentType: 'text/html', download: true));
        $routes->get('/stream', static function () use (&$runs): StreamResponse {
            return new StreamResponse(static function () use (&$runs): iterable {
                $runs++;
                yield 'binary';
            }, headers: ['Content-Type' => 'application/octet-stream']);
        });

        $api = $this->handle($app, $this->request('/api/data'));
        self::assertSame(200, $api->status());
        self::assertNull($api->header('Content-Security-Policy'));
        self::assertNull($api->header('X-Frame-Options'));
        self::assertSame('nosniff', $api->header('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $api->header('Referrer-Policy'));

        $range = $this->handle($app, $this->request('/download', headers: ['Range' => 'bytes=1-3']));
        self::assertInstanceOf(FileResponse::class, $range);
        self::assertSame(206, $range->status());
        self::assertSame('bytes 1-3/6', $range->header('Content-Range'));
        self::assertSame('3', $range->header('Content-Length'));
        self::assertNull($range->header('Content-Security-Policy'));
        $htmlDownload = $this->handle($app, $this->request('/html-download'));
        self::assertInstanceOf(FileResponse::class, $htmlDownload);
        self::assertNull($htmlDownload->header('Content-Security-Policy'));
        self::assertNull($htmlDownload->header('X-Frame-Options'));

        $stream = $this->handle($app, $this->request('/stream'));
        self::assertInstanceOf(StreamResponse::class, $stream);
        self::assertSame(0, $runs);
        self::assertNull($stream->header('Content-Security-Policy'));
        self::assertSame('application/octet-stream', $stream->header('Content-Type'));
    }

    public function testCspAndFrameValidationRejectsInjectionAndConflicts(): void
    {
        $invalid = [
            ['csp' => ['directives' => ['default-src' => ["'self'\r\nX-Evil: yes"]]]],
            ['csp' => ['directives' => ['default-src; x' => ["'self'"]]]],
            ['csp' => ['directives' => ['default-src' => ["'self'", "'none'"]]]],
            ['csp' => ['directives' => ['script-src' => ["'nonce-static'"]]]],
            ['csp' => ['directives' => ['frame-ancestors' => ["'self'"]]], 'frame_options' => 'DENY'],
            ['referrer_policy' => "origin\r\nX-Evil: yes"],
            ['hsts' => ['enabled' => true, 'max_age' => -1]],
            ['hsts' => ['enabled' => true, 'max_age' => 100, 'preload' => true]],
            ['enabled' => null],
            ['csp' => null],
            ['hsts' => ['max_age' => null]],
        ];
        foreach ($invalid as $case) {
            try {
                new BrowserSecurityPolicy(new Repository(['security' => ['browser' =>
                    array_replace_recursive($this->browser(), $case)]]));
                self::fail('Invalid browser policy was accepted: ' . var_export($case, true));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->application(array_replace_recursive($this->browser(),
            ['referrer_policy' => "origin\nInjected: yes"]));
    }

    public function testOptionalReferrerAndFrameControlsCanBeDisabled(): void
    {
        $browser = $this->browser();
        $browser['referrer_policy'] = null;
        $browser['frame_options'] = null;
        $browser['csp'] = ['directives' => ['default-src' => ["'self'"]]];
        $app = $this->application($browser);
        $app->container()->make(RouteRegistry::class)->get('/page',
            static fn (): Response => self::html('page'));
        $response = $this->handle($app, $this->request('/page'));
        self::assertNull($response->header('Referrer-Policy'));
        self::assertNull($response->header('X-Frame-Options'));
        self::assertSame("default-src 'self'", $response->header('Content-Security-Policy'));
    }

    /** @return array<string,mixed> */
    private function browser(): array
    {
        return [
            'enabled' => true,
            'csp' => ['directives' => ['default-src' => ["'self'"],
                'frame-ancestors' => ["'none'"]]],
            'hsts' => ['enabled' => true, 'max_age' => 31536000,
                'include_subdomains' => true, 'preload' => false],
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'frame_options' => 'DENY',
            'nosniff' => true,
        ];
    }

    /** @param array<string,mixed>|null $browser @param array<string,mixed> $proxy */
    private function application(?array $browser = null, array $proxy = [], bool $api = false): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $project->write('Config/App.php', '<?php return ' . var_export(
            ['env' => 'testing', 'debug' => false], true) . ';');
        if ($browser !== null) {
            $project->write('Config/Security.php', '<?php return ' . var_export(
                ['browser' => $browser], true) . ';');
        }
        if ($proxy !== []) {
            $project->write('Config/TrustedProxies.php', '<?php return ' . var_export($proxy, true) . ';');
        }
        if ($api) {
            $project->write('Config/Api.php', '<?php return ' . var_export(
                ['enabled' => true, 'paths' => ['/api']], true) . ';');
        }
        $app = new Application($project->path());
        foreach ([HttpServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        return $app;
    }

    /** @param array<string,string> $headers */
    private function request(string $uri = '/page', string $peer = '198.51.100.8',
        array $headers = [], bool $https = false): Request
    {
        return new Request('GET', $uri, headers: $headers, server: [
            'REMOTE_ADDR' => $peer, 'HTTP_HOST' => 'app.example.test',
            'SERVER_PORT' => $https ? '443' : '80', 'HTTPS' => $https ? 'on' : 'off',
        ]);
    }

    /** @param array<string,string> $headers */
    private static function html(string $body, array $headers = []): Response
    {
        return new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8', ...$headers]);
    }

    private function handle(Application $app, Request $request): Response
    {
        return $app->container()->make(Kernel::class)->handle($request);
    }
}
