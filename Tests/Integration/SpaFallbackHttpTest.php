<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Routing\Route;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Exercises the opt-in SPA shell through the real mounted Kernel and Router. */
final class SpaFallbackHttpTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];
    private Application $app;
    private Kernel $kernel;

    protected function setUp(): void
    {
        [$this->app, $this->kernel] = $this->application();
    }

    protected function tearDown(): void
    {
        Route::setResolver(null);
        Csrf::setResolver(null);
        Session::setResolver(null);
        foreach ($this->projects as $project) $project->remove();
    }

    /** @return array{Application,Kernel} */
    private function application(string $mount = ''): array
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $project->write('Config/App.php', '<?php return ["env"=>"testing","debug"=>false];');
        $project->write('Config/Http.php', '<?php return ["base_path"=>' . var_export($mount, true) . '];');
        $project->write('Config/Api.php', '<?php return ["enabled"=>true,"paths"=>["/api","/rpc"]];');
        $project->write('Config/Session.php', '<?php return ["driver"=>"array"];');
        $project->write('Config/Csrf.php', '<?php return ["enabled"=>true,"field"=>"_csrf","header"=>"X-CSRF-Token","except"=>[]];');
        $project->write('Config/Frontend.php', '<?php return ["spa"=>["enabled"=>false,"prefix"=>"/","view"=>null,"except"=>[]]];');
        $project->write('Project/Views/Frontend/App.squehub.php',
            '<!doctype html><html><head><meta name="csrf-token" content="{{ csrf_token() }}"></head>'
            . '<body><div id="frontend-app">SPA</div></body></html>');
        $app = new Application($project->path());
        foreach ([SessionServiceProvider::class, CsrfServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        return [$app, $app->container()->make(Kernel::class)];
    }

    private function enable(string $prefix = '/', array $except = []): void
    {
        $this->app->config()->set('frontend.spa', [
            'enabled' => true, 'prefix' => $prefix,
            'view' => 'Frontend.App', 'except' => $except,
        ]);
    }

    private static function html(string $method, string $path): Request
    {
        return new Request($method, $path, headers: ['Accept' => 'text/html']);
    }

    public function testDisabledSpaKeepsOrdinaryNotFound(): void
    {
        self::assertSame(404, $this->kernel->handle(self::html('GET', '/dashboard'))->status());
    }

    public function testEnabledSpaRendersDynamicPrivateShellForGetAndHead(): void
    {
        $this->enable();
        $get = $this->kernel->handle(self::html('GET', '/dashboard'));
        self::assertSame(200, $get->status());
        self::assertSame('private, no-store', $get->header('Cache-Control'));
        self::assertSame('Accept', $get->header('Vary'));
        self::assertSame('text/html; charset=UTF-8', $get->header('Content-Type'));
        self::assertStringContainsString('id="frontend-app"', $get->content());
        self::assertMatchesRegularExpression('/content="[0-9a-f]{64}"/', $get->content());

        $head = $this->kernel->handle(self::html('HEAD', '/dashboard'));
        self::assertSame(200, $head->status());
        ob_start();
        $head->send(true);
        self::assertSame('', ob_get_clean());
    }

    public function testOrdinaryRoutesMethodErrorsAndGenericFallbacksKeepPrecedence(): void
    {
        $this->enable();
        Route::path('/dashboard')->get(static fn (): string => 'normal');
        Route::path('/legacy')->fallback(static fn (): string => 'generic');
        self::assertSame('normal', $this->kernel->handle(self::html('GET', '/dashboard'))->content());
        self::assertSame('generic', $this->kernel->handle(self::html('GET', '/legacy/unknown'))->content());
        $token = Csrf::manager()->token();
        $unsafe = static fn (string $path): Request => new Request('POST', $path,
            headers: ['Accept' => 'text/html', 'X-CSRF-Token' => $token]);
        self::assertSame('generic', $this->kernel->handle($unsafe('/legacy/unknown'))->content());
        self::assertSame(405, $this->kernel->handle($unsafe('/dashboard'))->status());
        self::assertStringContainsString('SPA',
            $this->kernel->handle(self::html('GET', '/dashboard/child'))->content());
    }

    public function testExistingRootFallbackRemainsAuthoritativeWhenSpaIsEnabled(): void
    {
        $this->enable();
        Route::path('/')->fallback(static fn (): string => 'existing fallback');
        self::assertSame('existing fallback',
            $this->kernel->handle(self::html('GET', '/dashboard'))->content());
        $token = Csrf::manager()->token();
        self::assertSame('existing fallback', $this->kernel->handle(new Request(
            'POST', '/dashboard', headers: ['X-CSRF-Token' => $token]))->content());
    }

    public function testSpaNeverConvertsUnsafeMethodsOrNonBrowserRequestsToHtml(): void
    {
        $this->enable();
        $token = Csrf::manager()->token();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $this->kernel->handle(new Request($method, '/dashboard',
                headers: ['Accept' => 'text/html', 'X-CSRF-Token' => $token]));
            self::assertSame(404, $response->status(), $method);
            self::assertStringNotContainsString('id="frontend-app"', $response->content());
        }
        foreach ([
            [],
            ['Accept' => 'application/json'],
            ['Accept' => '*/*'],
            ['Accept' => 'text/html;q=0'],
            ['Accept' => 'text/html;q=invalid'],
            ['Accept' => 'text/html', 'X-Requested-With' => 'XMLHttpRequest'],
            ['Accept' => 'text/html', 'Sec-Fetch-Mode' => 'cors'],
        ] as $headers) {
            self::assertSame(404, $this->kernel->handle(
                new Request('GET', '/dashboard', headers: $headers))->status());
        }
    }

    public function testApiAssetsAndInternalPathsStayMissing(): void
    {
        $this->enable();
        foreach (['/api/missing', '/rpc/missing', '/assets/missing.js', '/build/app.123.js',
            '/health/unknown', '/studio/routes', '/squehub', '/App/Core/View.php',
            '/Config/secret', '/.env', '/project/private'] as $path) {
            $response = $this->kernel->handle(self::html('GET', $path));
            self::assertSame(404, $response->status(), $path);
            self::assertStringNotContainsString('id="frontend-app"', $response->content(), $path);
        }
        $api = $this->kernel->handle(self::html('GET', '/api/missing'));
        self::assertSame(404, $api->status());
        self::assertSame('not_found', json_decode($api->content(), true)['error']['code']);
        $this->app->config()->set('api.enabled', false);
        self::assertSame(404, $this->kernel->handle(self::html('GET', '/api/missing'))->status());
    }

    public function testPrefixExclusionsAndUnsafeEncodingsDoNotReachShell(): void
    {
        $this->enable('/app', ['/app/private']);
        self::assertSame(200, $this->kernel->handle(self::html('GET', '/app/dashboard'))->status());
        foreach (['/application', '/app/private', '/app/private/item', '/app/assets/missing.css',
            '/app/%2eenv', '/app/%2fsecret', '/app//broken', '/app/%GG'] as $path) {
            self::assertSame(404, $this->kernel->handle(self::html('GET', $path))->status(), $path);
        }
    }

    public function testMountedSpaUsesApplicationRelativePrefixAndRejectsOutsideMount(): void
    {
        [$app, $kernel] = $this->application('/site');
        $app->config()->set('frontend.spa', [
            'enabled' => true, 'prefix' => '/app', 'view' => 'Frontend.App', 'except' => [],
        ]);
        self::assertSame(200, $kernel->handle(self::html('GET', '/site/app/dashboard'))->status());
        foreach (['/app/dashboard', '/site/other', '/site2/app/dashboard',
            '/site/app/%2e%2e/private'] as $path) {
            self::assertSame(404, $kernel->handle(self::html('GET', $path))->status(), $path);
        }
    }

    public function testConfiguredBrowser404StillHandlesRequestsOutsideSpa(): void
    {
        $this->enable('/app');
        Route::error(404, static fn (): Response => new Response('custom 404', 404));
        self::assertSame('custom 404', $this->kernel->handle(self::html('GET', '/outside'))->content());
        self::assertSame(200, $this->kernel->handle(self::html('GET', '/app/deep'))->status());
    }

    public function testInvalidSpaConfigurationFailsSafely(): void
    {
        foreach ([
            ['enabled' => 'yes', 'prefix' => '/', 'view' => 'Frontend.App', 'except' => []],
            ['enabled' => true, 'prefix' => '/../private', 'view' => 'Frontend.App', 'except' => []],
            ['enabled' => true, 'prefix' => '/', 'view' => '../private', 'except' => []],
        ] as $settings) {
            $this->app->config()->set('frontend.spa', $settings);
            $response = $this->kernel->handle(self::html('GET', '/dashboard'));
            self::assertSame(500, $response->status());
            self::assertStringNotContainsString('Frontend SPA', $response->content());
            self::assertStringNotContainsString('id="frontend-app"', $response->content());
        }
    }
}
