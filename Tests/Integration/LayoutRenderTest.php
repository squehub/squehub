<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Http\Request;
use App\Plugins\ViewContext;
use App\Support\RuntimeContext;
use App\View\Compiler\CompilerException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises layout inheritance through the real resolver and compiled renderer. */
final class LayoutRenderTest extends TestCase
{
    public function testSingleLayoutAndSeparateTopLevelRendersKeepSectionsSeparate(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Main.squehub.php',
                '<title>@yield(\'title\', \'My App\')</title><main>@yield(\'content\')</main>');
            $project->write('Project/Views/Pages/First.squehub.php',
                "@extends('Layouts.Main')@section('title')First@endsection"
                . "@section('content')<b>{{ \$title }}</b>@endsection");
            $project->write('Project/Views/Pages/Second.squehub.php',
                "@section('content')Second@endsection@extends('Layouts.Main')");
            $project->write('Project/Views/Pages/Plain.squehub.php', 'Plain {{ $title }}');
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('<title>First</title><main><b>Controller</b></main>',
                $this->render('Pages.First', ['title' => 'Controller']));
            self::assertSame('<title>My App</title><main>Second</main>',
                $this->render('Pages.Second'));
            self::assertSame('Plain Direct', $this->render('Pages.Plain', ['title' => 'Direct']));
        } finally {
            $project->remove();
        }
    }

    public function testThreeLevelLayoutUsesChildOverrideIntermediateSectionAndBaseDefault(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Base.squehub.php',
                '<title>@yield(\'title\', \'Application\')</title>@yield(\'body\')');
            $project->write('Project/Views/Layouts/Admin.squehub.php',
                "@extends('Layouts.Base')@section('title')Admin@endsection"
                . "@section('sidebar')Navigation@endsection"
                . "@section('body')<aside>@yield('sidebar')</aside>"
                . "<main>@yield('content')</main>@endsection");
            $project->write('Project/Views/Layouts/Bare.squehub.php',
                "@extends('Layouts.Base')@section('body')"
                . "<main>@yield('content')</main>@endsection");
            $project->write('Project/Views/Pages/Users.squehub.php',
                "@extends('Layouts.Admin')@section('title')Users@endsection"
                . "@section('content')<h1>Users</h1>@endsection");
            $project->write('Project/Views/Pages/AdminDefault.squehub.php',
                "@extends('Layouts.Admin')@section('content')Dashboard@endsection");
            $project->write('Project/Views/Pages/BaseDefault.squehub.php',
                "@extends('Layouts.Bare')@section('content')Welcome@endsection");
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('<title>Users</title><aside>Navigation</aside><main><h1>Users</h1></main>',
                $this->render('Pages.Users'));
            self::assertSame('<title>Admin</title><aside>Navigation</aside><main>Dashboard</main>',
                $this->render('Pages.AdminDefault'));
            self::assertSame('<title>Application</title><main>Welcome</main>',
                $this->render('Pages.BaseDefault'));
        } finally {
            $project->remove();
        }
    }

    /** @dataProvider lineEndings */
    public function testTopPlacedMultilineExtendsDiscardsLooseChildAndMiddleOutput(string $lineEnding): void
    {
        $project = new TemporaryProject();
        try {
            $page = <<<'TEMPLATE'
@extends(
    'Layouts.Middle'
)
{{ $touch('page') }}
CHILD_LOOSE_OUTPUT
@section('footer')Footer@endsection
@section('header')Header@endsection
@section('content')Content@endsection
TEMPLATE;
            $middle = <<<'TEMPLATE'
@extends(
    'Layouts.Base'
)
{{ $touch('middle') }}
MIDDLE_LOOSE_OUTPUT
@section('body')<main>@yield('content')</main>@endsection
TEMPLATE;
            $project->write('Project/Views/Pages/Loose.squehub.php',
                str_replace("\n", $lineEnding, $page));
            $project->write('Project/Views/Layouts/Middle.squehub.php',
                str_replace("\n", $lineEnding, $middle));
            $project->write('Project/Views/Layouts/Base.squehub.php',
                "<header>@yield('header')</header>@yield('body')"
                . "<footer>@yield('footer')</footer>");
            RuntimeContext::select(new Application($project->path()));
            $calls = [];
            $touch = static function (string $name) use (&$calls): string {
                $calls[] = $name;
                return 'EXPRESSION_LOOSE_OUTPUT';
            };

            self::assertSame('<header>Header</header><main>Content</main><footer>Footer</footer>',
                $this->render('Pages.Loose', ['touch' => $touch]));
            self::assertSame(['page', 'middle'], $calls);
        } finally {
            $project->remove();
        }
    }

    public function testOverriddenAncestorSectionBodiesDoNotExecute(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Base.squehub.php',
                "@section('title'){{ \$next('base') }}@endsection"
                . "<title>@yield('title')</title>");
            $project->write('Project/Views/Layouts/Admin.squehub.php',
                "@extends('Layouts.Base')@section('title')"
                . "{{ \$next('admin') }}@endsection");
            $project->write('Project/Views/Pages/Child.squehub.php',
                "@extends('Layouts.Admin')@section('title')"
                . "{{ \$next('page') }}@endsection");
            $project->write('Project/Views/Pages/Intermediate.squehub.php',
                "@extends('Layouts.Admin')");
            $project->write('Project/Views/Pages/Base.squehub.php',
                "@extends('Layouts.Base')");
            RuntimeContext::select(new Application($project->path()));
            $calls = [];
            $next = static function (string $name) use (&$calls): string {
                $calls[] = $name;
                return strtoupper($name);
            };

            self::assertSame('<title>PAGE</title>',
                $this->render('Pages.Child', ['next' => $next]));
            self::assertSame(['page'], $calls);
            $calls = [];
            self::assertSame('<title>ADMIN</title>',
                $this->render('Pages.Intermediate', ['next' => $next]));
            self::assertSame(['admin'], $calls);
            $calls = [];
            self::assertSame('<title>BASE</title>',
                $this->render('Pages.Base', ['next' => $next]));
            self::assertSame(['base'], $calls);
        } finally {
            $project->remove();
        }
    }

    public function testIncludedSectionDeclarationsUseExistingSameDepthLastWinsBehavior(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Main.squehub.php',
                "<strong>@yield('badge')</strong>");
            $project->write('Project/Views/Partials/First.squehub.php',
                "@section('badge')First@endsection");
            $project->write('Project/Views/Partials/Second.squehub.php',
                "@section('badge')Second@endsection");
            $project->write('Project/Views/Pages/IncludeLast.squehub.php',
                "@extends('Layouts.Main')@section('badge')Page@endsection"
                . "@include('Partials.First')@include('Partials.Second')");
            $project->write('Project/Views/Pages/PageLast.squehub.php',
                "@extends('Layouts.Main')@include('Partials.Second')"
                . "@section('badge')Page@endsection");
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('<strong>Second</strong>', $this->render('Pages.IncludeLast'));
            self::assertSame('<strong>Page</strong>', $this->render('Pages.PageLast'));
            self::assertSame('<strong>Second</strong>', $this->render('Pages.IncludeLast'));
        } finally {
            $project->remove();
        }
    }

    public function testExtendsInsideAnIncludedPartialIsRejectedAndTheNextRenderIsClean(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Main.squehub.php',
                "<main>@yield('content')</main>");
            $project->write('Project/Views/Layouts/Other.squehub.php', '<other/>');
            $project->write('Project/Views/Partials/Bad.squehub.php',
                "first\n@extends('Layouts.Other')");
            $project->write('Project/Views/Pages/Bad.squehub.php',
                "@extends('Layouts.Main')@section('content')"
                . "@include('Partials.Bad')@endsection");
            $project->write('Project/Views/Pages/Good.squehub.php',
                "@extends('Layouts.Main')@section('content')GOOD@endsection");
            RuntimeContext::select(new Application($project->path()));

            [$error, $output] = $this->failedRender('Pages.Bad');
            self::assertInstanceOf(CompilerException::class, $error);
            self::assertSame('Partials.Bad', $error->view());
            self::assertSame(2, $error->sourceLine());
            self::assertStringContainsString('@extends', $error->getMessage());
            self::assertSame('', $output);
            self::assertSame('<main>GOOD</main>', $this->render('Pages.Good'));
        } finally {
            $project->remove();
        }
    }

    public function testSectionBodyIsCapturedOnceAndCanBeYieldedRepeatedly(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Multi.squehub.php',
                '<header>@yield(\'notice\')</header><main>@yield(\'notice\')</main>'
                . 'A@yield(\'missing\')B|@yield(\'empty\', \'fallback\')');
            $project->write('Project/Views/Pages/Notice.squehub.php',
                "@extends('Layouts.Multi')@section('notice')<em>{{ \$next() }}</em>@endsection"
                . "@section('empty')@endsection");
            RuntimeContext::select(new Application($project->path()));
            $calls = 0;
            $next = static function () use (&$calls): int { return ++$calls; };

            self::assertSame('<header><em>1</em></header><main><em>1</em></main>AB|',
                $this->render('Pages.Notice', ['next' => $next]));
            self::assertSame(1, $calls);
            self::assertSame('<header><em>2</em></header><main><em>2</em></main>AB|',
                $this->render('Pages.Notice', ['next' => $next]));
            self::assertSame(2, $calls);
        } finally {
            $project->remove();
        }
    }

    public function testYieldFallbackIsLazyEscapedAndSeparateFromControllerTitle(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Title.squehub.php',
                '<title>@yield(\'title\', $fallback())</title><data>{{ $title }}</data>');
            $project->write('Project/Views/Pages/SectionTitle.squehub.php',
                "@extends('Layouts.Title')@section('title')Section Title@endsection");
            $project->write('Project/Views/Pages/ContextTitle.squehub.php',
                "@extends('Layouts.Title')");
            RuntimeContext::select(new Application($project->path()));
            $calls = 0;
            $fallback = static function () use (&$calls): string {
                ++$calls;
                return '<script>fallback</script>';
            };

            self::assertSame('<title>Section Title</title><data>Controller &amp; Title</data>',
                $this->render('Pages.SectionTitle', [
                    'title' => 'Controller & Title', 'fallback' => $fallback,
                ]));
            self::assertSame(0, $calls);
            self::assertSame('<title>&lt;script&gt;fallback&lt;/script&gt;</title>'
                . '<data>Controller &amp; Title</data>',
                $this->render('Pages.ContextTitle', [
                    'title' => 'Controller & Title', 'fallback' => $fallback,
                ]));
            self::assertSame(1, $calls);
        } finally {
            $project->remove();
        }
    }

    public function testNestedLayoutsInheritResolvedContextAndApplyTheirComposers(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Report.squehub.php',
                "@extends('Layouts.Admin', ['local' => 'Overlay'])"
                . "@section('content')<p>{{ \$title }}|{{ \$brand }}|{{ \$provider }}</p>@endsection");
            $project->write('Project/Views/Layouts/Admin.squehub.php',
                "@extends('Layouts.Base')@section('shell')"
                . "<nav>{{ \$adminOnly }}</nav>@yield('content')@endsection");
            $project->write('Project/Views/Layouts/Base.squehub.php',
                '<div>{{ $title }}|{{ $brand }}|{{ $provider }}|{{ $baseOnly }}|{{ $local }}</div>'
                . "@yield('shell')");
            $app = new Application($project->path());
            $app->views()->share('brand', 'Acme');
            $calls = 0;
            $app->views()->provide(static function (ViewContext $context) use (&$calls): array {
                ++$calls;
                return ['provider' => $context->request()?->path() ?? 'none'];
            });
            $app->views()->compose('Layouts.Admin',
                static fn (ViewContext $context): array => ['adminOnly' => 'Admin']);
            $app->views()->compose('Layouts.Base',
                static fn (ViewContext $context): array => [
                    'title' => 'Base composer', 'baseOnly' => 'Base',
                ]);
            RuntimeContext::select($app);

            $app->views()->beginRequest(new Request('GET', '/reports'));
            try {
                self::assertSame('<div>Page|Acme|/reports|Base|Overlay</div>'
                    . '<nav>Admin</nav><p>Page|Acme|/reports</p>',
                    $this->render('Pages.Report', ['title' => 'Page']));
                self::assertSame(1, $calls);
            } finally {
                $app->views()->endRequest();
            }
        } finally {
            $project->remove();
        }
    }

    public function testConditionsLoopsAndIncludesWorkInsideSectionsWithoutLeakingLoopState(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/List.squehub.php',
                "@extends('Layouts.List')@section('content')@if(\$showUsers)"
                . "@forelse(\$users as \$user)<li>{{ \$loop->iteration }}:{{ \$user }}</li>"
                . "@empty<empty/>@endforelse@else<hidden/>@endif"
                . "@include('Partials.Tail')@endsection");
            $project->write('Project/Views/Layouts/List.squehub.php',
                "<header>@include('Partials.Head')</header><main>@yield('content')</main>"
                . "<after>{{ isset(\$loop) ? 'stale' : 'clear' }}</after>");
            $project->write('Project/Views/Partials/Head.squehub.php', '{{ $title }}');
            $project->write('Project/Views/Partials/Tail.squehub.php', '<tail>{{ $title }}</tail>');
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('<header>Team</header><main><li>1:&lt;Ada&gt;</li>'
                . '<li>2:Bea</li><tail>Team</tail></main><after>clear</after>',
                $this->render('Pages.List', [
                    'title' => 'Team', 'showUsers' => true, 'users' => ['<Ada>', 'Bea'],
                ]));
            self::assertSame('<header>Team</header><main><empty/><tail>Team</tail></main>'
                . '<after>clear</after>',
                $this->render('Pages.List', [
                    'title' => 'Team', 'showUsers' => true, 'users' => [],
                ]));
            self::assertSame('<header>Team</header><main><hidden/><tail>Team</tail></main>'
                . '<after>clear</after>',
                $this->render('Pages.List', [
                    'title' => 'Team', 'showUsers' => false, 'users' => ['ignored'],
                ]));
        } finally {
            $project->remove();
        }
    }

    /** @dataProvider lineEndings */
    public function testMultilineDirectivesPreserveUtf8SectionContent(string $lineEnding): void
    {
        $project = new TemporaryProject();
        try {
            $page = <<<'TEMPLATE'
@extends(
    'Layouts.Unicode'
)
@section(
    'content'
)こんにちは | مرحبا | Ẹ káàbọ̀@endsection
TEMPLATE;
            $layout = <<<'TEMPLATE'
<main>@yield(
    'content',
    'fallback'
)</main>
TEMPLATE;
            $project->write('Project/Views/Pages/Unicode.squehub.php',
                str_replace("\n", $lineEnding, $page));
            $project->write('Project/Views/Layouts/Unicode.squehub.php',
                str_replace("\n", $lineEnding, $layout));
            RuntimeContext::select(new Application($project->path()));

            self::assertStringContainsString('<main>こんにちは | مرحبا | Ẹ káàbọ̀</main>',
                $this->render('Pages.Unicode'));
        } finally {
            $project->remove();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
    }

    public function testFailuresDuringSectionLayoutNestedLayoutAndIncludeLeaveNextRenderClean(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Main.squehub.php', "<main>@yield('content')</main>");
            $project->write('Project/Views/Layouts/Broken.squehub.php',
                'LAYOUT_PARTIAL{{ $explode() }}');
            $project->write('Project/Views/Layouts/Middle.squehub.php',
                "@extends('Layouts.Broken')@section('middle')MIDDLE@endsection");
            $project->write('Project/Views/Partials/Broken.squehub.php',
                'INCLUDE_PARTIAL{{ $explode() }}');
            $project->write('Project/Views/Pages/Good.squehub.php',
                "@extends('Layouts.Main')@section('content')GOOD@endsection");
            $project->write('Project/Views/Pages/BadSection.squehub.php',
                "@extends('Layouts.Main')@section('content')SECTION_PARTIAL"
                . "{{ \$explode() }}@endsection");
            $project->write('Project/Views/Pages/BadLayout.squehub.php',
                "@extends('Layouts.Broken')@section('content')CHILD@endsection");
            $project->write('Project/Views/Pages/BadNested.squehub.php',
                "@extends('Layouts.Middle')@section('content')CHILD@endsection");
            $project->write('Project/Views/Pages/BadInclude.squehub.php',
                "@extends('Layouts.Main')@section('content')"
                . "@include('Partials.Broken')@endsection");
            RuntimeContext::select(new Application($project->path()));
            $explode = static function (): string {
                throw new \RuntimeException('intentional layout failure');
            };

            foreach (['BadSection', 'BadLayout', 'BadNested', 'BadInclude'] as $name) {
                [$error, $output] = $this->failedRender('Pages.' . $name, ['explode' => $explode]);
                self::assertInstanceOf(\App\View\ViewRenderException::class, $error);
                self::assertSame('intentional layout failure',
                    $error->getPrevious()?->getMessage());
                self::assertStringNotContainsString('intentional layout failure',
                    $error->getMessage());
                self::assertSame('', $output, $name . ' leaked partial output.');
                self::assertSame('<main>GOOD</main>', $this->render('Pages.Good'));
            }
        } finally {
            $project->remove();
        }
    }

    public function testCircularChainsFailSafelyAndTheirStateIsReleased(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Self.squehub.php',
                "@extends('Layouts.Self')SELF_PARTIAL");
            $project->write('Project/Views/Layouts/TwoA.squehub.php',
                "@extends('Layouts.TwoB')A_PARTIAL");
            $project->write('Project/Views/Layouts/TwoB.squehub.php',
                "@extends('Layouts.TwoA')B_PARTIAL");
            $project->write('Project/Views/Layouts/LongA.squehub.php',
                "@extends('Layouts.LongB')A_PARTIAL");
            $project->write('Project/Views/Layouts/LongB.squehub.php',
                "@extends('Layouts.LongC')B_PARTIAL");
            $project->write('Project/Views/Layouts/LongC.squehub.php',
                "@extends('Layouts.LongB')C_PARTIAL");
            $project->write('Project/Views/Layouts/Good.squehub.php', "[@yield('content')]");
            $project->write('Project/Views/Pages/Good.squehub.php',
                "@extends('Layouts.Good')@section('content')OK@endsection");
            foreach (['Self', 'TwoA', 'LongA'] as $name) {
                $project->write('Project/Views/Pages/' . $name . '.squehub.php',
                    "@extends('Layouts.{$name}')@section('content')CHILD_PARTIAL@endsection");
            }
            RuntimeContext::select(new Application($project->path()));

            foreach (['Self', 'TwoA', 'LongA'] as $name) {
                [$error, $output] = $this->failedRender('Pages.' . $name);
                self::assertInstanceOf(\Exception::class, $error);
                self::assertMatchesRegularExpression('/circular/i', $error->getMessage());
                self::assertStringNotContainsString($project->path(), $error->getMessage());
                self::assertSame('', $output, $name . ' leaked partial output.');
                self::assertSame('[OK]', $this->render('Pages.Good'));
            }
        } finally {
            $project->remove();
        }
    }

    public function testMissingParentFailsWithoutOutputAndTheNextRenderSucceeds(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Missing.squehub.php',
                "@extends('Layouts.Absent')@section('content')CHILD_PARTIAL@endsection");
            $project->write('Project/Views/Pages/Good.squehub.php', 'GOOD');
            RuntimeContext::select(new Application($project->path()));

            [$error, $output] = $this->failedRender('Pages.Missing');
            self::assertInstanceOf(\Exception::class, $error);
            self::assertStringNotContainsString($project->path(), $error->getMessage());
            self::assertSame('', $output);
            self::assertSame('GOOD', $this->render('Pages.Good'));
        } finally {
            $project->remove();
        }
    }

    public function testInRootSymlinkAliasCannotBypassCircularDetection(): void
    {
        $project = new TemporaryProject();
        $link = $project->path('Project/Views/Layouts/Alias.squehub.php');
        try {
            $project->write('Project/Views/Layouts/Real.squehub.php',
                "@extends('Layouts.Alias')REAL_PARTIAL");
            if (!@symlink($project->path('Project/Views/Layouts/Real.squehub.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            $project->write('Project/Views/Pages/Alias.squehub.php', "@extends('Layouts.Real')");
            RuntimeContext::select(new Application($project->path()));

            [$error, $output] = $this->failedRender('Pages.Alias');
            self::assertInstanceOf(\Exception::class, $error);
            self::assertMatchesRegularExpression('/circular/i', $error->getMessage());
            self::assertSame('', $output);
        } finally {
            if (is_link($link)) { unlink($link); }
            $project->remove();
        }
    }

    public function testOutsideRootLinkedParentIsUnavailable(): void
    {
        $project = new TemporaryProject();
        $outside = new TemporaryProject();
        $link = $project->path('Project/Views/Layouts/Linked.squehub.php');
        try {
            $project->write('Project/Views/Pages/Linked.squehub.php',
                "@extends('Layouts.Linked')@section('content')CHILD_PARTIAL@endsection");
            $outside->write('Project/Views/Private.squehub.php', 'OUTSIDE_LAYOUT_SECRET');
            if (!@symlink($outside->path('Project/Views/Private.squehub.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            RuntimeContext::select(new Application($project->path()));

            [$error, $output] = $this->failedRender('Pages.Linked');
            self::assertInstanceOf(\Exception::class, $error);
            self::assertSame('', $output);
            self::assertStringNotContainsString('OUTSIDE_LAYOUT_SECRET', $error->getMessage());
            self::assertStringNotContainsString($outside->path(), $error->getMessage());
        } finally {
            if (is_link($link)) { unlink($link); }
            $project->remove();
            $outside->remove();
        }
    }

    public function testLayoutResolutionKeepsCaseCompatibilityAndRefreshesParentAndChildSources(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Base.squehub.php', "[one:@yield('content')]");
            $project->write('Project/Views/Pages/Cache.squehub.php',
                "@extends('layouts.base')@section('content')First@endsection");
            RuntimeContext::select(new Application($project->path()));

            self::assertSame('[one:First]', $this->render('Pages.Cache'));
            $project->write('Project/Views/Layouts/Base.squehub.php', "[two:@yield('content')]");
            self::assertSame('[two:First]', $this->render('Pages.Cache'));
            $project->write('Project/Views/Pages/Cache.squehub.php',
                "@extends('layouts.base')@section('content')Second@endsection");
            self::assertSame('[two:Second]', $this->render('Pages.Cache'));
        } finally {
            $project->remove();
        }
    }

    public function testSectionsAndProvidersAreIsolatedAcrossRequestsInOneApplication(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/Main.squehub.php',
                "<title>@yield('title', 'Default')</title>@yield('content')");
            $project->write('Project/Views/Pages/One.squehub.php',
                "@extends('Layouts.Main')@section('title')One@endsection"
                . "@section('content'){{ \$path }}|{{ \$label }}@endsection");
            $project->write('Project/Views/Pages/Two.squehub.php',
                "@extends('Layouts.Main')@section('content'){{ \$path }}|{{ \$label }}@endsection");
            $app = new Application($project->path());
            RuntimeContext::select($app);
            $calls = 0;
            $app->views()->provide(static function (ViewContext $context) use (&$calls): array {
                ++$calls;
                return ['path' => $context->request()?->path() ?? 'none'];
            });

            $app->views()->beginRequest(new Request('GET', '/first'));
            try {
                self::assertSame('<title>One</title>/first|A',
                    $this->render('Pages.One', ['label' => 'A']));
                self::assertSame('<title>Default</title>/first|B',
                    $this->render('Pages.Two', ['label' => 'B']));
                self::assertSame(1, $calls);
            } finally {
                $app->views()->endRequest();
            }
            $app->views()->beginRequest(new Request('GET', '/second'));
            try {
                self::assertSame('<title>Default</title>/second|C',
                    $this->render('Pages.Two', ['label' => 'C']));
                self::assertSame(2, $calls);
            } finally {
                $app->views()->endRequest();
            }
        } finally {
            $project->remove();
        }
    }

    public function testApplicationsWithSameLogicalNamesKeepSectionsAndComposersSeparate(): void
    {
        $first = new TemporaryProject();
        $second = new TemporaryProject();
        try {
            foreach ([[$first, 'Alpha'], [$second, 'Beta']] as [$project, $label]) {
                $project->write('Project/Views/Pages/Home.squehub.php',
                    "@extends('Layouts.Main')@section('content'){$label}@endsection");
                $project->write('Project/Views/Layouts/Main.squehub.php',
                    "<div>{{ \$appName }}|@yield('content')</div>");
            }
            $appA = new Application($first->path());
            $appB = new Application($second->path());
            $appA->views()->compose('Layouts.Main',
                static fn (ViewContext $context): array => ['appName' => 'App A']);
            $appB->views()->compose('Layouts.Main',
                static fn (ViewContext $context): array => ['appName' => 'App B']);

            RuntimeContext::select($appA);
            self::assertSame('<div>App A|Alpha</div>', $this->render('Pages.Home'));
            RuntimeContext::select($appB);
            self::assertSame('<div>App B|Beta</div>', $this->render('Pages.Home'));
            RuntimeContext::select($appA);
            self::assertSame('<div>App A|Alpha</div>', $this->render('Pages.Home'));
        } finally {
            $first->remove();
            $second->remove();
        }
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data = []): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            View::render($view, $data);
            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) { ob_end_clean(); }
        }
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
        self::assertNotNull($error, 'Expected layout render to fail.');
        return [$error, $output];
    }
}
