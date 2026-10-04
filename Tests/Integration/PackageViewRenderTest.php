<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Support\RuntimeContext;
use App\View\ViewContext;
use App\View\ViewNotFoundException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Package identity, activation, and directives use the ordinary View renderer. */
final class PackageViewRenderTest extends TestCase
{
    private TemporaryProject $project;
    private string $commerce;
    private string $accounting;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new TemporaryProject();
        $suffix = bin2hex(random_bytes(4));
        $this->commerce = 'Commerce' . $suffix;
        $this->accounting = 'Accounting' . $suffix;
        $this->package($this->commerce);
        $this->package($this->accounting);
        $manager = new PackageManager(new Application($this->project->path()));
        foreach ([$this->commerce, $this->accounting] as $name) {
            self::assertTrue($manager->apply($manager->planEnable($name))->complete());
        }
        $this->selectFreshApplication();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        parent::tearDown();
    }

    public function testOrdinaryAndTwoPackageViewsWithTheSameLocalNameStayDistinct(): void
    {
        $this->project->write('Project/Views/Orders/Index.squehub.php', 'ordinary');
        $this->view($this->commerce, 'Orders.Index', 'commerce');
        $this->view($this->accounting, 'Orders.Index', 'accounting');

        self::assertSame('ordinary', $this->render('Orders.Index'));
        self::assertSame('commerce', $this->render($this->commerce . '::Orders.Index'));
        self::assertSame('accounting', $this->render($this->accounting . '::Orders.Index'));
        $manager = $this->app->container()->make(PackageManager::class);
        self::assertSame($this->commerce, $manager->viewNamespace($this->commerce)?->name());
        self::assertNull($manager->viewNamespace(strtolower($this->commerce)));
        self::assertSame('package:' . $this->commerce,
            $this->app->contributions()->ownerOf('view_namespace', $this->commerce)?->key());
        $upper = strtoupper($this->commerce);
        self::assertNotSame($this->commerce, $upper);
        self::assertNull($manager->viewNamespace($upper));
        try {
            View::renderResult($upper . '::Orders.Index');
            self::fail('A valid but differently cased namespace must not resolve.');
        } catch (ViewNotFoundException $error) {
            self::assertFalse($error->unsafe());
            self::assertSame($upper . '::Orders.Index', $error->view());
        }
        try {
            View::renderResult(strtolower($this->commerce) . '::Orders.Index');
            self::fail('A differently cased Package namespace must not resolve.');
        } catch (ViewNotFoundException $error) {
            self::assertTrue($error->unsafe());
            self::assertSame('[invalid logical name]', $error->view());
        }
    }

    public function testApplicationOverrideTakesPrecedenceAndItsRemovalRestoresPackageSource(): void
    {
        $logical = $this->commerce . '::Orders.Index';
        $this->project->write('Project/Views/Orders/Index.squehub.php', 'ordinary');
        $this->view($this->commerce, 'Orders.Index', 'source');
        self::assertSame('source', $this->render($logical));
        $this->project->write('Project/PackagesViews/' . $this->commerce
            . '/Orders/Index.squehub.php', 'override');
        self::assertSame('override', $this->render($logical));
        self::assertSame('ordinary', $this->render('Orders.Index'));
        unlink($this->project->path('Project/PackagesViews/' . $this->commerce
            . '/Orders/Index.squehub.php'));
        self::assertSame('source', $this->render($logical));
    }

    public function testSelectedViewProvenanceNamesItsNamespaceAndSourceKind(): void
    {
        $name = $this->commerce;
        $logical = $name . '::Orders.Index';
        $this->view($name, 'Orders.Index', 'source');
        $source = View::sourceOf($logical);
        self::assertNotNull($source);
        self::assertSame('package:' . $name, $source->owner->key());
        self::assertSame($name, $source->metadata['namespace'] ?? null);
        self::assertSame('package', $source->metadata['source_kind'] ?? null);
        self::assertStringNotContainsString($this->project->path(), $source->source ?? '');

        $this->project->write('Project/PackagesViews/' . $name
            . '/Orders/Index.squehub.php', 'override');
        $override = View::sourceOf($logical);
        self::assertNotNull($override);
        self::assertSame('application:Project', $override->owner->key());
        self::assertSame($name, $override->metadata['namespace'] ?? null);
        self::assertSame('override', $override->metadata['source_kind'] ?? null);
    }

    public function testExplicitPackageReferencesWorkAcrossIncludeLayoutAndComponent(): void
    {
        $name = $this->commerce;
        $this->project->write('Project/Views/Partials/Row.squehub.php', '[application row]');
        $this->view($name, 'Orders.Index', "@extends('{$name}::Layouts.App')"
            . "@section('body')@include('{$name}::Partials.Row')"
            . "@component('{$name}::OrderCard', ['label' => 'Card'])@endcomponent"
            . "@include('Partials.Row')@endsection");
        $this->view($name, 'Layouts.App', '<main>@yield(\'body\')</main>');
        $this->view($name, 'Partials.Row', '[package row]');
        $this->view($name, 'Components.OrderCard', "@props(['label'])<b>{{ \$label }}</b>");

        self::assertSame('<main>[package row]<b>Card</b>[application row]</main>',
            $this->render($name . '::Orders.Index'));
    }

    public function testOptionalMissingLocalViewSkipsButUnavailableNamespaceDoesNot(): void
    {
        $name = $this->commerce;
        $this->project->write('Project/Views/Pages/Optional.squehub.php',
            "FIRST\n@includeOptional('{$name}::Partials.Missing')\nSECOND\n"
            . "@includeWhen(false, 'Unknown::Partials.Hidden')\nTHIRD");
        self::assertSame('FIRSTSECONDTHIRD',
            preg_replace('/\s+/', '', $this->render('Pages.Optional')));

        $this->project->write('Project/Views/Pages/Unavailable.squehub.php',
            "FIRST\n@includeOptional('Unknown::Partials.Missing')\nSECOND");
        try {
            $this->render('Pages.Unavailable');
            self::fail('An optional Include must not hide an unavailable Package namespace.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('Unknown::Partials.Missing', $error->getMessage());
            self::assertStringNotContainsString($this->project->path(), $error->getMessage());
        }
    }

    public function testCrossNamespaceIncludeCycleNamesBothLogicalViews(): void
    {
        $commerce = $this->commerce . '::Partials.A';
        $accounting = $this->accounting . '::Partials.B';
        $this->view($this->commerce, 'Partials.A',
            "first\n@include('{$accounting}')");
        $this->view($this->accounting, 'Partials.B',
            "second\n@include('{$commerce}')");

        try {
            View::renderResult($commerce);
            self::fail('A cross-Package include cycle must fail.');
        } catch (\Throwable $error) {
            $message = $error->getMessage();
            self::assertStringContainsString('circular', strtolower($message));
            self::assertStringContainsString($commerce, $message);
            self::assertStringContainsString($accounting, $message);
            self::assertStringContainsString('line 2', $message);
            self::assertStringNotContainsString($this->project->path(), $message);
        }
    }

    public function testComposerAssetsAndFragmentUseExactNamespacedOwner(): void
    {
        $name = $this->commerce;
        $other = $this->accounting;
        $this->view($name, 'Orders.Index', <<<'VIEW'
@fragment('orders.list')<p>{{ $label }}</p>@script('/commerce/orders.js')@endfragment
VIEW);
        $this->view($other, 'Orders.Index', '<p>{{ $label ?? "other" }}</p>');
        $this->project->write('Project/Views/Orders/Index.squehub.php', '<p>{{ $label ?? "ordinary" }}</p>');
        View::compose($name . '::Orders.Index', static fn (ViewContext $context): array =>
            ['label' => 'composed']);
        View::assets()->for($name . '::Orders.Index')->style('/commerce/orders.css');

        self::assertStringContainsString('<p>composed</p>', $this->render($name . '::Orders.Index'));
        self::assertSame('<p>other</p>', $this->render($other . '::Orders.Index'));
        self::assertSame('<p>ordinary</p>', $this->render('Orders.Index'));
        $fragment = View::fragment($name . '::Orders.Index', 'orders.list');
        self::assertSame('<p>composed</p>', trim($fragment->html()));
        self::assertStringContainsString('/commerce/orders.js', $fragment->stack('scripts'));
        self::assertStringContainsString('/commerce/orders.css', $fragment->stack('styles'));
    }

    public function testDisabledPackageCannotUseOverrideOrPreviouslyCompiledArtifact(): void
    {
        $name = $this->commerce;
        $logical = $name . '::Orders.Index';
        $this->view($name, 'Orders.Index', 'source');
        $this->project->write('Project/PackagesViews/' . $name
            . '/Orders/Index.squehub.php', 'override');
        self::assertSame('override', $this->render($logical));
        self::assertNotEmpty(glob($this->project->path('Storage/Views/*.php')) ?: []);

        $manager = $this->app->container()->make(PackageManager::class);
        self::assertTrue($manager->apply($manager->planDisable($name))->complete());
        $this->selectFreshApplication();
        $this->assertMissing($logical);

        $manager = $this->app->container()->make(PackageManager::class);
        self::assertTrue($manager->apply($manager->planEnable($name))->complete());
        $this->selectFreshApplication();
        self::assertSame('override', $this->render($logical));
    }

    public function testTwoApplicationsInOneProcessKeepDifferentActivation(): void
    {
        $name = $this->commerce;
        $logical = $name . '::Orders.Index';
        $this->view($name, 'Orders.Index', 'enabled A');
        $disabledProject = new TemporaryProject();
        try {
            $disabledProject->write("Project/Packages/{$name}/{$name}.php",
                '<?php namespace Packages\\' . $name . '; final class ' . $name
                . ' extends \\App\\Plugins\\ServiceProvider {}');
            $disabledProject->write("Project/Packages/{$name}/Views/Orders/Index.squehub.php",
                'disabled source');
            $disabledProject->write("Project/PackagesViews/{$name}/Orders/Index.squehub.php",
                'disabled override');
            $disabled = new Application($disabledProject->path());
            $disabled->bootstrap();
            RuntimeContext::select($disabled);
            self::assertNull($disabled->container()->make(PackageManager::class)
                ->viewNamespace($name));
            $this->assertMissing($logical);

            RuntimeContext::select($this->app);
            self::assertSame('enabled A', $this->render($logical));
        } finally {
            RuntimeContext::select($this->app);
            $disabledProject->remove();
        }
    }

    public function testNamespacedLookupDoesNotExposeRawPhpTemplates(): void
    {
        $name = $this->commerce;
        $this->project->write("Project/Packages/{$name}/Views/Orders/Raw.php",
            '<?php echo "RAW_PACKAGE_PHP";');
        self::assertFalse(View::findViewFile($name . '::Orders.Raw'));
        self::assertNull(View::sourceOf($name . '::Orders.Raw', true));
        $this->assertMissing($name . '::Orders.Raw');
    }

    private function package(string $name): void
    {
        $this->project->write("Project/Packages/{$name}/{$name}.php",
            '<?php namespace Packages\\' . $name . '; final class ' . $name
            . ' extends \\App\\Plugins\\ServiceProvider {}');
    }

    private function view(string $package, string $logical, string $source): void
    {
        $this->project->write('Project/Packages/' . $package . '/Views/'
            . str_replace('.', '/', $logical) . '.squehub.php', $source);
    }

    private function selectFreshApplication(): void
    {
        $this->app = new Application($this->project->path());
        $this->app->bootstrap();
        RuntimeContext::select($this->app);
    }

    private function render(string $logical): string
    {
        return trim(View::renderResult($logical)->html());
    }

    private function assertMissing(string $logical): void
    {
        try {
            View::renderResult($logical);
            self::fail('Unavailable Package View rendered.');
        } catch (ViewNotFoundException $error) {
            self::assertSame($logical, $error->view());
            self::assertStringNotContainsString($this->project->path(), $error->getMessage());
        }
    }
}
