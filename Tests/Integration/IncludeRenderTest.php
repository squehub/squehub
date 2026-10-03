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
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises partial scope, render-tree state, and resolver safety with real Views. */
final class IncludeRenderTest extends TestCase
{
    private TemporaryProject $project;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new TemporaryProject();
        $this->application = new Application($this->project->path());
        RuntimeContext::select($this->application);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        parent::tearDown();
    }

    public function testInheritedComposerAndExplicitDataStayInThePartialSubtree(): void
    {
        $this->project->write('Project/Views/Pages/Parent.squehub.php', <<<'TEMPLATE'
{{ $title }}:{{ $mode }}|@include('Partials.Card', ['mode' => 'compact'])|{{ $title }}:{{ $mode }}|@include('Partials.Sibling')
TEMPLATE);
        $this->project->write('Project/Views/Partials/Card.squehub.php',
            '{{ $title }}:{{ $mode }}:{{ $toolbar }}:@include(\'Partials.Child\')');
        $this->project->write('Project/Views/Partials/Child.squehub.php',
            '{{ $title }}:{{ $mode }}');
        $this->project->write('Project/Views/Partials/Sibling.squehub.php',
            '{{ $title }}:{{ $mode }}');

        $providerCalls = 0;
        $this->application->views()->share('title', 'Shared');
        $this->application->views()->provide(
            static function (ViewContext $context) use (&$providerCalls): array {
                ++$providerCalls;
                return ['toolbar' => 'provided'];
            }
        );
        $this->application->views()->compose('Partials.Card',
            static fn (ViewContext $context): array => ['mode' => 'composed']);

        self::assertSame(
            'Parent:full|Parent:compact:provided:Parent:compact|Parent:full|Parent:full',
            $this->render('Pages.Parent', ['title' => 'Parent', 'mode' => 'full'])
        );
        self::assertSame(1, $providerCalls);
    }

    public function testExplicitFalsyValuesOverrideWithoutTruthinessMerging(): void
    {
        $this->project->write('Project/Views/Pages/Values.squehub.php',
            "@include('Partials.Values', ['nullValue' => null, 'falseValue' => false,"
            . " 'zeroValue' => 0, 'emptyValue' => '', 'emptyArray' => []])");
        $this->project->write('Project/Views/Partials/Values.squehub.php', <<<'TEMPLATE'
{{ $nullValue === null ? 'null' : 'wrong' }}:{{ $falseValue === false ? 'false' : 'wrong' }}:{{ $zeroValue === 0 ? 'zero' : 'wrong' }}:{{ $emptyValue === '' ? 'empty' : 'wrong' }}:{{ $emptyArray === [] ? 'array' : 'wrong' }}
TEMPLATE);
        self::assertSame('null:false:zero:empty:array', $this->render('Pages.Values', [
            'nullValue' => 'inherited', 'falseValue' => true,
            'zeroValue' => 9, 'emptyValue' => 'inherited', 'emptyArray' => [1],
        ]));
    }

    public function testSameCompiledPartialRendersTwiceWithDifferentOverlays(): void
    {
        $this->project->write('Project/Views/Pages/Badges.squehub.php',
            "@include('Partials.Badge', ['text' => 'A'])|"
            . "@include('Partials.Badge', ['text' => 'B'])");
        $this->project->write('Project/Views/Partials/Badge.squehub.php',
            '<span>{{ $text }}</span>');

        self::assertSame('<span>A</span>|<span>B</span>', $this->render('Pages.Badges'));
        self::assertSame('<span>A</span>|<span>B</span>', $this->render('Pages.Badges'));
    }

    public function testOptionalMissingSkipsComposerAndOwnedAssetsButPresentRenders(): void
    {
        $this->project->write('Project/Views/Pages/Optional.squehub.php',
            "@stack('styles')|@includeOptional('Partials.Promo')|"
            . "@includeOptional('Partials.Ready', ['label' => 'shown'])");
        $this->project->write('Project/Views/Partials/Ready.squehub.php',
            "@style('/assets/ready.css')<span>{{ \$label }}</span>");
        $promoComposerCalls = 0;
        $readyComposerCalls = 0;
        $this->application->views()->compose('Partials.Promo',
            static function (ViewContext $context) use (&$promoComposerCalls): array {
                ++$promoComposerCalls;
                return [];
            });
        $this->application->views()->compose('Partials.Ready',
            static function (ViewContext $context) use (&$readyComposerCalls): array {
                ++$readyComposerCalls;
                return [];
            });
        View::assets()->for('Partials.Promo')->style('/assets/missing.css');

        $output = $this->render('Pages.Optional');
        self::assertStringContainsString('/assets/ready.css', $output);
        self::assertStringContainsString('<span>shown</span>', $output);
        self::assertStringNotContainsString('/assets/missing.css', $output);
        self::assertSame(0, $promoComposerCalls);
        self::assertSame(1, $readyComposerCalls);

        // No negative existence cache may hide a partial created later.
        $this->project->write('Project/Views/Partials/Promo.squehub.php', '<b>now present</b>');
        self::assertStringContainsString('<b>now present</b>', $this->render('Pages.Optional'));
        self::assertSame(1, $promoComposerCalls);
    }

    public function testOptionalExistingPartialPropagatesCompileAndExecutionFailures(): void
    {
        $this->project->write('Project/Views/Pages/OptionalBroken.squehub.php',
            "@includeOptional('Partials.Broken')");
        $this->project->write('Project/Views/Partials/Broken.squehub.php',
            '@if($flag)<b>broken</b>');
        [$compilerError, $output] = $this->renderFailure('Pages.OptionalBroken');
        self::assertStringContainsString('Partials.Broken', $compilerError->getMessage());
        self::assertSame('', $output);

        $this->project->write('Project/Views/Pages/OptionalThrows.squehub.php',
            "@includeOptional('Partials.Throws')");
        $this->project->write('Project/Views/Partials/Throws.squehub.php',
            "@php throw new \\RuntimeException('partial failed'); @endphp");
        [$runtimeError, $output] = $this->renderFailure('Pages.OptionalThrows');
        self::assertInstanceOf(\RuntimeException::class, $runtimeError);
        self::assertInstanceOf(\App\View\ViewRenderException::class, $runtimeError);
        self::assertSame('Partials.Throws', $runtimeError->view());
        self::assertSame('partial failed', $runtimeError->getPrevious()?->getMessage());
        self::assertStringNotContainsString('partial failed', $runtimeError->getMessage());
        self::assertSame('', $output);
    }

    public function testConditionalFalseSkipsDataComposerAndAssets(): void
    {
        $this->project->write('Project/Views/Pages/Conditional.squehub.php',
            "@stack('styles')|@includeWhen(\$show, 'Partials.Editor', \$makeData())");
        $this->project->write('Project/Views/Partials/Editor.squehub.php',
            "@style('/assets/editor.css')<b>{{ \$label }}</b>");
        $dataCalls = 0;
        $composerCalls = 0;
        $makeData = static function () use (&$dataCalls): array {
            ++$dataCalls;
            return ['label' => 'Editor'];
        };
        $this->application->views()->compose('Partials.Editor',
            static function (ViewContext $context) use (&$composerCalls): array {
                ++$composerCalls;
                return [];
            });

        self::assertSame('|', $this->render('Pages.Conditional',
            ['show' => false, 'makeData' => $makeData]));
        self::assertSame(0, $dataCalls);
        self::assertSame(0, $composerCalls);

        $shown = $this->render('Pages.Conditional',
            ['show' => true, 'makeData' => $makeData]);
        self::assertStringContainsString('/assets/editor.css', $shown);
        self::assertStringContainsString('<b>Editor</b>', $shown);
        self::assertSame(1, $dataCalls);
        self::assertSame(1, $composerCalls);
    }

    public function testNestedIncludesPreserveActiveLoopAndInnerLoopParent(): void
    {
        $this->project->write('Project/Views/Pages/Rows.squehub.php', <<<'TEMPLATE'
@foreach($rows as $row)
@include('Partials.Row', ['loop' => 'fake'])
@endforeach
TEMPLATE);
        $this->project->write('Project/Views/Partials/Row.squehub.php', <<<'TEMPLATE'
<row>{{ $loop->iteration }}:{{ $row['name'] }}</row>
@include('Partials.Deep', ['loop' => 'other'])
@foreach($row['details'] as $detail)
<detail>{{ $loop->parent->iteration }}:{{ $loop->iteration }}:{{ $detail }}</detail>
@endforeach
TEMPLATE);
        $this->project->write('Project/Views/Partials/Deep.squehub.php',
            '<deep>{{ $loop->iteration }}</deep>');

        $output = $this->render('Pages.Rows', [
            'rows' => [
                ['name' => '<A>', 'details' => ['x', 'y']],
                ['name' => 'B', 'details' => ['z']],
            ],
        ]);
        self::assertSame(
            '<row>1:&lt;A&gt;</row><deep>1</deep><detail>1:1:x</detail>'
            . '<detail>1:2:y</detail><row>2:B</row><deep>2</deep><detail>2:1:z</detail>',
            self::withoutTagWhitespace($output)
        );
    }

    public function testLayoutSectionAndRepeatedPartialKeepAssetOwnership(): void
    {
        $this->project->write('Project/Views/Layouts/Base.squehub.php',
            "<head>@stack('styles')</head><main>@yield('content')</main>"
            . "<footer>@stack('scripts')</footer>");
        $this->project->write('Project/Views/Pages/Editor.squehub.php',
            "@extends('Layouts.Base')@section('content')"
            . "@include('Partials.Editor')@include('Partials.Editor')@endsection");
        $this->project->write('Project/Views/Partials/Editor.squehub.php',
            "@style('/assets/editor.css')@script('/assets/editor.js')"
            . "@push('head')<meta name=\"editor\">@endpush"
            . '<article>{{ $title }}</article>');

        $output = $this->render('Pages.Editor', ['title' => '<Editor>']);
        self::assertSame(1, substr_count($output, '/assets/editor.css'));
        self::assertSame(1, substr_count($output, '/assets/editor.js'));
        self::assertSame(2, substr_count($output, '<article>&lt;Editor&gt;</article>'));
        self::assertStringNotContainsString('@include', $output);
    }

    public function testRequiredMissingAndInvalidDataFailWithoutLeakingOutput(): void
    {
        $this->project->write('Project/Views/Pages/Missing.squehub.php',
            "BEFORE\n@include('Partials.Absent')\nAFTER");
        [$missing, $output] = $this->renderFailure('Pages.Missing');
        self::assertStringContainsString('Partials.Absent', $missing->getMessage());
        self::assertSame('', $output);

        $this->project->write('Project/Views/Partials/Existing.squehub.php', 'existing');
        $this->project->write('Project/Views/Pages/InvalidData.squehub.php',
            "@include('Partials.Existing', 'INCLUDE_SECRET')");
        [$invalid, $output] = $this->renderFailure('Pages.InvalidData');
        self::assertStringNotContainsString('INCLUDE_SECRET', $invalid->getMessage());
        self::assertSame('', $output);
    }

    public function testInvalidIncludeDataKeyFailsAndReservedValuesCannotReplaceRuntimeValues(): void
    {
        $this->project->write('Project/Views/Partials/Protected.squehub.php',
            "{{ \$errors->any() ? 'bad' : 'clean' }}");
        $this->project->write('Project/Views/Pages/Protected.squehub.php',
            "@include('Partials.Protected', ['errors' => 'fake',"
            . " '__squehub_layoutState' => 'fake'])");
        self::assertSame('clean', $this->render('Pages.Protected'));

        $this->project->write('Project/Views/Pages/BadKey.squehub.php',
            "@include('Partials.Protected', ['bad-key' => 'INCLUDE_SECRET'])");
        [$invalid, $output] = $this->renderFailure('Pages.BadKey');
        self::assertStringNotContainsString('INCLUDE_SECRET', $invalid->getMessage());
        self::assertSame('', $output);
    }

    public function testDirectAndLongerIncludeCyclesFailButSequentialReuseWorks(): void
    {
        $this->project->write('Project/Views/Partials/Direct.squehub.php',
            "DIRECT_START\n@include('Partials.Direct')");
        $this->project->write('Project/Views/Pages/Direct.squehub.php',
            "@include('Partials.Direct')");
        [$direct, $output] = $this->renderFailure('Pages.Direct');
        self::assertStringContainsString('Circular include', $direct->getMessage());
        self::assertStringContainsString('Partials.Direct', $direct->getMessage());
        self::assertSame('', $output);

        $this->project->write('Project/Views/Pages/Long.squehub.php',
            "@include('Partials.A')");
        $this->project->write('Project/Views/Partials/A.squehub.php',
            "@include('Partials.B')");
        $this->project->write('Project/Views/Partials/B.squehub.php',
            "@include('Partials.C')");
        $this->project->write('Project/Views/Partials/C.squehub.php',
            "@include('Partials.B')");
        [$long, $output] = $this->renderFailure('Pages.Long');
        self::assertStringContainsString('Circular include', $long->getMessage());
        self::assertStringContainsString('Partials.B', $long->getMessage());
        self::assertSame('', $output);

        $this->project->write('Project/Views/Pages/Repeated.squehub.php',
            "@include('Partials.Badge')@include('Partials.Badge')");
        $this->project->write('Project/Views/Partials/Badge.squehub.php', '[badge]');
        self::assertSame('[badge][badge]', $this->render('Pages.Repeated'));
    }

    public function testIncludingActivePageOrLayoutIdentityFailsAndNextRenderIsClean(): void
    {
        $this->project->write('Project/Views/Pages/PageCycle.squehub.php',
            "@include('Pages.PageCycle')");
        $this->project->write('Project/Views/Pages/LayoutCycle.squehub.php',
            "@extends('Layouts.Self')@section('content')body@endsection");
        $this->project->write('Project/Views/Layouts/Self.squehub.php',
            "@include('Layouts.Self')@yield('content')");
        $this->project->write('Project/Views/Pages/Healthy.squehub.php',
            '<main>healthy</main>');

        foreach (['Pages.PageCycle', 'Pages.LayoutCycle'] as $view) {
            [$error, $output] = $this->renderFailure($view);
            self::assertStringContainsString('Circular include', $error->getMessage());
            self::assertSame('', $output);
            self::assertSame('<main>healthy</main>', $this->render('Pages.Healthy'));
        }
    }

    public function testFailedPartialTreeDoesNotLeakAssetsSectionsOrIncludeState(): void
    {
        $this->project->write('Project/Views/Pages/Bad.squehub.php',
            "@stack('styles')@include('Partials.Bad')");
        $this->project->write('Project/Views/Partials/Bad.squehub.php',
            "@style('/assets/failed.css')@section('failed')failed@endsection"
            . "@include('Partials.Bad')");
        $this->project->write('Project/Views/Pages/Good.squehub.php',
            "@stack('styles')|@yield('failed', 'none')|@include('Partials.Good')");
        $this->project->write('Project/Views/Partials/Good.squehub.php', 'healthy');

        [$error, $output] = $this->renderFailure('Pages.Bad');
        self::assertStringContainsString('Circular include', $error->getMessage());
        self::assertSame('', $output);
        self::assertSame('|none|healthy', $this->render('Pages.Good'));
    }

    public function testPartialMayNotDeclareItsOwnLayout(): void
    {
        $this->project->write('Project/Views/Pages/BadLayout.squehub.php',
            "@include('Partials.BadLayout')");
        $this->project->write('Project/Views/Partials/BadLayout.squehub.php',
            "@extends('Layouts.Other')");
        $this->project->write('Project/Views/Layouts/Other.squehub.php', 'other');

        [$error, $output] = $this->renderFailure('Pages.BadLayout');
        self::assertStringContainsString('included View', $error->getMessage());
        self::assertSame('', $output);
    }

    public function testOptionalAbsenceAndBrokenFileLinkHaveDifferentOutcomes(): void
    {
        $this->project->write('Project/Views/Pages/OptionalLink.squehub.php',
            "@includeOptional('Partials.Linked')");
        self::assertSame('', $this->render('Pages.OptionalLink'));

        $link = $this->project->path('Project/Views/Partials/Linked.squehub.php');
        if (!is_dir(dirname($link))) {
            mkdir(dirname($link), 0777, true);
        }
        if (!@symlink($this->project->path('Project/Views/Partials/Absent.squehub.php'), $link)) {
            self::markTestSkipped('File symlinks are unavailable to this test process.');
        }
        try {
            [$error, $output] = $this->renderFailure('Pages.OptionalLink');
            self::assertSame('', $output);
            self::assertStringNotContainsString($link, $error->getMessage());
        } finally {
            unlink($link);
        }
    }

    public function testStandaloneOptionalAbsenceLeavesOutputBufferDepthUnchanged(): void
    {
        $before = ob_get_level();
        ob_start();
        try {
            $outer = ob_get_level();
            View::include('Partials.Missing', [], false, [], null, true);
            self::assertSame($outer, ob_get_level());
            self::assertSame('', (string) ob_get_contents());
        } finally {
            ob_end_clean();
        }
        self::assertSame($before, ob_get_level());
    }

    public function testOptionalPackagePartialTracksEnabledApplicationWithoutNegativeCache(): void
    {
        $this->project->write('Project/Views/Pages/PackageOptional.squehub.php',
            "@stack('styles')|@includeOptional('Addon')");
        $this->project->write('Project/Packages/OptionalAddon/OptionalAddon.php',
            '<?php namespace Packages\\OptionalAddon; final class OptionalAddon extends '
            . '\\App\\Plugins\\ServiceProvider {}');
        $this->project->write('Project/Packages/OptionalAddon/Views/Addon.squehub.php',
            "@style('/assets/optional-addon.css')<b>package</b>");

        $disabled = new Application($this->project->path());
        $disabled->bootstrap();
        RuntimeContext::select($disabled);
        self::assertSame('|', $this->render('Pages.PackageOptional'));

        $manager = $disabled->container()->make(PackageManager::class);
        self::assertTrue($manager->apply($manager->planEnable('OptionalAddon'))->complete());
        $enabled = new Application($this->project->path());
        $enabled->bootstrap();
        RuntimeContext::select($enabled);
        $shown = $this->render('Pages.PackageOptional');
        self::assertStringContainsString('/assets/optional-addon.css', $shown);
        self::assertStringContainsString('<b>package</b>', $shown);

        RuntimeContext::select($disabled);
        self::assertSame('|', $this->render('Pages.PackageOptional'));
    }

    public function testInRootFileLinkRendersAndPhysicalAliasCycleIsDetected(): void
    {
        $this->project->write('Project/Views/Partials/Actual.squehub.php', '<b>inside</b>');
        $this->project->write('Project/Views/Pages/Linked.squehub.php',
            "@include('Partials.Alias')");
        $alias = $this->project->path('Project/Views/Partials/Alias.squehub.php');
        if (!@symlink($this->project->path('Project/Views/Partials/Actual.squehub.php'), $alias)) {
            self::markTestSkipped('File symlinks are unavailable to this test process.');
        }
        try {
            self::assertSame('<b>inside</b>', $this->render('Pages.Linked'));
            $this->project->write('Project/Views/Partials/Actual.squehub.php',
                "@include('Partials.Alias')");
            [$error, $output] = $this->renderFailure('Pages.Linked');
            self::assertStringContainsString('Circular include', $error->getMessage());
            self::assertSame('', $output);
        } finally {
            unlink($alias);
        }
    }

    public function testOptionalOutsideRootLinkFailsAndNeverCompilesOutsideSource(): void
    {
        $outside = new TemporaryProject();
        $link = $this->project->path('Project/Views/Partials/Outside.squehub.php');
        try {
            $outside->write('Outside.squehub.php', 'OUTSIDE_INCLUDE_SECRET');
            $this->project->write('Project/Views/Pages/Outside.squehub.php',
                "@includeOptional('Partials.Outside')");
            if (!is_dir(dirname($link))) {
                mkdir(dirname($link), 0777, true);
            }
            if (!@symlink($outside->path('Outside.squehub.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            [$error, $output] = $this->renderFailure('Pages.Outside');
            self::assertSame('', $output);
            self::assertStringNotContainsString('OUTSIDE_INCLUDE_SECRET', $error->getMessage());
            self::assertStringNotContainsString($outside->path(), $error->getMessage());
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            $outside->remove();
        }
    }

    public function testPreviouslyCompiledPartialCannotExecuteAfterUnsafeSourceSwap(): void
    {
        $outside = new TemporaryProject();
        $partial = $this->project->path('Project/Views/Partials/Swapped.squehub.php');
        try {
            $this->project->write('Project/Views/Pages/Swapped.squehub.php',
                "@include('Partials.Swapped')");
            $this->project->write('Project/Views/Partials/Swapped.squehub.php', 'safe');
            $outside->write('Outside.squehub.php', 'OUTSIDE_INCLUDE_SECRET');
            self::assertSame('safe', $this->render('Pages.Swapped'));
            unlink($partial);
            if (!@symlink($outside->path('Outside.squehub.php'), $partial)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            clearstatcache(true, $partial);
            [$error, $output] = $this->renderFailure('Pages.Swapped');
            self::assertSame('', $output);
            self::assertStringNotContainsString('OUTSIDE_INCLUDE_SECRET', $error->getMessage());
        } finally {
            if (is_link($partial)) {
                unlink($partial);
            }
            $outside->remove();
        }
    }

    public function testIncludeOverlayIsolatedAcrossRequestsAndApplications(): void
    {
        $this->project->write('Project/Views/Pages/Scope.squehub.php',
            "@include('Partials.Scope', ['mode' => \$mode])");
        $this->project->write('Project/Views/Partials/Scope.squehub.php',
            '{{ $mode }}:{{ $fromComposer }}');
        $this->application->views()->compose('Partials.Scope',
            static fn (ViewContext $context): array => ['fromComposer' => 'A']);

        $this->application->views()->beginRequest(new Request('GET', '/one'));
        try {
            self::assertSame('one:A', $this->render('Pages.Scope', ['mode' => 'one']));
        } finally {
            $this->application->views()->endRequest();
        }
        $this->application->views()->beginRequest(new Request('GET', '/two'));
        try {
            self::assertSame('two:A', $this->render('Pages.Scope', ['mode' => 'two']));
        } finally {
            $this->application->views()->endRequest();
        }

        $other = new TemporaryProject();
        try {
            $other->write('Project/Views/Pages/Scope.squehub.php',
                "@include('Partials.Scope', ['mode' => \$mode])");
            $other->write('Project/Views/Partials/Scope.squehub.php',
                '{{ $mode }}:{{ $fromComposer }}');
            $otherApp = new Application($other->path());
            $otherApp->views()->compose('Partials.Scope',
                static fn (ViewContext $context): array => ['fromComposer' => 'B']);
            RuntimeContext::select($otherApp);
            self::assertSame('other:B', $this->render('Pages.Scope', ['mode' => 'other']));
            RuntimeContext::select($this->application);
            self::assertSame('again:A', $this->render('Pages.Scope', ['mode' => 'again']));
        } finally {
            $other->remove();
        }
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

    private static function withoutTagWhitespace(string $html): string
    {
        return (string) preg_replace('/>\s+</', '><', trim($html));
    }
}
