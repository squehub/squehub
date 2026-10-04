<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Http\Request;
use App\Packages\PackageManager;
use App\Plugins\View;
use App\Plugins\ViewContext;
use App\Support\RuntimeContext;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises final template variables across layouts, includes, and compiled code. */
final class ViewContextRenderTest extends TestCase
{
    public function testLayersReachViewsLayoutsAndIncludesWithExplicitPrecedence(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Home.squehub.php', '{{ $brand }}|{{ $title }}|{{ $navigation }}');
            $project->write('Project/Views/Parent.squehub.php', "@include('Partials.Badge')");
            $project->write('Project/Views/ParentExplicit.squehub.php',
                "@include('Partials.Badge', ['title' => 'Include'])");
            $project->write('Project/Views/ParentLocal.squehub.php',
                "@php \$title = 'Local'; @endphp@include('Partials.Plain')");
            $project->write('Project/Views/Partials/Badge.squehub.php', '{{ $brand }}|{{ $title }}');
            $project->write('Project/Views/Partials/Plain.squehub.php', '{{ $brand }}|{{ $title }}');
            $project->write('Project/Views/Layout.squehub.php',
                '<header>{{ $brand }}|{{ $title }}|{{ $layoutOnly }}</header>@yield(\'content\')');
            $project->write('Project/Views/Child.squehub.php',
                "@section('content')<p>Body</p>@endsection@extends('Layout')");
            $project->write('Project/Views/ChildComposed.squehub.php',
                "@section('content')<p>Composed body</p>@endsection@extends('Layout')");

            $app = new Application($project->path());
            $views = $app->views();
            $views->share('brand', 'Acme');
            $views->share('title', 'Shared');
            $views->provide(static fn (ViewContext $context): array => [
                'title' => 'Provided', 'navigation' => 'Primary',
            ]);
            $views->compose('Home', static fn (ViewContext $context): array => ['title' => 'Home']);
            $views->compose('ChildComposed', static fn (ViewContext $context): array => [
                'title' => 'ChildComposer',
            ]);
            $views->compose('Partials.Badge', static fn (ViewContext $context): array => ['title' => 'Badge']);
            $views->compose('Layout', static fn (ViewContext $context): array => [
                'title' => 'Layout', 'layoutOnly' => 'yes',
            ]);

            RuntimeContext::select($app);
            self::assertSame('Acme|Home|Primary', $this->render('Home'));
            self::assertSame('Acme|Explicit|Primary', $this->render('Home', ['title' => 'Explicit']));
            self::assertSame('Acme|Badge', $this->render('Parent'));
            self::assertSame('Acme|Badge', $this->render('Parent', ['title' => 'Explicit']));
            self::assertSame('Acme|Include', $this->render('ParentExplicit'));
            self::assertSame('Acme|Local', $this->render('ParentLocal'));
            self::assertSame('<header>Acme|Provided|yes</header><p>Body</p>', $this->render('Child'));
            self::assertSame('<header>Acme|ChildComposer|yes</header><p>Composed body</p>',
                $this->render('ChildComposed'));
            self::assertSame('<header>Acme|Explicit|yes</header><p>Body</p>',
                $this->render('Child', ['title' => 'Explicit']));
        } finally {
            $project->remove();
        }
    }

    public function testOrdinaryExplicitNamesCannotReplaceRendererInternals(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Names.squehub.php',
                '{{ $view }}|{{ $data }}|{{ $viewFilePath }}|{{ $cacheFile }}|{{ $parsedContent }}');
            $app = new Application($project->path());
            RuntimeContext::select($app);
            self::assertSame('v|d|path|cache|parsed', $this->render('Names', [
                'view' => 'v', 'data' => 'd', 'viewFilePath' => 'path',
                'cacheFile' => 'cache', 'parsedContent' => 'parsed',
            ]));
        } finally {
            $project->remove();
        }
    }

    public function testNestedIncludesInheritRenderDataAndKeepOverridesInTheirSubtree(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Tree.squehub.php',
                "{{ \$post }}|{{ \$title }}|@include('Parts.Row', ['title' => 'Row'])|{{ \$title }}");
            $project->write('Project/Views/TreeComposed.squehub.php',
                "{{ \$title }}|@include('Parts.Row')|{{ \$title }}");
            $project->write('Project/Views/Parts/Row.squehub.php',
                "{{ \$post }}|{{ \$title }}|@include('Parts.Author')");
            $project->write('Project/Views/Parts/Author.squehub.php', '{{ $post }}|{{ $title }}');

            $app = new Application($project->path());
            $app->views()->share('title', 'Shared');
            $app->views()->compose('Parts.Row',
                static fn (ViewContext $context): array => ['title' => 'ComposedRow']);
            RuntimeContext::select($app);

            self::assertSame('P7|Parent|P7|Row|P7|Row|Parent',
                $this->render('Tree', ['post' => 'P7', 'title' => 'Parent']));
            self::assertSame('P8|Second|P8|Row|P8|Row|Second',
                $this->render('Tree', ['post' => 'P8', 'title' => 'Second']));
            self::assertSame('Parent|P7|ComposedRow|P7|ComposedRow|Parent',
                $this->render('TreeComposed', ['post' => 'P7', 'title' => 'Parent']));
        } finally {
            $project->remove();
        }
    }

    public function testPageTitleReachesLayoutHeadAndHeadPartialWithNormalPrecedence(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Direct.squehub.php',
                "@section('content')Body@endsection@extends('Layouts.Direct')");
            $project->write('Project/Views/Pages/Reports.squehub.php',
                "@section('content')Reports@endsection@extends('Layouts.WithHead')");
            $project->write('Project/Views/Pages/Special.squehub.php',
                "@section('content')Special@endsection@extends('Layouts.HeadOverride')");
            $project->write('Project/Views/Layouts/Direct.squehub.php',
                '<html><head><title>{{ $title }}</title></head><body>@yield(\'content\')</body></html>');
            $project->write('Project/Views/Layouts/WithHead.squehub.php',
                '<html><head>@include(\'Partials.Head\')</head><body>@yield(\'content\')</body></html>');
            $project->write('Project/Views/Layouts/HeadOverride.squehub.php',
                '<html><head>@include(\'Partials.Head\', [\'title\' => \'Head Special\'])'
                . '</head><body>{{ $title }}|@yield(\'content\')</body></html>');
            $project->write('Project/Views/Partials/Head.squehub.php',
                '<title>{{ $title }}</title>');

            $app = new Application($project->path());
            RuntimeContext::select($app);
            $app->views()->share('title', 'My App');
            self::assertSame('<html><head><title>My App</title></head><body>Body</body></html>',
                $this->render('Pages.Direct'));
            self::assertSame('<html><head><title>Dashboard</title></head><body>Body</body></html>',
                $this->render('Pages.Direct', ['title' => 'Dashboard']));

            $app->views()->provide(static fn (ViewContext $context): array => ['title' => 'Account']);
            self::assertSame('<html><head><title>Account</title></head><body>Body</body></html>',
                $this->render('Pages.Direct'));
            $app->views()->compose('Pages.Reports',
                static fn (ViewContext $context): array => ['title' => 'Reports']);
            self::assertSame('<html><head><title>Reports</title></head><body>Reports</body></html>',
                $this->render('Pages.Reports'));
            self::assertSame('<html><head><title>Monthly Report</title></head><body>Reports</body></html>',
                $this->render('Pages.Reports', ['title' => 'Monthly Report']));
            self::assertSame('<html><head><title>Head Special</title></head><body>Dashboard|Special</body></html>',
                $this->render('Pages.Special', ['title' => 'Dashboard']));

            $secret = '<script>VIEW_TITLE_SECRET_' . bin2hex(random_bytes(6)) . '</script>';
            $escaped = htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            self::assertSame("<html><head><title>{$escaped}</title></head><body>Reports</body></html>",
                $this->render('Pages.Reports', ['title' => $secret]));
            $cacheFiles = glob($project->path('Storage/Views/*.php')) ?: [];
            self::assertNotEmpty($cacheFiles);
            $sourceContainsTitle = false;
            foreach ($cacheFiles as $file) {
                $source = file_get_contents($file);
                if (!is_string($source)) {
                    throw new \RuntimeException('Compiled View source could not be read.');
                }
                $sourceContainsTitle = $sourceContainsTitle || str_contains($source, $secret);
            }
            self::assertFalse($sourceContainsTitle, 'Runtime title entered compiled template source.');
        } finally {
            $project->remove();
        }
    }

    public function testCompiledTemplateKeepsRequestValuesOutOfSource(): void
    {
        $project = new TemporaryProject();
        $marker = 'context-structure-' . bin2hex(random_bytes(8));
        try {
            $project->write('Project/Views/Private.squehub.php', $marker . ':{{ $tenant }}');
            $app = new Application($project->path());
            RuntimeContext::select($app);
            $app->views()->provide(static fn (ViewContext $context): array => [
                'tenant' => $context->request()?->path() === '/alpha'
                    ? 'SQUEHUB_VIEW_SECRET_ALPHA' : 'SQUEHUB_VIEW_SECRET_BETA',
            ]);

            $app->views()->beginRequest(new Request('GET', '/alpha'));
            try {
                self::assertSame($marker . ':SQUEHUB_VIEW_SECRET_ALPHA', $this->render('Private'));
            } finally {
                $app->views()->endRequest();
            }
            $app->views()->beginRequest(new Request('GET', '/beta'));
            try {
                self::assertSame($marker . ':SQUEHUB_VIEW_SECRET_BETA', $this->render('Private'));
            } finally {
                $app->views()->endRequest();
            }

            $compiled = [];
            foreach (glob($project->path('Storage/Views/*.php')) ?: [] as $file) {
                $content = file_get_contents($file);
                if (is_string($content) && str_contains($content, $marker)) {
                    $compiled[] = $content;
                }
            }
            self::assertNotEmpty($compiled);
            $compiledSource = implode("\n", $compiled);
            self::assertStringNotContainsString('SQUEHUB_VIEW_SECRET_ALPHA', $compiledSource);
            self::assertStringNotContainsString('SQUEHUB_VIEW_SECRET_BETA', $compiledSource);
        } finally {
            $project->remove();
        }
    }

    public function testOnlyEnabledPackageBootContributesContextToItsApplication(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/PackageState.squehub.php', "{{ \$packageLabel ?? 'none' }}");
            $project->write('Project/Packages/ViewContextAddon/ViewContextAddon.php',
                '<?php namespace Packages\\ViewContextAddon; final class ViewContextAddon extends '
                . '\\App\\Plugins\\ServiceProvider { public function boot(): void {'
                . ' \\App\\Plugins\\View::share("packageLabel", "enabled"); } }');

            $disabled = new Application($project->path());
            $disabled->bootstrap();
            RuntimeContext::select($disabled);
            self::assertSame('none', $this->render('PackageState'));

            $manager = $disabled->container()->make(PackageManager::class);
            self::assertTrue($manager->apply($manager->planEnable('ViewContextAddon'))->complete());

            $enabled = new Application($project->path());
            $enabled->bootstrap();
            RuntimeContext::select($enabled);
            self::assertSame('enabled', $this->render('PackageState'));
            self::assertSame('none', $this->renderFor($disabled, 'PackageState'));

            $manager = $enabled->container()->make(PackageManager::class);
            self::assertTrue($manager->apply($manager->planDisable('ViewContextAddon'))->complete());
            $disabledAgain = new Application($project->path());
            $disabledAgain->bootstrap();
            self::assertSame('none', $this->renderFor($disabledAgain, 'PackageState'));
        } finally {
            $project->remove();
        }
    }

    private function renderFor(Application $application, string $view, array $data = []): string
    {
        RuntimeContext::select($application);
        return $this->render($view, $data);
    }

    private function render(string $view, array $data = []): string
    {
        ob_start();
        try {
            View::render($view, $data);
            return trim((string) ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
}
