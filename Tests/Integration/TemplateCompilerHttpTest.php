<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\TestCase;
use App\Testing\TestApplication;

/** Checks compiler diagnostics at the existing HTTP error boundary. */
final class TemplateCompilerHttpTest extends TestCase
{
    public function testDebugResponseNamesTheViewAndTemplateSourceLine(): void
    {
        $this->writeBrokenRouteAndView();
        $this->app()->config()->set('app.debug', true);

        $response = $this->get('/broken-template')->assertStatus(500);
        self::assertStringContainsString('Pages.Broken', $response->content());
        self::assertMatchesRegularExpression('/\bline\s+3\b/i', $response->content());
    }

    public function testProductionResponseKeepsPathsSourceAndUnrelatedContextSecretPrivate(): void
    {
        $this->writeBrokenRouteAndView();
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);

        $body = $this->get('/broken-template')->assertStatus(500)->content();
        self::assertStringContainsString('Internal Server Error', $body);
        self::assertStringNotContainsString('Pages.Broken', $body);
        self::assertStringNotContainsString('{{ $title', $body);
        self::assertStringNotContainsString('PRIVATE_COMPILER_CONTEXT_MARKER', $body);
        self::assertStringNotContainsString($this->testApplication()->root(), $body);
        self::assertStringNotContainsString(BASE_DIR, $body);
        self::assertStringNotContainsString('Storage/Cache', $body);
    }

    public function testUnavailableViewReturnsGenericProductionError(): void
    {
        $this->writeMissingViewRoute();
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);

        $body = $this->get('/unavailable-view')->assertStatus(500)->content();
        self::assertStringContainsString('Internal Server Error', $body);
        self::assertStringNotContainsString('Unavailable', $body);
        self::assertStringNotContainsString($this->testApplication()->root(), $body);
        self::assertStringNotContainsString(BASE_DIR, $body);
    }

    public function testOutsideRootLinkedViewCannotExposeSourceThroughHttp(): void
    {
        $outside = TestApplication::temporary();
        try {
            $marker = 'OUTSIDE_SECRET_MARKER_' . bin2hex(random_bytes(6));
            $outside->write('Project/Views/Outside.squehub.php', $marker);
            $link = $this->testApplication()->path('Project/Views/Linked.squehub.php');
            if (!@symlink($outside->path('Project/Views/Outside.squehub.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/linked-view')->get(
    static fn (): string => (string) \App\Core\View::render('Linked')
);
PHP);
            $this->app()->config()->set('app.debug', true);

            $debug = $this->get('/linked-view')->assertStatus(500)->content();
            self::assertStringContainsString('Linked', $debug);
            self::assertStringContainsString('unsafe or unavailable', $debug);
            self::assertStringNotContainsString($marker, $debug);
            self::assertStringNotContainsString($outside->root(), $debug);
            self::assertStringNotContainsString($this->testApplication()->root(), $debug);
            self::assertStringNotContainsString('Stack trace', $debug);

            $this->app()->config()->set('app.env', 'production');
            $this->app()->config()->set('app.debug', false);

            $body = $this->get('/linked-view')->assertStatus(500)->content();
            self::assertStringContainsString('Internal Server Error', $body);
            self::assertStringNotContainsString('Linked', $body);
            self::assertStringNotContainsString($marker, $body);
            self::assertStringNotContainsString($outside->root(), $body);
            self::assertStringNotContainsString($this->testApplication()->root(), $body);
        } finally {
            $outside->cleanup();
        }
    }

    private function writeMissingViewRoute(): void
    {
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/unavailable-view')->get(
    static fn (): string => (string) \App\Core\View::render('Unavailable')
);
PHP);
    }

    private function writeBrokenRouteAndView(): void
    {
        $this->testApplication()->write('Project/Views/Pages/Broken.squehub.php',
            "first\nsecond\n{{ \$title");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/broken-template')->get(static function (): void {
    \App\Core\View::render('Pages.Broken', [
        'title' => 'Example',
        'unrelatedSecret' => 'PRIVATE_COMPILER_CONTEXT_MARKER',
    ]);
});
PHP);
    }
}
