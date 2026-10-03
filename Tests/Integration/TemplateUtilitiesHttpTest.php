<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\TestCase;

/** Checks that failed utility evaluation uses the normal production error boundary. */
final class TemplateUtilitiesHttpTest extends TestCase
{
    public function testInvalidJsonAndClassInputsDoNotExposeRenderStateOverHttp(): void
    {
        $this->testApplication()->write('Project/Views/Phase14K/InvalidJson.squehub.php',
            'PARTIAL_JSON_OUTPUT<script>window.value=@json($value);</script>');
        $this->testApplication()->write('Project/Views/Phase14K/InvalidClass.squehub.php',
            'PARTIAL_CLASS_OUTPUT<div class="{{ classes([$value]) }}">SECRET_BODY</div>');
        $this->testApplication()->write('Project/Views/Phase14K/Healthy.squehub.php',
            '<main>Healthy</main>');
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/utilities/invalid-json')->get(static function (): void {
    \App\Core\View::render('Phase14K.InvalidJson', [
        'value' => ['secret' => 'JSON_PRIVATE_SECRET', 'number' => INF],
    ]);
});
\App\Routing\Route::path('/utilities/invalid-class')->get(static function (): void {
    \App\Core\View::render('Phase14K.InvalidClass', [
        'value' => (object) ['secret' => 'CLASS_PRIVATE_SECRET'],
    ]);
});
\App\Routing\Route::path('/utilities/healthy')->get(static function (): void {
    \App\Core\View::render('Phase14K.Healthy');
});
PHP);
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);

        foreach (['invalid-json', 'invalid-class'] as $case) {
            $body = $this->get('/utilities/' . $case)->assertStatus(500)->content();
            self::assertStringContainsString('Internal Server Error', $body);
            foreach (['PARTIAL_JSON_OUTPUT', 'PARTIAL_CLASS_OUTPUT', 'SECRET_BODY',
                'JSON_PRIVATE_SECRET', 'CLASS_PRIVATE_SECRET', 'Phase14K.Invalid',
                'Storage/Cache'] as $secret) {
                self::assertStringNotContainsString($secret, $body);
            }
            self::assertStringNotContainsString($this->testApplication()->root(), $body);
            self::assertSame('<main>Healthy</main>',
                $this->get('/utilities/healthy')->assertOk()->content());
        }
    }
}
