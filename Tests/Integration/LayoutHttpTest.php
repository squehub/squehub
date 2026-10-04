<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\TestCase;
use App\Plugins\ViewContext;

/** Covers layout composition and safe errors at the HTTP boundary. */
final class LayoutHttpTest extends TestCase
{
    public function testNestedLayoutRendersControllerAndProviderDataThroughHttp(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Users.squehub.php',
            "@extends('Layouts.Admin')@section('title')Users@endsection"
            . "@section('content')<main>{{ \$title }}</main>@endsection");
        $this->testApplication()->write('Project/Views/Layouts/Admin.squehub.php',
            "@extends('Layouts.Base')@section('body')"
            . "<aside>{{ \$requestPath }}</aside>@yield('content')@endsection");
        $this->testApplication()->write('Project/Views/Layouts/Base.squehub.php',
            "<title>@yield('title', 'Default')</title>@yield('body')");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/layouts/users')->get(static function (): void {
    \App\Core\View::render('Pages.Users', ['title' => 'Controller title']);
});
PHP);
        $calls = 0;
        $this->app()->views()->provide(static function (ViewContext $context) use (&$calls): array {
            ++$calls;
            return ['requestPath' => $context->request()?->path() ?? 'none'];
        });

        self::assertSame('<title>Users</title><aside>/layouts/users</aside>'
            . '<main>Controller title</main>',
            $this->get('/layouts/users')->assertOk()->content());
        self::assertSame(1, $calls);
    }

    public function testDebugCircularLayoutReportsLogicalChainAndNextRequestWorks(): void
    {
        $this->writeFailureFixtures();
        $this->app()->config()->set('app.debug', true);

        $body = $this->get('/layouts/circular')->assertStatus(500)->content();
        self::assertMatchesRegularExpression('/circular/i', $body);
        self::assertStringContainsString('Layouts.CycleA', $body);
        self::assertStringContainsString('Layouts.CycleB', $body);
        self::assertStringNotContainsString('<main>CHILD_OUTPUT_SENTINEL</main>', $body);
        self::assertSame('<main>Healthy</main>',
            $this->get('/layouts/good')->assertOk()->content());
    }

    public function testDebugMalformedSectionNamesLogicalViewAndSourceLine(): void
    {
        $this->writeFailureFixtures();
        $this->app()->config()->set('app.debug', true);

        $body = $this->get('/layouts/duplicate')->assertStatus(500)->content();
        self::assertStringContainsString('Pages.Duplicate', $body);
        self::assertMatchesRegularExpression('/\bline\s+4\b/i', $body);
        self::assertMatchesRegularExpression('/section/i', $body);
    }

    public function testProductionFailuresDoNotExposePartialOutputSecretsOrPaths(): void
    {
        $this->writeFailureFixtures();
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);

        foreach (['circular', 'duplicate', 'missing'] as $kind) {
            $body = $this->get('/layouts/' . $kind)->assertStatus(500)->content();
            self::assertStringContainsString('Internal Server Error', $body);
            self::assertStringNotContainsString('CHILD_OUTPUT_SENTINEL', $body);
            self::assertStringNotContainsString('APP_KEY_SECRET', $body);
            self::assertStringNotContainsString('SESSION_SECRET', $body);
            self::assertStringNotContainsString('TOKEN_SECRET', $body);
            self::assertStringNotContainsString('PRIVATE_SECTION_SOURCE_MARKER', $body);
            self::assertStringNotContainsString('Pages.Duplicate', $body);
            self::assertStringNotContainsString('Layouts.CycleA', $body);
            self::assertStringNotContainsString($this->testApplication()->root(), $body);
            self::assertStringNotContainsString(dirname(__DIR__, 2), $body);
            self::assertStringNotContainsString('Storage/Cache', $body);
            self::assertSame('<main>Healthy</main>',
                $this->get('/layouts/good')->assertOk()->content());
        }
    }

    private function writeFailureFixtures(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Circular.squehub.php',
            "@extends('Layouts.CycleA')@section('content')"
            . "<main>CHILD_OUTPUT_SENTINEL</main>@endsection");
        $this->testApplication()->write('Project/Views/Layouts/CycleA.squehub.php',
            "@extends('Layouts.CycleB')@section('body')@yield('content')@endsection");
        $this->testApplication()->write('Project/Views/Layouts/CycleB.squehub.php',
            "@extends('Layouts.CycleA')@yield('body')");
        $this->testApplication()->write('Project/Views/Pages/Duplicate.squehub.php',
            "first\nsecond\n@section('content')A@endsection\n"
            . "@section('content')PRIVATE_SECTION_SOURCE_MARKER@endsection");
        $this->testApplication()->write('Project/Views/Pages/Missing.squehub.php',
            "@extends('Layouts.Absent')@section('content')"
            . "<main>CHILD_OUTPUT_SENTINEL</main>@endsection");
        $this->testApplication()->write('Project/Views/Pages/Good.squehub.php',
            '<main>Healthy</main>');
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/layouts/circular')->get(static function (): void {
    \App\Core\View::render('Pages.Circular', [
        'appKey' => 'APP_KEY_SECRET',
        'sessionSecret' => 'SESSION_SECRET',
        'tokenSecret' => 'TOKEN_SECRET',
    ]);
});
\App\Routing\Route::path('/layouts/duplicate')->get(static function (): void {
    \App\Core\View::render('Pages.Duplicate', [
        'appKey' => 'APP_KEY_SECRET',
        'sessionSecret' => 'SESSION_SECRET',
        'tokenSecret' => 'TOKEN_SECRET',
    ]);
});
\App\Routing\Route::path('/layouts/missing')->get(static function (): void {
    \App\Core\View::render('Pages.Missing', [
        'appKey' => 'APP_KEY_SECRET',
        'sessionSecret' => 'SESSION_SECRET',
        'tokenSecret' => 'TOKEN_SECRET',
    ]);
});
\App\Routing\Route::path('/layouts/good')->get(static function (): void {
    \App\Core\View::render('Pages.Good');
});
PHP);
    }
}
