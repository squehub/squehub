<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Plugins\TestCase;

/** Rendered links, form actions, and template assets use the public mount. */
final class BasePathAssetHttpTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    protected function testingConfig(): array
    {
        return ['http' => ['base_path' => '/app']];
    }

    public function testNamedLinksAssetHelperAndTemplateOwnedAssetsStayMountAware(): void
    {
        $this->testApplication()->write('Project/Views/Mounted/Page.squehub.php', <<<'VIEW'
<html><head>@stack('styles')</head><body>
<a href="{{ route('home') }}">Home</a>
<form action="{{ route('form.submit') }}" method="POST"></form>
<img src="{{ asset('/assets/logo.png') }}" alt="Logo">
@style('/assets/app.css')
@script('/assets/app.js')
@style('https://cdn.example.test/widget.css')
@stack('scripts')
</body></html>
VIEW);
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/')->get(static fn (): string => 'home')->named('home');
\App\Routing\Route::path('/submit')->post(static fn (): string => 'submitted')->named('form.submit');
\App\Routing\Route::path('/page')->get(static function (): void {
    \App\Core\View::render('Mounted.Page');
});
PHP);

        $body = $this->get('/app/page')->assertOk()->content();
        self::assertStringContainsString('href="/app/"', $body);
        self::assertStringContainsString('action="/app/submit"', $body);
        self::assertStringContainsString('src="/app/assets/logo.png"', $body);
        self::assertStringContainsString('href="/app/assets/app.css"', $body);
        self::assertStringContainsString('src="/app/assets/app.js"', $body);
        self::assertStringContainsString('href="https://cdn.example.test/widget.css"', $body);
        self::assertStringNotContainsString('/app/app/assets/', $body);
        self::assertSame('home', $this->get('/app/')->assertOk()->content());
    }
}
