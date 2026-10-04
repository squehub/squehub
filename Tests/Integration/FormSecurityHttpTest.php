<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\TestCase;

/** Production form errors must stay generic even when template data is sensitive. */
final class FormSecurityHttpTest extends TestCase
{
    public function testMalformedDirectivesAndRuntimeHelpersDoNotExposeFormSecrets(): void
    {
        $this->testApplication()->write('Project/Views/Pages/BadMethod.squehub.php',
            '@method($verb)');
        $this->testApplication()->write('Project/Views/Pages/BadError.squehub.php',
            '@error() FORM_SECRET @enderror');
        $this->testApplication()->write('Project/Views/Pages/BadState.squehub.php',
            "{{ checked('PASSWORD_SECRET') }}");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/bad-method')->get(static function (): void {
    \App\Core\View::render('Pages.BadMethod', ['verb' => 'CSRF_SECRET']);
});
\App\Routing\Route::path('/bad-error')->get(static function (): void {
    \App\Core\View::render('Pages.BadError');
});
\App\Routing\Route::path('/bad-state')->get(static function (): void {
    \App\Core\View::render('Pages.BadState');
});
PHP);
        $this->app()->config()->set('app.env', 'production');
        $this->app()->config()->set('app.debug', false);

        foreach (['/bad-method', '/bad-error', '/bad-state'] as $path) {
            $body = $this->get($path)->assertStatus(500)->content();
            self::assertStringContainsString('Internal Server Error', $body);
            foreach (['FORM_SECRET', 'PASSWORD_SECRET', 'CSRF_SECRET', $this->testApplication()->root()]
                as $secret) {
                self::assertStringNotContainsString($secret, $body);
            }
        }
    }
}
