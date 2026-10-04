<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Plugins\TestCase;

/** Test helpers and HTTP diagnostics preserve the full Package View identity. */
final class PackageViewTestingHttpTest extends TestCase
{
    private string $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->package = 'Commerce' . bin2hex(random_bytes(4));
        $name = $this->package;
        $this->testApplication()->write("Project/Packages/{$name}/{$name}.php",
            '<?php namespace Packages\\' . $name . '; final class ' . $name
            . ' extends \\App\\Plugins\\ServiceProvider {}');
        $this->testApplication()->write("Project/Packages/{$name}/Views/Orders/Index.squehub.php",
            "@fragment('orders.list')<p>{{ \$label }}</p>@endfragment");
        $this->testApplication()->write('Project/Views/Pages/Caller.squehub.php',
            "before\n@include('{$name}::Partials.Absent')");
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/package/missing')->get(static function (): void {
    \App\Plugins\View::render('Pages.Caller');
});
PHP);
        $manager = new PackageManager(new Application($this->testApplication()->root()));
        self::assertTrue($manager->apply($manager->planEnable($name))->complete());
    }

    public function testViewAndFragmentHelpersAcceptNamespacedRoot(): void
    {
        $logical = $this->package . '::Orders.Index';
        $this->view($logical, ['label' => '<Order>'])
            ->assertSeeEscaped('<Order>')
            ->assertDontSee('<Order>');
        $this->fragment($logical, 'orders.list', ['label' => 'Selected'])
            ->assertSee('<p>Selected</p>');
    }

    public function testDevelopmentDiagnosticNamesCallerLineAndMissingPackageDependency(): void
    {
        $this->app()->config()->set('app.debug', true);
        $body = $this->get('/package/missing')->assertStatus(500)->content();
        self::assertStringContainsString('Pages.Caller', $body);
        self::assertStringContainsString('line 2', $body);
        self::assertStringContainsString($this->package . '::Partials.Absent', $body);
        self::assertStringNotContainsString($this->testApplication()->root(), $body);
        self::assertStringNotContainsString(dirname(__DIR__, 2), $body);
        self::assertStringNotContainsString('Storage/Views', $body);
    }

    public function testProductionDiagnosticDoesNotExposePackageOrPhysicalSource(): void
    {
        $this->app()->config()->set('app.debug', false);
        $body = $this->get('/package/missing')->assertStatus(500)->content();
        self::assertStringNotContainsString('Pages.Caller', $body);
        self::assertStringNotContainsString($this->package, $body);
        self::assertStringNotContainsString($this->testApplication()->root(), $body);
        self::assertStringNotContainsString(dirname(__DIR__, 2), $body);
        self::assertStringNotContainsString('Storage/Views', $body);
    }
}
