<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Http\Request;
use App\Packages\PackageManager;
use App\Plugins\ViewContext;
use App\Support\RuntimeContext;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises Phase 14F ownership through the real resolver and renderer. */
final class AssetRenderTest extends TestCase
{
    public function testNestedLayoutsPageAndPartialsHaveOwnerOrderDespiteDelayedStacks(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Base.squehub.php',
                '<head>@stack(\'styles\')</head><body>@yield(\'body\')'
                . '@stack(\'scripts\')</body>'
                . "@style('/assets/base.css')@script('/assets/base.js')");
            $project->write('Project/Views/Layouts/Admin.squehub.php',
                "@extends('Layouts.Base')@style('/assets/admin.css')"
                . "@script('/assets/admin.js')@section('body')"
                . "<main>@yield('content')</main>@endsection");
            $project->write('Project/Views/Pages/Dashboard.squehub.php',
                "@extends('Layouts.Admin')@style('/assets/page.css')"
                . "@script('/assets/page.js')@section('content')"
                . "@include('Partials.Analytics')@include('Partials.Editor')"
                . "<h1>Dashboard</h1>@endsection");
            $project->write('Project/Views/Partials/Analytics.squehub.php',
                "@style('/assets/analytics.css')@script('/assets/analytics.js')");
            $project->write('Project/Views/Partials/Editor.squehub.php',
                "@style('/assets/editor.css')@script('/assets/editor.js')");
            RuntimeContext::select(new Application($project->path()));

            $output = $this->render('Pages.Dashboard');
            self::assertStringContainsString('<head>', $output);
            self::assertStringContainsString('</head><body><main><h1>Dashboard</h1></main>', $output);
            self::assertOrdered($output, [
                '/assets/base.css', '/assets/admin.css', '/assets/page.css',
                '/assets/analytics.css', '/assets/editor.css',
                '</head>', '<h1>Dashboard</h1>',
                '/assets/base.js', '/assets/admin.js', '/assets/page.js',
                '/assets/analytics.js', '/assets/editor.js', '</body>',
            ]);
            self::assertSame(1, substr_count($output, '/assets/base.css'));
            self::assertStringNotContainsString('@stack', $output);
        } finally {
            $project->remove();
        }
    }

    public function testConditionalAndRepeatedPartialOwnsDirectAssetsButRetainsGenericPushes(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/App.squehub.php',
                "@stack('styles')|@stack('head')|@yield('content')|@stack('scripts')");
            $project->write('Project/Views/Pages/Editor.squehub.php',
                "@extends('Layouts.App')@section('content')"
                . "@if(\$editing)@include('Partials.Editor')"
                . "@include('Partials.Editor')@endif"
                . "@endsection");
            $project->write('Project/Views/Partials/Editor.squehub.php',
                "@style('/assets/editor.css')@script('/assets/editor.js')"
                . "@push('head')<meta name=\"editor\">@endpush");
            RuntimeContext::select(new Application($project->path()));

            $hidden = $this->render('Pages.Editor', ['editing' => false]);
            self::assertStringNotContainsString('/assets/editor.css', $hidden);
            self::assertStringNotContainsString('/assets/editor.js', $hidden);
            self::assertStringNotContainsString('name="editor"', $hidden);

            $shown = $this->render('Pages.Editor', ['editing' => true]);
            self::assertSame(1, substr_count($shown, '/assets/editor.css'));
            self::assertSame(1, substr_count($shown, '/assets/editor.js'));
            self::assertSame(2, substr_count($shown, '<meta name="editor">'));
        } finally {
            $project->remove();
        }
    }

    public function testPrependsPreserveOrderAndRepeatedStackEmissionDoesNotRerunPushBody(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Double.squehub.php',
                "<first>@stack('head')</first><second>@stack('head')</second>"
                . "@yield('content')");
            $project->write('Project/Views/Pages/Captured.squehub.php',
                "@extends('Layouts.Double')"
                . "@prepend('head')<i>A</i>@endprepend"
                . "@prepend('head')<i>B</i>@endprepend"
                . "@push('head')<b>{{ \$tick() }}</b>@endpush"
                . "@push('head')<em>D</em>@endpush"
                . "@push('head')<em>D</em>@endpush"
                . "@push('head', once: 'unique')<u>X</u>@endpush"
                . "@push('head', once: 'unique')<u>X</u>@endpush"
                . "@section('content')<main>Body</main>@endsection");
            RuntimeContext::select(new Application($project->path()));
            $calls = 0;
            $tick = static function () use (&$calls): int { return ++$calls; };

            $stack = implode("\n", [
                '<i>A</i>', '<i>B</i>', '<b>1</b>', '<em>D</em>', '<em>D</em>', '<u>X</u>',
            ]);
            self::assertSame('<first>' . $stack . '</first><second>' . $stack
                . '</second><main>Body</main>', $this->render('Pages.Captured', ['tick' => $tick]));
            self::assertSame(1, $calls);
        } finally {
            $project->remove();
        }
    }

    public function testOnceConflictFailsWithoutOutputAndNextRenderCanReuseKey(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/App.squehub.php',
                "@stack('scripts')@yield('content')");
            $project->write('Project/Views/Pages/Runtime.squehub.php',
                "@extends('Layouts.App')"
                . "@foreach(\$urls as \$url)@script(\$url, once: 'runtime')@endforeach"
                . "@section('content')<main>OK</main>@endsection");
            RuntimeContext::select(new Application($project->path()));

            $same = $this->render('Pages.Runtime', [
                'urls' => ['/assets/chart.js', '/assets/chart.js'],
            ]);
            self::assertSame(1, substr_count($same, '/assets/chart.js'));

            [$error, $partial] = $this->failedRender('Pages.Runtime', [
                'urls' => ['/assets/chart.js', '/assets/other.js'],
            ]);
            self::assertStringContainsString('once key', $error->getMessage());
            self::assertSame('', $partial);

            $fresh = $this->render('Pages.Runtime', ['urls' => ['/assets/other.js']]);
            self::assertSame(1, substr_count($fresh, '/assets/other.js'));
            self::assertStringNotContainsString('/assets/chart.js', $fresh);
        } finally {
            $project->remove();
        }
    }

    public function testExternalRegistrationRequiresParticipationAndSupportsMultipleOwners(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/App.squehub.php',
                "@stack('styles')|@yield('content')");
            foreach (['Dashboard', 'Reports', 'Login'] as $name) {
                $project->write('Project/Views/Pages/' . $name . '.squehub.php',
                    "@extends('Layouts.App')@section('content')" . $name . '@endsection');
            }
            RuntimeContext::select(new Application($project->path()));
            View::assets()->for('Layouts.App')->style('/assets/app.css');
            View::assets()->for(['Pages.Dashboard', 'Pages.Reports'])
                ->style('/assets/shared.css');
            View::assets()->for('Pages.Dashboard')->style('/assets/dashboard.css');
            View::assets()->for('Pages.NeverRendered')->style('/assets/absent.css');

            $login = $this->render('Pages.Login');
            self::assertStringContainsString('/assets/app.css', $login);
            self::assertStringNotContainsString('/assets/shared.css', $login);
            self::assertStringNotContainsString('/assets/dashboard.css', $login);
            self::assertStringNotContainsString('/assets/absent.css', $login);

            $dashboard = $this->render('Pages.Dashboard');
            self::assertOrdered($dashboard, [
                '/assets/app.css', '/assets/shared.css', '/assets/dashboard.css', 'Dashboard',
            ]);
            self::assertSame(1, substr_count($dashboard, '/assets/shared.css'));
            self::assertStringNotContainsString('/assets/absent.css', $dashboard);

            $reports = $this->render('Pages.Reports');
            self::assertSame(1, substr_count($reports, '/assets/shared.css'));
            self::assertStringNotContainsString('/assets/dashboard.css', $reports);
        } finally {
            $project->remove();
        }
    }

    public function testExternalRegistriesAndCollectedAssetsStayWithTheirApplications(): void
    {
        $firstProject = new TemporaryProject();
        $secondProject = new TemporaryProject();
        try {
            foreach ([$firstProject, $secondProject] as $project) {
                $project->write('Project/Views/Layouts/App.squehub.php',
                    "@stack('styles')|@yield('content')");
                $project->write('Project/Views/Pages/Same.squehub.php',
                    "@extends('Layouts.App')@section('content')Same@endsection");
            }
            $first = new Application($firstProject->path());
            $second = new Application($secondProject->path());

            RuntimeContext::select($first);
            View::assets()->for('Pages.Same')->style('/assets/first.css');
            self::assertStringContainsString('/assets/first.css', $this->render('Pages.Same'));

            RuntimeContext::select($second);
            View::assets()->for('Pages.Same')->style('/assets/second.css');
            $other = $this->render('Pages.Same');
            self::assertStringContainsString('/assets/second.css', $other);
            self::assertStringNotContainsString('/assets/first.css', $other);

            RuntimeContext::select($first);
            $again = $this->render('Pages.Same');
            self::assertStringContainsString('/assets/first.css', $again);
            self::assertStringNotContainsString('/assets/second.css', $again);
        } finally {
            $firstProject->remove();
            $secondProject->remove();
        }
    }

    public function testDynamicUrlsAreEvaluatedPerRenderAndEscapedAsAttributes(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/App.squehub.php',
                "<head>@stack('styles')</head><body>@yield('content')"
                . "@stack('scripts')</body>");
            $project->write('Project/Views/Pages/Dynamic.squehub.php',
                "@extends('Layouts.App')@style(\$themeCss)"
                . "@section('content')@include('Partials.Runtime', "
                . "['scriptUrl' => \$scriptUrl])@endsection");
            $project->write('Project/Views/Partials/Runtime.squehub.php',
                '@script($scriptUrl)');
            RuntimeContext::select(new Application($project->path()));

            $light = $this->render('Pages.Dynamic', [
                'themeCss' => '/assets/light.css', 'scriptUrl' => '/assets/a.js',
            ]);
            self::assertStringContainsString('href="/assets/light.css"', $light);
            self::assertStringContainsString('src="/assets/a.js"', $light);

            $dark = $this->render('Pages.Dynamic', [
                'themeCss' => '/assets/dark.css" onload="SECRET',
                'scriptUrl' => '/assets/b.js" defer="SECRET',
            ]);
            self::assertStringContainsString('href="/assets/dark.css&quot; onload=&quot;SECRET"', $dark);
            self::assertStringContainsString('src="/assets/b.js&quot; defer=&quot;SECRET"', $dark);
            self::assertStringNotContainsString('onload="SECRET"', $dark);
            self::assertStringNotContainsString('defer="SECRET"', $dark);
            self::assertStringNotContainsString('/assets/light.css', $dark);
        } finally {
            $project->remove();
        }
    }

    public function testCircularLayoutAfterAssetCollectionLeavesNextRenderClean(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/CycleA.squehub.php',
                "@extends('Layouts.CycleB')@style('/assets/stale.css')");
            $project->write('Project/Views/Layouts/CycleB.squehub.php',
                "@extends('Layouts.CycleA')@script('/assets/stale.js')");
            $project->write('Project/Views/Pages/Bad.squehub.php',
                "@extends('Layouts.CycleA')@style('/assets/page-stale.css')");
            $project->write('Project/Views/Pages/Good.squehub.php',
                "@stack('styles')|@stack('scripts')|<main>Good</main>");
            RuntimeContext::select(new Application($project->path()));

            [$error, $partial] = $this->failedRender('Pages.Bad');
            self::assertStringContainsString('Circular', $error->getMessage());
            self::assertSame('', $partial);
            $good = $this->render('Pages.Good');
            self::assertSame('||<main>Good</main>', $good);
        } finally {
            $project->remove();
        }
    }

    public function testCapturedSectionsRegisterAssetsWhenExecutedButSkippedAncestorDoesNot(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Repeated.squehub.php',
                "@stack('styles')|@stack('head')|@yield('content')|@yield('content')");
            $project->write('Project/Views/Pages/Repeated.squehub.php',
                "@extends('Layouts.Repeated')"
                . "@section('unused')@style('/assets/unused.css')@endsection"
                . "@section('content')@style('/assets/content.css')"
                . "@push('head')<b>Captured once</b>@endpush"
                . "<main>Body</main>@endsection");
            $project->write('Project/Views/Layouts/Default.squehub.php',
                "@section('content')@style('/assets/skipped.css')"
                . "Skipped@endsection@stack('styles')|@yield('content')");
            $project->write('Project/Views/Pages/Override.squehub.php',
                "@extends('Layouts.Default')@section('content')"
                . "@style('/assets/child.css')Child@endsection");
            RuntimeContext::select(new Application($project->path()));

            $repeated = $this->render('Pages.Repeated');
            self::assertSame(1, substr_count($repeated, '/assets/unused.css'));
            self::assertSame(1, substr_count($repeated, '/assets/content.css'));
            self::assertSame(1, substr_count($repeated, '<b>Captured once</b>'));
            self::assertSame(2, substr_count($repeated, '<main>Body</main>'));

            $overridden = $this->render('Pages.Override');
            self::assertStringContainsString('/assets/child.css', $overridden);
            self::assertStringNotContainsString('/assets/skipped.css', $overridden);
            self::assertStringNotContainsString('Skipped', $overridden);
        } finally {
            $project->remove();
        }
    }

    public function testPushCapturesActiveLoopMetadataAndEscapesEachIterationOnce(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/List.squehub.php',
                "<head>@stack('head')</head><main>@yield('content')</main>");
            $project->write('Project/Views/Pages/List.squehub.php',
                "@extends('Layouts.List')@section('content')"
                . "@foreach(\$items as \$item)@push('head')"
                . "<i>{{ \$loop->iteration }}:{{ \$item }}</i>@endpush"
                . "@endforeach@endsection");
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('<head><i>1:A</i>' . "\n"
                . '<i>2:&lt;B&gt;</i></head><main></main>',
                $this->render('Pages.List', ['items' => ['A', '<B>']]));
        } finally {
            $project->remove();
        }
    }

    public function testSharedProviderComposerAndIncludeAssetValuesStayRuntimeOnly(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/App.squehub.php',
                "@stack('styles')|@yield('content')");
            $project->write('Project/Views/Pages/Context.squehub.php',
                "@extends('Layouts.App')@style(\$sharedCss)"
                . "@style(\$providerCss)@style(\$composerCss)"
                . "@style(\$nextUrl())@section('content')"
                . "@include('Partials.Overlay', ['includeCss' => \$includeCss])"
                . "@endsection");
            $project->write('Project/Views/Partials/Overlay.squehub.php',
                '@style($includeCss)');
            $app = new Application($project->path());
            $app->views()->share('sharedCss', '/assets/shared.css');
            $providers = 0;
            $composers = 0;
            $app->views()->provide(static function (ViewContext $context) use (&$providers): array {
                ++$providers;
                return ['providerCss' => '/assets' . ($context->request()?->path() ?? '/none') . '.css'];
            });
            $app->views()->compose('Pages.Context',
                static function (ViewContext $context) use (&$composers): array {
                    ++$composers;
                    return ['composerCss' => '/assets/composer.css'];
                });
            RuntimeContext::select($app);
            $evaluations = 0;
            $nextUrl = static function () use (&$evaluations): string {
                return '/assets/dynamic-' . ++$evaluations . '.css';
            };

            $compiledAfterFirst = [];
            foreach (['/first', '/second'] as $index => $path) {
                $app->views()->beginRequest(new Request('GET', $path));
                try {
                    $output = $this->render('Pages.Context', [
                        'nextUrl' => $nextUrl,
                        'includeCss' => '/assets/include-' . $index . '.css',
                    ]);
                } finally {
                    $app->views()->endRequest();
                }
                self::assertStringContainsString('/assets/shared.css', $output);
                self::assertStringContainsString('/assets' . $path . '.css', $output);
                self::assertStringContainsString('/assets/composer.css', $output);
                self::assertStringContainsString('/assets/dynamic-' . ($index + 1) . '.css', $output);
                self::assertStringContainsString('/assets/include-' . $index . '.css', $output);
                self::assertStringNotContainsString('/assets/include-' . (1 - $index) . '.css', $output);
                if ($index === 0) {
                    $compiledAfterFirst = glob($project->path('Storage/Views/*.php'));
                    self::assertIsArray($compiledAfterFirst);
                    self::assertNotEmpty($compiledAfterFirst);
                }
            }
            self::assertSame($compiledAfterFirst, glob($project->path('Storage/Views/*.php')));
            foreach ($compiledAfterFirst as $file) {
                $compiled = file_get_contents($file);
                self::assertIsString($compiled);
                self::assertStringNotContainsString('/assets/dynamic-1.css', $compiled);
                self::assertStringNotContainsString('/assets/dynamic-2.css', $compiled);
            }
            self::assertSame(2, $providers);
            self::assertSame(2, $composers);
            self::assertSame(2, $evaluations);
        } finally {
            $project->remove();
        }
    }

    public function testRegistryMutationDuringRenderIsVisibleOnlyToNextRenderSnapshot(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Snapshot.squehub.php', <<<'TEMPLATE'
@stack('styles')
@php \App\Core\View::assets()->for('Partials.Later')->style('/assets/late.css'); @endphp
@include('Partials.Later')
TEMPLATE);
            $project->write('Project/Views/Partials/Later.squehub.php', '<span>Later</span>');
            RuntimeContext::select(new Application($project->path()));

            $first = $this->render('Pages.Snapshot');
            self::assertStringNotContainsString('/assets/late.css', $first);
            self::assertStringContainsString('<span>Later</span>', $first);
            $second = $this->render('Pages.Snapshot');
            self::assertSame(1, substr_count($second, '/assets/late.css'));
        } finally {
            $project->remove();
        }
    }

    public function testExternalOwnerUsesExactLogicalSpellingEvenWithPhysicalCaseFallback(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Dashboard.squehub.php',
                "@stack('styles')<main>Dashboard</main>");
            RuntimeContext::select(new Application($project->path()));
            View::assets()->for('Pages.Dashboard')->style('/assets/exact.css');

            $exact = $this->render('Pages.Dashboard');
            self::assertStringContainsString('/assets/exact.css', $exact);
            $variant = $this->render('pages.dashboard');
            self::assertStringContainsString('<main>Dashboard</main>', $variant);
            self::assertStringNotContainsString('/assets/exact.css', $variant);
        } finally {
            $project->remove();
        }
    }

    public function testPackageViewAssetsAndBootRegistrationsRequireEnabledPackage(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/App.squehub.php',
                "@stack('styles')|@yield('content')|@stack('scripts')");
            $project->write('Project/Views/Pages/Home.squehub.php',
                "@extends('Layouts.App')@section('content')"
                . "@if(\$showPackage)@include('Addon')@endif"
                . "<main>Home</main>@endsection");
            $project->write('Project/Packages/AssetAddon/AssetAddon.php',
                '<?php namespace Packages\\AssetAddon; final class AssetAddon extends '
                . '\\App\\Plugins\\ServiceProvider { public function boot(): void {'
                . ' \\App\\Plugins\\View::assets()->for("Pages.Home")'
                . '->style("/assets/package-boot.css"); } }');
            $project->write('Project/Packages/AssetAddon/Views/Addon.squehub.php',
                "@style('/assets/package-view.css')"
                . "@script('/assets/package-view.js')");

            $disabled = new Application($project->path());
            $disabled->bootstrap();
            RuntimeContext::select($disabled);
            $before = $this->render('Pages.Home', ['showPackage' => false]);
            self::assertStringNotContainsString('/assets/package-boot.css', $before);
            self::assertStringNotContainsString('/assets/package-view.css', $before);

            $manager = $disabled->container()->make(PackageManager::class);
            self::assertTrue($manager->apply($manager->planEnable('AssetAddon'))->complete());
            $enabled = new Application($project->path());
            $enabled->bootstrap();
            RuntimeContext::select($enabled);
            $after = $this->render('Pages.Home', ['showPackage' => true]);
            self::assertStringContainsString('/assets/package-boot.css', $after);
            self::assertStringContainsString('/assets/package-view.css', $after);
            self::assertStringContainsString('/assets/package-view.js', $after);
            self::assertStringNotContainsString('/assets/package-boot.css',
                $this->renderFor($disabled, 'Pages.Home', ['showPackage' => false]));
        } finally {
            $project->remove();
        }
    }

    public function testStandalonePublicIncludeFinalizesItsOwnAssetStack(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Partials/Standalone.squehub.php',
                "<head>@stack('styles')</head>@style('/assets/standalone.css')");
            RuntimeContext::select(new Application($project->path()));

            ob_start();
            try {
                View::include('Partials.Standalone');
                $output = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
            self::assertSame('<head><link rel="stylesheet" href="/assets/standalone.css"></head>',
                $output);
        } finally {
            $project->remove();
        }
    }

    public function testStackInsidePushFinalizesRecursivelyAndSelfReferenceFailsCleanly(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Head.squehub.php',
                "<head>@stack('head')</head>@yield('content')");
            $project->write('Project/Views/Pages/NestedStack.squehub.php',
                "@extends('Layouts.Head')"
                . "@push('head')<meta name=\"before\">@stack('scripts-after')@endpush"
                . "@push('scripts-after')<script>ready()</script>@endpush");
            $project->write('Project/Views/Pages/CircularStack.squehub.php',
                "@extends('Layouts.Head')"
                . "@push('head')@stack('head')@endpush");
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('<head><meta name="before"><script>ready()</script></head>',
                $this->render('Pages.NestedStack'));
            [$error, $partial] = $this->failedRender('Pages.CircularStack');
            self::assertStringContainsString('Circular asset stack', $error->getMessage());
            self::assertSame('', $partial);
            self::assertSame('<head><meta name="before"><script>ready()</script></head>',
                $this->render('Pages.NestedStack'));
        } finally {
            $project->remove();
        }
    }

    public function testPushUsesNormalEscapedAndRawTemplateOutputWithoutDoubleEscaping(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/TrustedPush.squehub.php',
                "@stack('head')@push('head')"
                . '<b>{{ $untrusted }}</b><i>{!! $trusted !!}</i>'
                . '@endpush');
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('<b>&lt;unsafe&gt;</b><i><em>safe</em></i>',
                $this->render('Pages.TrustedPush', [
                    'untrusted' => '<unsafe>', 'trusted' => '<em>safe</em>',
                ]));
        } finally {
            $project->remove();
        }
    }

    /** @param list<string> $needles */
    private static function assertOrdered(string $output, array $needles): void
    {
        $previous = -1;
        foreach ($needles as $needle) {
            $position = strpos($output, $needle);
            self::assertNotFalse($position, 'Missing ' . $needle . ' in ' . $output);
            self::assertGreaterThan($previous, $position, 'Unexpected position for ' . $needle);
            $previous = $position;
        }
    }

    /** @param array<string, mixed> $data */
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

    /** @param array<string, mixed> $data */
    private function renderFor(Application $application, string $view, array $data = []): string
    {
        RuntimeContext::select($application);
        return $this->render($view, $data);
    }

    /** @param array<string, mixed> $data @return array{Throwable, string} */
    private function failedRender(string $view, array $data = []): array
    {
        $level = ob_get_level();
        $error = null;
        $output = '';
        ob_start();
        try {
            View::render($view, $data);
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            $output = (string) ob_get_contents();
            while (ob_get_level() > $level) { ob_end_clean(); }
        }
        self::assertNotNull($error, 'Expected asset render to fail.');
        return [$error, $output];
    }
}
