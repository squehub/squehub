<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\TestCase;

/** Checks source-aware loop diagnostics and production privacy at the HTTP boundary. */
final class LoopCompilerHttpTest extends TestCase
{
    public function testDebugMalformedLoopIdentifiesLogicalViewAndOpeningLine(): void
    {
        $this->writeBrokenRouteAndView();
        $this->app()->config()->set('app.debug', true);

        $body = $this->get('/loop/broken')->assertStatus(500)->content();
        self::assertStringContainsString('Phase14D.Broken', $body);
        self::assertMatchesRegularExpression('/\bline\s+3\b/i', $body);
        self::assertStringContainsString('@foreach', $body);
    }

    public function testProductionMalformedLoopDoesNotExposeSourceDataSecretsOrPaths(): void
    {
        $this->writeBrokenRouteAndView();
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);

        $body = $this->get('/loop/broken')->assertStatus(500)->content();
        self::assertStringContainsString('Internal Server Error', $body);
        self::assertStringNotContainsString('Phase14D.Broken', $body);
        self::assertStringNotContainsString('PRIVATE_LOOP_TEMPLATE_MARKER', $body);
        self::assertStringNotContainsString('PRIVATE_LOOP_ITEM_SECRET', $body);
        self::assertStringNotContainsString('PRIVATE_LOOP_CONTEXT_SECRET', $body);
        self::assertStringNotContainsString('PRIVATE_LOOP_SESSION_SECRET', $body);
        self::assertStringNotContainsString($this->testApplication()->root(), $body);
        self::assertStringNotContainsString(BASE_DIR, $body);
        self::assertStringNotContainsString('Storage/Cache', $body);
    }

    private function writeBrokenRouteAndView(): void
    {
        $this->testApplication()->write('Project/Views/Phase14D/Broken.squehub.php',
            "first\nsecond\n@foreach(\$items as \$item)\nPRIVATE_LOOP_TEMPLATE_MARKER");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/loop/broken')->get(static function (): void {
    \session()->put('loop_secret', 'PRIVATE_LOOP_SESSION_SECRET');
    \App\Core\View::render('Phase14D.Broken', [
        'items' => ['PRIVATE_LOOP_ITEM_SECRET'],
        'secret' => 'PRIVATE_LOOP_CONTEXT_SECRET',
    ]);
});
PHP);
    }
}
