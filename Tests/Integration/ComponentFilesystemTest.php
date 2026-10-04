<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Plugins\View;
use App\Support\RuntimeContext;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises component resolution through the existing contained View roots. */
final class ComponentFilesystemTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new TemporaryProject();
        RuntimeContext::select(new Application($this->project->path()));
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        parent::tearDown();
    }

    public function testNormalComponentResolvesFromProjectViews(): void
    {
        $this->project->write('Project/Views/Pages/Normal.squehub.php',
            "@component('Forms.Input', ['label' => 'Name'])@endcomponent");
        $this->project->write('Project/Views/Components/Forms/Input.squehub.php',
            "@props(['label'])<label>{{ \$label }}</label>");
        self::assertSame('<label>Name</label>', $this->render('Pages.Normal'));
    }

    public function testInRootSymlinkRendersAndPhysicalAliasCycleFails(): void
    {
        $this->project->write('Project/Views/Pages/Alias.squehub.php',
            "@component('Alias')@endcomponent");
        $this->project->write('Project/Views/Components/Actual.squehub.php',
            '<span>inside</span>');
        $alias = $this->project->path('Project/Views/Components/Alias.squehub.php');
        if (!@symlink($this->project->path('Project/Views/Components/Actual.squehub.php'),
            $alias)) {
            self::markTestSkipped('File symlinks are unavailable to this test process.');
        }
        try {
            self::assertSame('<span>inside</span>', $this->render('Pages.Alias'));
            $this->project->write('Project/Views/Components/Actual.squehub.php',
                "@component('Alias')@endcomponent");
            [$error, $output] = $this->renderFailure('Pages.Alias');
            self::assertSame('', $output);
            self::assertStringContainsString('circular', strtolower($error->getMessage()));
        } finally {
            unlink($alias);
        }
    }

    public function testOutsideRootComponentSymlinkNeverRendersOrCompilesTarget(): void
    {
        $outside = new TemporaryProject();
        $alias = $this->project->path('Project/Views/Components/Unsafe.squehub.php');
        try {
            $outside->write('Outside.squehub.php', 'OUTSIDE_COMPONENT_SECRET');
            $this->project->write('Project/Views/Pages/Unsafe.squehub.php',
                "@component('Unsafe')@endcomponent");
            if (!is_dir(dirname($alias))) {
                mkdir(dirname($alias), 0777, true);
            }
            if (!@symlink($outside->path('Outside.squehub.php'), $alias)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            [$error, $output] = $this->renderFailure('Pages.Unsafe');
            self::assertSame('', $output);
            self::assertStringNotContainsString('OUTSIDE_COMPONENT_SECRET', $error->getMessage());
            self::assertStringNotContainsString($outside->path(), $error->getMessage());
            foreach (glob($this->project->path('Storage/Views/*.php')) ?: [] as $file) {
                self::assertStringNotContainsString('OUTSIDE_COMPONENT_SECRET',
                    (string) file_get_contents($file));
            }
        } finally {
            if (is_link($alias)) {
                unlink($alias);
            }
            $outside->remove();
        }
    }

    public function testBrokenComponentSymlinkFailsInsteadOfExecutingStaleContent(): void
    {
        $alias = $this->project->path('Project/Views/Components/Broken.squehub.php');
        $this->project->write('Project/Views/Pages/Broken.squehub.php',
            "@component('Broken')@endcomponent");
        if (!is_dir(dirname($alias))) {
            mkdir(dirname($alias), 0777, true);
        }
        if (!@symlink($this->project->path('Project/Views/Components/Absent.squehub.php'),
            $alias)) {
            self::markTestSkipped('File symlinks are unavailable to this test process.');
        }
        try {
            [$error, $output] = $this->renderFailure('Pages.Broken');
            self::assertSame('', $output);
            self::assertStringNotContainsString($alias, $error->getMessage());
        } finally {
            unlink($alias);
        }
    }

    public function testPreviouslyCompiledComponentCannotFollowOutsideRootSourceSwap(): void
    {
        $outside = new TemporaryProject();
        $component = $this->project->path('Project/Views/Components/Swapped.squehub.php');
        try {
            $this->project->write('Project/Views/Pages/Swapped.squehub.php',
                "@component('Swapped')@endcomponent");
            $this->project->write('Project/Views/Components/Swapped.squehub.php',
                '<span>safe</span>');
            $outside->write('Outside.squehub.php', 'OUTSIDE_COMPONENT_SECRET');
            self::assertSame('<span>safe</span>', $this->render('Pages.Swapped'));
            unlink($component);
            if (!@symlink($outside->path('Outside.squehub.php'), $component)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            clearstatcache(true, $component);
            [$error, $output] = $this->renderFailure('Pages.Swapped');
            self::assertSame('', $output);
            self::assertStringNotContainsString('OUTSIDE_COMPONENT_SECRET', $error->getMessage());
        } finally {
            if (is_link($component)) {
                unlink($component);
            }
            $outside->remove();
        }
    }

    public function testPackageComponentRequiresEnabledPackage(): void
    {
        $this->project->write('Project/Views/Pages/Package.squehub.php',
            "<head>@stack('styles')</head>@component('Addon')@endcomponent");
        $this->project->write('Project/Packages/ComponentAddon/ComponentAddon.php',
            '<?php namespace Packages\\ComponentAddon; final class ComponentAddon extends '
            . '\\App\\Plugins\\ServiceProvider {}');
        $this->project->write(
            'Project/Packages/ComponentAddon/Views/Components/Addon.squehub.php',
            "@style('/assets/component-addon.css')<b>enabled</b>");

        $disabled = new Application($this->project->path());
        $disabled->bootstrap();
        RuntimeContext::select($disabled);
        [$error, $output] = $this->renderFailure('Pages.Package');
        self::assertSame('', $output);
        self::assertStringContainsString('Addon', $error->getMessage());

        $manager = $disabled->container()->make(PackageManager::class);
        self::assertTrue($manager->apply($manager->planEnable('ComponentAddon'))->complete());
        $enabled = new Application($this->project->path());
        $enabled->bootstrap();
        RuntimeContext::select($enabled);
        $shown = $this->render('Pages.Package');
        self::assertStringContainsString('<b>enabled</b>', $shown);
        self::assertStringContainsString('/assets/component-addon.css', $shown);

        RuntimeContext::select($disabled);
        [$disabledAgain, $output] = $this->renderFailure('Pages.Package');
        self::assertSame('', $output);
        self::assertStringContainsString('Addon', $disabledAgain->getMessage());
    }

    public function testUnrelatedCasingDoesNotResolveOnCaseSensitiveFilesystem(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Case-sensitive filesystem execution requires Linux qualification.');
        }
        $this->project->write('Project/Views/Pages/Case.squehub.php',
            "@component('CASEONLY')@endcomponent");
        $this->project->write('Project/Views/Components/CaseOnly.squehub.php',
            '<b>canonical</b>');
        [$error, $output] = $this->renderFailure('Pages.Case');
        self::assertSame('', $output);
        self::assertStringContainsString('CASEONLY', $error->getMessage());
    }

    private function render(string $view): string
    {
        ob_start();
        try {
            View::render($view);
            return trim((string) ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    /** @return array{Throwable, string} */
    private function renderFailure(string $view): array
    {
        $error = null;
        ob_start();
        try {
            View::render($view);
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            $output = (string) ob_get_clean();
        }
        self::assertInstanceOf(Throwable::class, $error);
        return [$error, $output];
    }
}
