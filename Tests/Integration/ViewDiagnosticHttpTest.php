<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\TestCase;

/** Confirms logical development diagnostics and generic production failures. */
final class ViewDiagnosticHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->testApplication()->write('Project/Views/Pages/MissingComponent.squehub.php',
            "first\n@component('Absent')@endcomponent");
        $this->testApplication()->write('Project/Views/Pages/Runtime.squehub.php',
            "<?php throw new \\RuntimeException('VIEW_SECRET /home/PATH_SECRET'); ?>");
        $this->testApplication()->write('Project/Views/Pages/HttpFailure.squehub.php',
            "<?php throw new \\App\\Http\\Exception\\HttpException(503, "
            . "'PATH_SECRET D:\\\\PATH_SECRET'); ?>");
        $this->testApplication()->write('Project/Views/Pages/Fragments.squehub.php',
            "@fragment('known')known@endfragment");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/diagnostic/missing-view')->get(static function (): void {
    \App\Plugins\View::render('Missing.Page');
});
\App\Routing\Route::path('/diagnostic/missing-component')->get(static function (): void {
    \App\Plugins\View::render('Pages.MissingComponent');
});
\App\Routing\Route::path('/diagnostic/runtime')->get(static function (): void {
    \App\Plugins\View::render('Pages.Runtime', [
        'sessionSecret' => 'SESSION_SECRET',
        'authSecret' => 'AUTH_SECRET',
        'csrfSecret' => 'CSRF_SECRET',
        'propSecret' => 'PROP_SECRET',
        'fragmentSecret' => 'FRAGMENT_SECRET',
    ]);
});
\App\Routing\Route::path('/diagnostic/http')->get(static function (): void {
    \App\Plugins\View::render('Pages.HttpFailure');
});
\App\Routing\Route::path('/diagnostic/invalid-name')->get(static function (): void {
    \App\Plugins\View::render('Missing.<script>alert(1)</script>');
});
\App\Routing\Route::path('/diagnostic/invalid-fragment')->get(static function (): void {
    \App\Plugins\View::fragment('Pages.Runtime', 'FRAGMENT_SECRET/<script>');
});
\App\Routing\Route::path('/diagnostic/missing-fragment')->get(static function (): void {
    \App\Plugins\View::fragment('Pages.Fragments', 'absent');
});
PHP);
    }

    public function testDebugMissingRootAndFragmentUseLogicalNamesWithoutSourceLocations(): void
    {
        $this->app()->config()->set('app.debug', true);

        $missingView = $this->get('/diagnostic/missing-view')
            ->assertStatus(500)->content();
        self::assertStringContainsString('Missing.Page', $missingView);
        self::assertStringNotContainsString('line 1', $missingView);
        self::assertStringNotContainsString($this->testApplication()->root(), $missingView);
        self::assertStringNotContainsString(BASE_DIR, $missingView);
        self::assertStringNotContainsString('Stack trace', $missingView);

        $missingFragment = $this->get('/diagnostic/missing-fragment')
            ->assertStatus(500)->content();
        self::assertStringContainsString('Pages.Fragments', $missingFragment);
        self::assertStringContainsString('absent', $missingFragment);
        self::assertStringNotContainsString('line 1', $missingFragment);
        self::assertStringNotContainsString($this->testApplication()->root(), $missingFragment);
        self::assertStringNotContainsString(BASE_DIR, $missingFragment);
        self::assertStringNotContainsString('Stack trace', $missingFragment);
    }

    public function testDebugHtmlReportsLogicalDependencyWithoutCompiledLocation(): void
    {
        $this->app()->config()->set('app.debug', true);
        $body = $this->get('/diagnostic/missing-component')->assertStatus(500)->content();

        self::assertStringContainsString('Pages.MissingComponent', $body);
        self::assertStringContainsString('line 2', $body);
        self::assertStringContainsString('Components.Absent', $body);
        self::assertStringNotContainsString($this->testApplication()->root(), $body);
        self::assertStringNotContainsString(BASE_DIR, $body);
        self::assertStringNotContainsString('Storage/Cache', $body);
        self::assertStringNotContainsString('Stack trace', $body);
    }

    public function testDebugRuntimeFailureHidesCauseValuesAndCompiledTrace(): void
    {
        $this->app()->config()->set('app.debug', true);
        $body = $this->get('/diagnostic/runtime')->assertStatus(500)->content();

        self::assertStringContainsString('Pages.Runtime', $body);
        self::assertStringContainsString('PHP expression failed while rendering', $body);
        foreach (['VIEW_SECRET', 'SESSION_SECRET', 'AUTH_SECRET', 'CSRF_SECRET',
            'PROP_SECRET', 'FRAGMENT_SECRET', 'PATH_SECRET', 'Storage/Cache',
            $this->testApplication()->root(), BASE_DIR] as $private) {
            self::assertStringNotContainsString($private, $body);
        }
    }

    public function testHttpExceptionInsideViewKeepsStatusWithoutCompiledPath(): void
    {
        $this->app()->config()->set('app.debug', true);
        $body = $this->get('/diagnostic/http')->assertStatus(503)->content();

        self::assertStringContainsString('Pages.HttpFailure', $body);
        self::assertStringNotContainsString('PATH_SECRET', $body);
        self::assertStringNotContainsString($this->testApplication()->root(), $body);
        self::assertStringNotContainsString(BASE_DIR, $body);
    }

    public function testDebugDiagnosticHtmlEscapesOrRejectsUnsafeLogicalNames(): void
    {
        $this->app()->config()->set('app.debug', true);
        $body = $this->get('/diagnostic/invalid-name')->assertStatus(500)->content();

        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString('alert(1)', $body);
        self::assertStringContainsString('invalid logical name', $body);

        $fragment = $this->get('/diagnostic/invalid-fragment')
            ->assertStatus(500)->content();
        self::assertStringContainsString('Fragment name must be a safe logical name',
            $fragment);
        self::assertStringNotContainsString('FRAGMENT_SECRET', $fragment);
        self::assertStringNotContainsString('<script>', $fragment);
        self::assertStringNotContainsString(BASE_DIR, $fragment);
    }

    public function testProductionHtmlAndJsonKeepNamesPathsAndSecretsPrivate(): void
    {
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);

        foreach (['missing-view', 'missing-component', 'missing-fragment',
            'invalid-fragment', 'runtime', 'http'] as $name) {
            $response = $this->get('/diagnostic/' . $name);
            $response->assertStatus($name === 'http' ? 503 : 500);
            $body = $response->content();
            foreach (['Missing.Page', 'Pages.MissingComponent', 'Components.Absent',
                'Pages.Fragments', 'Fragment "absent"',
                'Pages.Runtime', 'VIEW_SECRET', 'SESSION_SECRET', 'AUTH_SECRET',
                'CSRF_SECRET', 'PROP_SECRET', 'FRAGMENT_SECRET', 'PATH_SECRET',
                'Storage/Cache', $this->testApplication()->root(), BASE_DIR] as $private) {
                self::assertStringNotContainsString($private, $body);
            }
        }

        $json = $this->get('/diagnostic/runtime', ['Accept' => 'application/json'])
            ->assertStatus(500)->assertJson();
        self::assertStringContainsString('Internal Server Error', $json->content());
        self::assertStringNotContainsString('VIEW_SECRET', $json->content());
        self::assertStringNotContainsString(BASE_DIR, $json->content());
    }
}
