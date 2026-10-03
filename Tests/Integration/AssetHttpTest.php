<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\TestCase;

/** Focused Phase 14F checks at the HTTP response boundary. */
final class AssetHttpTest extends TestCase
{
    public function testDebugMalformedAssetBlockReportsLogicalViewAndSourceLine(): void
    {
        $this->testApplication()->write('Project/Views/Pages/BadPush.squehub.php',
            "first\n@push('head')\nPRIVATE_PUSH_BODY\n@endprepend");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/assets/bad-push')->get(static function (): void {
    \App\Core\View::render('Pages.BadPush');
});
PHP);
        $this->app()->config()->set('app.debug', true);

        $body = $this->get('/assets/bad-push')->assertStatus(500)->content();
        self::assertStringContainsString('Pages.BadPush', $body);
        self::assertMatchesRegularExpression('/\bline\s+4\b/i', $body);
        self::assertMatchesRegularExpression('/endprepend/i', $body);
    }

    public function testFinalHeadAndBodyPlacementAndConditionalAssetsDoNotLeakAcrossRequests(): void
    {
        $this->testApplication()->write('Project/Views/Layouts/App.squehub.php',
            "<html><head>@stack('styles')</head><body>"
            . "@yield('content')@stack('scripts')</body></html>");
        $this->testApplication()->write('Project/Views/Pages/Dashboard.squehub.php',
            "@extends('Layouts.App')@style('/assets/dashboard.css')"
            . "@script('/assets/dashboard.js')@section('content')"
            . "@if(\$editing)@include('Partials.Editor')@endif"
            . "<main>Dashboard</main>@endsection");
        $this->testApplication()->write('Project/Views/Partials/Editor.squehub.php',
            "@style('/assets/editor.css')@script('/assets/editor.js')");
        $this->testApplication()->write('Project/Views/Pages/Login.squehub.php',
            "@extends('Layouts.App')@style('/assets/login.css')"
            . "@section('content')<main>Login</main>@endsection");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/assets/dashboard')->get(static function (): void {
    \App\Core\View::render('Pages.Dashboard', ['editing' => true]);
});
\App\Routing\Route::path('/assets/dashboard-plain')->get(static function (): void {
    \App\Core\View::render('Pages.Dashboard', ['editing' => false]);
});
\App\Routing\Route::path('/assets/login')->get(static function (): void {
    \App\Core\View::render('Pages.Login');
});
PHP);

        $dashboard = $this->get('/assets/dashboard')->assertOk()->content();
        self::assertMatchesRegularExpression(
            '~<head>.*dashboard\.css.*editor\.css.*</head><body>~s', $dashboard
        );
        self::assertMatchesRegularExpression(
            '~<main>Dashboard</main>.*dashboard\.js.*editor\.js.*</body>~s', $dashboard
        );

        $plain = $this->get('/assets/dashboard-plain')->assertOk()->content();
        self::assertStringContainsString('/assets/dashboard.css', $plain);
        self::assertStringNotContainsString('/assets/editor.css', $plain);
        self::assertStringNotContainsString('/assets/editor.js', $plain);

        $login = $this->get('/assets/login')->assertOk()->content();
        self::assertStringContainsString('/assets/login.css', $login);
        self::assertStringNotContainsString('/assets/dashboard.css', $login);
        self::assertStringNotContainsString('/assets/editor.css', $login);
        self::assertStringNotContainsString('/assets/dashboard.js', $login);
        self::assertStringContainsString('/assets/editor.css',
            $this->get('/assets/dashboard')->assertOk()->content());
    }

    public function testDynamicAssetUrlsAreEscapedInsideFinalHttpAttributes(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Dynamic.squehub.php',
            "<head>@stack('styles')</head><body>@stack('scripts')</body>"
            . '@style($css)@script($js)');
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/assets/dynamic')->get(static function (): void {
    \App\Core\View::render('Pages.Dynamic', [
        'css' => '/assets/a.css" onload="SECRET',
        'js' => '/assets/a.js" defer="SECRET',
    ]);
});
PHP);

        $body = $this->get('/assets/dynamic')->assertOk()->content();
        self::assertStringContainsString('href="/assets/a.css&quot; onload=&quot;SECRET"', $body);
        self::assertStringContainsString('src="/assets/a.js&quot; defer=&quot;SECRET"', $body);
        self::assertStringNotContainsString('onload="SECRET"', $body);
        self::assertStringNotContainsString('defer="SECRET"', $body);
    }

    public function testProductionAssetErrorsHideSourceContextAndPartialOutput(): void
    {
        $this->testApplication()->write('Project/Views/Pages/BadValue.squehub.php',
            "<head>@stack('styles')</head>PRIVATE_ASSET_OUTPUT\n"
            . '@style($invalidUrl)');
        $this->testApplication()->write('Project/Views/Pages/BadCapture.squehub.php',
            "first\nsecond\n@push('head')PRIVATE_PUSH_BODY\n"
            . "@endprepend");
        $this->testApplication()->write('Project/Views/Pages/Good.squehub.php',
            "@stack('styles')<main>Healthy</main>");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/assets/bad-value')->get(static function (): void {
    \App\Core\View::render('Pages.BadValue', [
        'invalidUrl' => "\x01APP_KEY_SECRET",
        'sessionSecret' => 'SESSION_SECRET',
        'tokenSecret' => 'TOKEN_SECRET',
    ]);
});
\App\Routing\Route::path('/assets/bad-capture')->get(static function (): void {
    \App\Core\View::render('Pages.BadCapture', [
        'appKey' => 'APP_KEY_SECRET',
        'sessionSecret' => 'SESSION_SECRET',
        'tokenSecret' => 'TOKEN_SECRET',
    ]);
});
\App\Routing\Route::path('/assets/good')->get(static function (): void {
    \App\Core\View::render('Pages.Good');
});
PHP);
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);

        foreach (['bad-value', 'bad-capture'] as $case) {
            $response = $this->get('/assets/' . $case);
            self::assertSame(500, $response->status(), $case . ' must fail safely.');
            $body = $response->content();
            self::assertStringContainsString('Internal Server Error', $body);
            foreach (['PRIVATE_ASSET_OUTPUT', 'PRIVATE_PUSH_BODY',
                'APP_KEY_SECRET', 'SESSION_SECRET', 'TOKEN_SECRET',
                'Pages.BadValue', 'Pages.BadCapture', 'Storage/Cache'] as $secret) {
                self::assertStringNotContainsString($secret, $body);
            }
            self::assertStringNotContainsString($this->testApplication()->root(), $body);
            self::assertSame('<main>Healthy</main>',
                $this->get('/assets/good')->assertOk()->content());
        }
    }
}
