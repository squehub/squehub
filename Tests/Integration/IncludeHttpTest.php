<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Verifies partial inclusion and safe failure handling at the HTTP boundary. */
final class IncludeHttpTest extends TestCase
{
    public function testNormalOptionalAndConditionalPartialsRenderOnlyWhenSelected(): void
    {
        $this->testApplication()->write('Project/Views/Layouts/App.squehub.php',
            "<head>@stack('styles')</head><main>@yield('content')</main>");
        $this->testApplication()->write('Project/Views/Pages/Partial.squehub.php',
            "@extends('Layouts.App')@section('content')"
            . "@includeOptional('Partials.Absent')"
            . "@includeWhen(\$show, 'Partials.Editor')"
            . "<b>page</b>@endsection");
        $this->testApplication()->write('Project/Views/Partials/Editor.squehub.php',
            "@style('/assets/editor.css')<i>editor</i>");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/includes/yes')->get(static function (): void {
    \App\Plugins\View::render('Pages.Partial', ['show' => true]);
});
\App\Routing\Route::path('/includes/no')->get(static function (): void {
    \App\Plugins\View::render('Pages.Partial', ['show' => false]);
});
PHP);

        $yes = $this->get('/includes/yes')->assertOk()->content();
        self::assertStringContainsString('<i>editor</i>', $yes);
        self::assertStringContainsString('/assets/editor.css', $yes);
        self::assertStringContainsString('<b>page</b>', $yes);

        $no = $this->get('/includes/no')->assertOk()->content();
        self::assertStringNotContainsString('<i>editor</i>', $no);
        self::assertStringNotContainsString('/assets/editor.css', $no);
        self::assertStringContainsString('<b>page</b>', $no);
    }

    public function testDebugRequiredMissingIncludeIdentifiesParentAndSourceLine(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Missing.squehub.php',
            "first\n@include('Partials.Absent')\nlast");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/includes/missing')->get(static function (): void {
    \App\Plugins\View::render('Pages.Missing');
});
PHP);
        $this->app()->config()->set('app.debug', true);

        $body = $this->get('/includes/missing')->assertStatus(500)->content();
        self::assertStringContainsString('Pages.Missing', $body);
        self::assertStringContainsString('Partials.Absent', $body);
        self::assertMatchesRegularExpression('/\bline\s+2\b/i', $body);
    }

    public function testProductionFailuresHidePartialOutputContextAndPathsThenRecover(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Missing.squehub.php',
            "PARTIAL_OUTPUT_SECRET\n@include('Partials.Absent')");
        $this->testApplication()->write('Project/Views/Pages/Cycle.squehub.php',
            "@include('Partials.Cycle')");
        $this->testApplication()->write('Project/Views/Partials/Cycle.squehub.php',
            "PARTIAL_OUTPUT_SECRET\n@include('Partials.Cycle')");
        $this->testApplication()->write('Project/Views/Pages/Invalid.squehub.php',
            "@include('Partials.Good', 'INCLUDE_SECRET')");
        $this->testApplication()->write('Project/Views/Partials/Good.squehub.php',
            '<main>Healthy</main>');
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/includes/missing')->get(static function (): void {
    \App\Plugins\View::render('Pages.Missing', [
        'appKey' => 'APP_KEY_SECRET', 'sessionSecret' => 'SESSION_SECRET',
        'tokenSecret' => 'TOKEN_SECRET',
    ]);
});
\App\Routing\Route::path('/includes/cycle')->get(static function (): void {
    \App\Plugins\View::render('Pages.Cycle', [
        'includeSecret' => 'INCLUDE_SECRET',
    ]);
});
\App\Routing\Route::path('/includes/invalid')->get(static function (): void {
    \App\Plugins\View::render('Pages.Invalid');
});
\App\Routing\Route::path('/includes/good')->get(static function (): void {
    \App\Plugins\View::render('Partials.Good');
});
PHP);
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);

        foreach (['missing', 'cycle', 'invalid'] as $case) {
            $body = $this->get('/includes/' . $case)->assertStatus(500)->content();
            self::assertStringContainsString('Internal Server Error', $body);
            foreach (['PARTIAL_OUTPUT_SECRET', 'APP_KEY_SECRET', 'SESSION_SECRET',
                'TOKEN_SECRET', 'INCLUDE_SECRET', 'Pages.Missing', 'Pages.Cycle',
                'Partials.Absent', 'Storage/Cache'] as $secret) {
                self::assertStringNotContainsString($secret, $body);
            }
            self::assertStringNotContainsString($this->testApplication()->root(), $body);
            self::assertSame('<main>Healthy</main>',
                $this->get('/includes/good')->assertOk()->content());
        }
    }

    public function testOptionalOutsideRootLinkIsNotTreatedAsHarmlessAbsence(): void
    {
        $outside = new TemporaryProject();
        $link = $this->testApplication()->root()
            . '/Project/Views/Partials/Outside.squehub.php';
        try {
            $outside->write('Outside.squehub.php', 'OUTSIDE_INCLUDE_SECRET');
            $this->testApplication()->write('Project/Views/Pages/Unsafe.squehub.php',
                "BEFORE\n@includeOptional('Partials.Outside')\nAFTER");
            $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/includes/unsafe')->get(static function (): void {
    \App\Plugins\View::render('Pages.Unsafe', [
        'includeSecret' => 'INCLUDE_SECRET',
    ]);
});
PHP);
            if (!is_dir(dirname($link))) {
                mkdir(dirname($link), 0777, true);
            }
            if (!@symlink($outside->path('Outside.squehub.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            $this->app()->config()->set('app.env', 'production');
            $this->app()->config()->set('app.debug', false);

            $body = $this->get('/includes/unsafe')->assertStatus(500)->content();
            self::assertStringContainsString('Internal Server Error', $body);
            foreach (['OUTSIDE_INCLUDE_SECRET', 'INCLUDE_SECRET',
                'BEFORE', 'AFTER', $outside->path(), $this->testApplication()->root()] as $secret) {
                self::assertStringNotContainsString($secret, $body);
            }
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            $outside->remove();
        }
    }
}
