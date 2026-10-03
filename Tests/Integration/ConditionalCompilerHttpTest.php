<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\TestCase;
use App\Plugins\ViewContext;

/** Phase 14C diagnostics and request context at the HTTP boundary. */
final class ConditionalCompilerHttpTest extends TestCase
{
    public function testDebugErrorIdentifiesLogicalViewAndOpeningLine(): void
    {
        $this->writeBrokenRouteAndView();
        $this->app()->config()->set('app.debug', true);

        $body = $this->get('/conditional/broken')->assertStatus(500)->content();
        self::assertStringContainsString('Phase14C.Broken', $body);
        self::assertMatchesRegularExpression('/\bline\s+3\b/i', $body);
        self::assertStringContainsString('@if', $body);
    }

    public function testProductionErrorDoesNotExposeTemplateOrRuntimeSecrets(): void
    {
        $this->writeBrokenRouteAndView();
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);

        $body = $this->get('/conditional/broken')->assertStatus(500)->content();
        self::assertStringContainsString('Internal Server Error', $body);
        self::assertStringNotContainsString('Phase14C.Broken', $body);
        self::assertStringNotContainsString('PRIVATE_TEMPLATE_MARKER', $body);
        self::assertStringNotContainsString('PRIVATE_RUNTIME_SECRET', $body);
        self::assertStringNotContainsString($this->testApplication()->root(), $body);
        self::assertStringNotContainsString(BASE_DIR, $body);
        self::assertStringNotContainsString('Storage/Cache', $body);
    }

    public function testConditionalsUseCurrentRequestProviderOncePerRequest(): void
    {
        $this->testApplication()->write('Project/Views/Phase14C/Banner.squehub.php',
            '@if($showBanner)BANNER@else{{ \'HIDDEN\' }}@endif');
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/conditional/on')->get(static function (): void {
    \App\Core\View::render('Phase14C.Banner');
    echo '|';
    \App\Core\View::render('Phase14C.Banner');
});
\App\Routing\Route::path('/conditional/off')->get(static function (): void {
    \App\Core\View::render('Phase14C.Banner');
    echo '|';
    \App\Core\View::render('Phase14C.Banner');
});
PHP);
        $calls = 0;
        $this->app()->views()->provide(static function (ViewContext $context) use (&$calls): array {
            ++$calls;
            return ['showBanner' => $context->request()?->path() === '/conditional/on'];
        });

        self::assertSame('BANNER|BANNER', $this->get('/conditional/on')->assertOk()->content());
        self::assertSame(1, $calls);
        self::assertSame('HIDDEN|HIDDEN', $this->get('/conditional/off')->assertOk()->content());
        self::assertSame(2, $calls);
    }

    private function writeBrokenRouteAndView(): void
    {
        $this->testApplication()->write('Project/Views/Phase14C/Broken.squehub.php',
            "first\nsecond\n@if(\$flag)\nPRIVATE_TEMPLATE_MARKER");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/conditional/broken')->get(static function (): void {
    \App\Core\View::render('Phase14C.Broken', [
        'flag' => true,
        'secret' => 'PRIVATE_RUNTIME_SECRET',
    ]);
});
PHP);
    }
}
