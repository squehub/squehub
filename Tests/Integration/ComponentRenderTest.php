<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Http\Request;
use App\Plugins\View;
use App\Plugins\ViewContext;
use App\Support\RuntimeContext;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises component scope and composition through real compiled Views. */
final class ComponentRenderTest extends TestCase
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

    public function testDeclaredPropsDefaultSlotAndAttributesRenderWithoutInheritedSecrets(): void
    {
        $this->project->write('Project/Views/Pages/Profile.squehub.php', <<<'TEMPLATE'
@component('Card', ['title' => $title], ['class' => 'primary'])
    <p>{{ $message }}</p>
@endcomponent
TEMPLATE);
        $this->project->write('Project/Views/Components/Card.squehub.php', <<<'TEMPLATE'
@props(['title', 'variant' => 'default'])
<div {!! $attributes->merge(['class' => 'card'])->toHtml() !!}>
<h2>{{ $title }}</h2><i>{{ $variant }}</i>
<scope>{{ isset($message) ? 'leaked' : 'isolated' }}</scope>
{!! $slot->toHtml() !!}
</div>
TEMPLATE);

        $output = $this->render('Pages.Profile', [
            'title' => '<Profile>', 'message' => '<script>unsafe</script>',
        ]);
        self::assertStringContainsString('class="primary"', $output);
        self::assertStringContainsString('<h2>&lt;Profile&gt;</h2>', $output);
        self::assertStringContainsString('<i>default</i>', $output);
        self::assertStringContainsString('<scope>isolated</scope>', $output);
        self::assertStringContainsString('<p>&lt;script&gt;unsafe&lt;/script&gt;</p>', $output);
        self::assertStringNotContainsString('&amp;lt;script', $output);
    }

    public function testPropDefaultsDoNotReplaceExplicitFalsyValues(): void
    {
        $this->project->write('Project/Views/Components/Value.squehub.php',
            '@props([\'value\' => \'fallback\'])<v>{{ json_encode($value) }}</v>');
        $this->project->write('Project/Views/Pages/Values.squehub.php', <<<'TEMPLATE'
@component('Value', ['value' => null])@endcomponent
@component('Value', ['value' => false])@endcomponent
@component('Value', ['value' => 0])@endcomponent
@component('Value', ['value' => ''])@endcomponent
@component('Value', ['value' => []])@endcomponent
@component('Value')@endcomponent
TEMPLATE);
        self::assertSame(
            '<v>null</v><v>false</v><v>0</v><v>&quot;&quot;</v>'
            . '<v>[]</v><v>&quot;fallback&quot;</v>',
            self::withoutTagWhitespace($this->render('Pages.Values'))
        );
    }

    public function testMissingUnknownAndUndeclaredPropsFailWithoutValuesInErrors(): void
    {
        $this->project->write('Project/Views/Components/Card.squehub.php',
            "@props(['title'])<h2>{{ \$title }}</h2>");
        $this->project->write('Project/Views/Components/NoProps.squehub.php',
            '<span>plain</span>');
        $sources = [
            'Missing' => "@component('Card')@endcomponent",
            'Unknown' => "@component('Card', ['title' => 'ok', "
                . "'titel' => 'COMPONENT_PROP_SECRET'])@endcomponent",
            'Undeclared' => "@component('NoProps', ['secret' => 'COMPONENT_PROP_SECRET'])"
                . '@endcomponent',
        ];
        foreach ($sources as $name => $source) {
            $this->project->write("Project/Views/Pages/{$name}.squehub.php", $source);
            [$error, $output] = $this->renderFailure("Pages.{$name}");
            self::assertSame('', $output);
            self::assertStringNotContainsString('COMPONENT_PROP_SECRET', $error->getMessage());
            self::assertStringContainsString(
                $name === 'Undeclared' ? 'NoProps' : 'Card', $error->getMessage());
        }
    }

    public function testReservedAndInvalidPropDeclarationsFailClearly(): void
    {
        foreach (['slot', 'slots', 'attributes', 'errors', 'loop',
            'GLOBALS', 'this', '__squehub_state', 'bad-key'] as $name) {
            $this->project->write('Project/Views/Components/InvalidSchema.squehub.php',
                "@props(['{$name}'])<b>should not render</b>");
            $this->project->write('Project/Views/Pages/InvalidSchema.squehub.php',
                "@component('InvalidSchema')@endcomponent");
            [$error, $output] = $this->renderFailure('Pages.InvalidSchema');
            self::assertSame('', $output);
            self::assertStringContainsString('Components.InvalidSchema', $error->getMessage());
            self::assertStringContainsString('invalid or reserved', $error->getMessage());
        }
    }

    public function testPropsAndAttributesExpressionsRunOnceAndFalseBranchRunsNone(): void
    {
        $this->project->write('Project/Views/Components/Counter.squehub.php',
            '@props([\'title\'])<b {!! $attributes->toHtml() !!}>{{ $title }}</b>'
            . '{!! $slot->toHtml() !!}');
        $this->project->write('Project/Views/Pages/Counter.squehub.php', <<<'TEMPLATE'
@if($show)
    @component('Counter', $makeProps(), $makeAttributes())
        @php $slotTick(); @endphp
        <span>slot</span>
    @endcomponent
@endif
TEMPLATE);
        $propsCalls = $attributeCalls = $slotCalls = 0;
        $data = [
            'makeProps' => static function () use (&$propsCalls): array {
                ++$propsCalls;
                return ['title' => 'counted'];
            },
            'makeAttributes' => static function () use (&$attributeCalls): array {
                ++$attributeCalls;
                return ['data-count' => 'once'];
            },
            'slotTick' => static function () use (&$slotCalls): void {
                ++$slotCalls;
            },
        ];

        self::assertSame('', $this->render('Pages.Counter', ['show' => false] + $data));
        self::assertSame([0, 0, 0], [$propsCalls, $attributeCalls, $slotCalls]);
        $output = $this->render('Pages.Counter', ['show' => true] + $data);
        self::assertStringContainsString('data-count="once"', $output);
        self::assertStringContainsString('<span>slot</span>', $output);
        self::assertSame([1, 1, 1], [$propsCalls, $attributeCalls, $slotCalls]);
    }

    public function testDefaultAndNamedSlotsCaptureOnceInCallerScope(): void
    {
        $this->project->write('Project/Views/Pages/Slots.squehub.php', <<<'TEMPLATE'
@component('Repeat')
    @php $tick(); @endphp
    <p>{{ $callerValue }}</p>
    @slot('footer')
        @php $tick(); @endphp
        <b>{{ $callerValue }}</b>
    @endslot
@endcomponent
TEMPLATE);
        $this->project->write('Project/Views/Components/Repeat.squehub.php', <<<'TEMPLATE'
<scope>{{ isset($callerValue) ? 'leaked' : 'isolated' }}</scope>
<default>{!! $slot->toHtml() !!}|{!! $slot->toHtml() !!}</default>
<named>{!! $slots->get('footer')->toHtml() !!}|{!! $slots->get('footer')->toHtml() !!}</named>
<missing>{{ $slots->has('absent') ? 'bad' : 'none' }}</missing>
TEMPLATE);
        $ticks = 0;
        $output = $this->render('Pages.Slots', [
            'callerValue' => '<caller>',
            'tick' => static function () use (&$ticks): void { ++$ticks; },
        ]);
        self::assertSame(2, $ticks);
        self::assertStringContainsString('<scope>isolated</scope>', $output);
        self::assertSame(2, substr_count($output, '<p>&lt;caller&gt;</p>'));
        self::assertSame(2, substr_count($output, '<b>&lt;caller&gt;</b>'));
        self::assertStringContainsString('<missing>none</missing>', $output);
        self::assertStringNotContainsString('&amp;lt;caller', $output);
    }

    public function testEmptyDefaultSlotAndNestedNamedSlotsBelongToNearestComponent(): void
    {
        $this->project->write('Project/Views/Pages/Nested.squehub.php', <<<'TEMPLATE'
@component('Card')
    @slot('footer')
        @component('Icon', ['name' => 'close'])
            @slot('title')Nested title@endslot
        @endcomponent
    @endslot
@endcomponent
TEMPLATE);
        $this->project->write('Project/Views/Components/Card.squehub.php', <<<'TEMPLATE'
<card><empty>{{ $slot->toHtml() === '' ? 'yes' : 'no' }}</empty>
<footer>{!! $slots->get('footer')->toHtml() !!}</footer></card>
TEMPLATE);
        $this->project->write('Project/Views/Components/Icon.squehub.php', <<<'TEMPLATE'
@props(['name'])
<icon>{{ $name }}:{!! $slots->get('title')->toHtml() !!}</icon>
TEMPLATE);

        $output = self::withoutTagWhitespace($this->render('Pages.Nested'));
        self::assertStringContainsString('<empty>yes</empty>', $output);
        self::assertStringContainsString('<icon>close:Nested title</icon>', $output);
    }

    public function testAttributesStaySeparateAndEscapedInRenderedHtml(): void
    {
        $this->project->write('Project/Views/Pages/Button.squehub.php', <<<'TEMPLATE'
@component(
    'Button',
    ['label' => 'Save'],
    ['class' => 'primary', 'data-action' => $unsafe, 'aria-label' => 'Save', 'disabled' => true]
)
@endcomponent
TEMPLATE);
        $this->project->write('Project/Views/Components/Button.squehub.php',
            "@props(['label'])<button {!! \$attributes->merge(['class' => 'base'])->toHtml() !!}>"
            . '{{ $label }}</button>');

        $output = $this->render('Pages.Button', [
            'unsafe' => 'save" onmouseover="ATTRIBUTE_SECRET',
        ]);
        self::assertStringContainsString('class="primary"', $output);
        self::assertStringContainsString('data-action="save&quot; onmouseover=&quot;ATTRIBUTE_SECRET"',
            $output);
        self::assertStringContainsString('aria-label="Save"', $output);
        self::assertMatchesRegularExpression('/(?:^|\s)disabled(?:\s|>)/', $output);
        self::assertStringNotContainsString('onmouseover="ATTRIBUTE_SECRET"', $output);
        self::assertStringContainsString('>Save</button>', $output);
    }

    public function testActiveLoopCrossesCallerSlotsAndComponentTemplate(): void
    {
        $this->project->write('Project/Views/Pages/Rows.squehub.php', <<<'TEMPLATE'
@foreach($rows as $row)
    @component('Row', ['row' => $row])
        <slot-position>{{ $loop->iteration }}</slot-position>
    @endcomponent
@endforeach
TEMPLATE);
        $this->project->write('Project/Views/Components/Row.squehub.php', <<<'TEMPLATE'
@props(['row'])
<row>{{ $loop->iteration }}:{{ $row['name'] }}:{!! $slot->toHtml() !!}</row>
@foreach($row['children'] as $child)
    <child>{{ $loop->parent->iteration }}:{{ $loop->iteration }}:{{ $child }}</child>
@endforeach
TEMPLATE);
        $output = self::withoutTagWhitespace($this->render('Pages.Rows', [
            'rows' => [
                ['name' => '<A>', 'children' => ['x', 'y']],
                ['name' => 'B', 'children' => ['z']],
            ],
        ]));
        self::assertMatchesRegularExpression(
            '~<row>1:&lt;A&gt;:\\s*<slot-position>1</slot-position></row>~', $output);
        self::assertStringContainsString('<child>1:1:x</child><child>1:2:y</child>', $output);
        self::assertMatchesRegularExpression(
            '~<row>2:B:\\s*<slot-position>2</slot-position></row>~', $output);
        self::assertStringContainsString('<child>2:1:z</child>', $output);
    }

    public function testComponentInsidePartialAndPartialInsideComponentKeepSeparateScopes(): void
    {
        $this->project->write('Project/Views/Pages/Scope.squehub.php',
            "@include('Partials.Caller')|@component('Card', ['title' => \$title])"
            . '{{ $secret }}@endcomponent');
        $this->project->write('Project/Views/Partials/Caller.squehub.php',
            "<partial>{{ \$secret }}</partial>"
            . "@component('Card', ['title' => \$title])@endcomponent");
        $this->project->write('Project/Views/Components/Card.squehub.php', <<<'TEMPLATE'
@props(['title'])
<card>{{ $title }}:{{ isset($secret) ? 'leak' : 'isolated' }}:{{ isset($sharedSecret) ? 'leak' : 'isolated' }}:{{ isset($injected) ? 'leak' : 'isolated' }}:{!! $slot->toHtml() !!}</card>
@include('Partials.ComponentHelp')
TEMPLATE);
        $this->project->write('Project/Views/Partials/ComponentHelp.squehub.php',
            '<help>{{ $title }}:{{ isset($secret) ? \'leak\' : \'isolated\' }}</help>');
        $this->application->views()->share('sharedSecret', 'SHARED_SECRET');
        $this->application->views()->compose('Components.Card',
            static fn (ViewContext $context): array => ['injected' => 'COMPOSER_SECRET']);

        $output = $this->render('Pages.Scope', [
            'title' => 'Profile', 'secret' => 'CALLER_SECRET',
        ]);
        self::assertStringContainsString('<partial>CALLER_SECRET</partial>', $output);
        self::assertSame(2, substr_count($output,
            '<card>Profile:isolated:isolated:isolated:'));
        self::assertStringContainsString(
            '<card>Profile:isolated:isolated:isolated:CALLER_SECRET</card>', $output);
        self::assertSame(2, substr_count($output, '<help>Profile:isolated</help>'));
    }

    public function testComponentInLayoutSectionAndPushBlockRendersOnce(): void
    {
        $this->project->write('Project/Views/Layouts/App.squehub.php',
            "<head>@stack('head')</head><main>@yield('content')|@yield('content')</main>");
        $this->project->write('Project/Views/Pages/Layout.squehub.php',
            "@extends('Layouts.App')@section('content')"
            . "@push('head')@component('Badge', ['text' => 'head'])@endcomponent@endpush"
            . "@component('Badge', ['text' => 'body'])@endcomponent@endsection");
        $this->project->write('Project/Views/Components/Badge.squehub.php',
            "@props(['text'])<badge>{{ \$text }}</badge>");

        $output = $this->render('Pages.Layout');
        self::assertSame(1, substr_count($output, '<badge>head</badge>'));
        self::assertSame(2, substr_count($output, '<badge>body</badge>'));
    }

    public function testComponentCannotDeclareLayoutOrPageSection(): void
    {
        $this->project->write('Project/Views/Pages/BadLayout.squehub.php',
            "@component('BadLayout')@endcomponent");
        $this->project->write('Project/Views/Components/BadLayout.squehub.php',
            "@extends('Layouts.Other')");
        $this->project->write('Project/Views/Layouts/Other.squehub.php', 'other');
        [$layoutError, $output] = $this->renderFailure('Pages.BadLayout');
        self::assertSame('', $output);
        self::assertStringContainsString('component', strtolower($layoutError->getMessage()));

        $this->project->write('Project/Views/Pages/BadSection.squehub.php',
            "@component('BadSection')@endcomponent");
        $this->project->write('Project/Views/Components/BadSection.squehub.php',
            "@section('content')bad@endsection");
        [$sectionError, $output] = $this->renderFailure('Pages.BadSection');
        self::assertSame('', $output);
        self::assertStringContainsString('section', strtolower($sectionError->getMessage()));
    }

    public function testRepeatedChartOwnsOneResourceAndExternalRegistrationRequiresParticipation(): void
    {
        $this->project->write('Project/Views/Pages/Charts.squehub.php', <<<'TEMPLATE'
<head>@stack('styles')@stack('head')</head>
@foreach($reports as $report)
    @component('Chart', ['report' => $report])
    @endcomponent
@endforeach
@stack('scripts')
TEMPLATE);
        $this->project->write('Project/Views/Components/Chart.squehub.php', <<<'TEMPLATE'
@props(['report'])
@style('/assets/components/chart.css', once: 'chart-style')
@script('/assets/components/chart.js', once: 'chart-runtime')
@push('head', once: 'chart-head')<meta name="chart">@endpush
<chart>{{ $report }}</chart>
TEMPLATE);
        View::assets()->for('Components.Chart')->script('/assets/chart-extra.js');
        $empty = $this->render('Pages.Charts', ['reports' => []]);
        self::assertStringNotContainsString('/assets/chart-extra.js', $empty);
        $output = $this->render('Pages.Charts', ['reports' => range(1, 20)]);
        self::assertSame(20, substr_count($output, '<chart>'));
        foreach (['chart.css', 'chart.js', 'chart-extra.js', '<meta name="chart">'] as $asset) {
            self::assertSame(1, substr_count($output, $asset));
        }
    }

    public function testAssetOrderReservesParentComponentBeforeSlotDescendant(): void
    {
        $this->project->write('Project/Views/Layouts/Base.squehub.php',
            "<head>@stack('styles')</head>@yield('content')");
        $this->project->write('Project/Views/Pages/Assets.squehub.php',
            "@extends('Layouts.Base')@section('content')"
            . "@style('/assets/page.css')@component('Card')"
            . "@style('/assets/slot-page.css')"
            . "@component('Icon')@endcomponent@endcomponent@endsection");
        $this->project->write('Project/Views/Components/Card.squehub.php',
            "@style('/assets/card.css')<card>{!! \$slot->toHtml() !!}</card>");
        $this->project->write('Project/Views/Components/Icon.squehub.php',
            "@style('/assets/icon.css')<icon>icon</icon>");
        $this->project->write('Project/Views/Layouts/Base.squehub.php',
            "<head>@stack('styles')</head>@style('/assets/base.css')@yield('content')");

        $output = $this->render('Pages.Assets');
        $positions = [];
        foreach (['base.css', 'page.css', 'slot-page.css',
            'card.css', 'icon.css'] as $asset) {
            $position = strpos($output, $asset);
            self::assertNotFalse($position);
            $positions[] = $position;
        }
        $ordered = $positions;
        sort($ordered);
        self::assertSame($ordered, $positions);
        self::assertStringContainsString('<card><icon>icon</icon></card>',
            self::withoutTagWhitespace($output));
    }

    public function testSameComponentUsesRuntimePropsSlotsAndAttributesWithoutCacheSecrets(): void
    {
        $probe = 'component-cache-probe-' . bin2hex(random_bytes(6));
        $this->project->write('Project/Views/Pages/Runtime.squehub.php', <<<'TEMPLATE'
@component('Runtime', ['title' => $title], ['data-secret' => $attribute])
    {{ $body }}
@endcomponent
TEMPLATE);
        $this->project->write('Project/Views/Components/Runtime.squehub.php',
            "@props(['title'])<?php /* {$probe} */ ?>"
            . '<div {!! $attributes->toHtml() !!}>{{ $title }}:{!! $slot->toHtml() !!}</div>');

        $first = $this->render('Pages.Runtime', [
            'title' => 'COMPONENT_PROP_SECRET_A',
            'attribute' => 'ATTRIBUTE_SECRET_A',
            'body' => 'SLOT_SECRET_A',
        ]);
        $second = $this->render('Pages.Runtime', [
            'title' => 'COMPONENT_PROP_SECRET_B',
            'attribute' => 'ATTRIBUTE_SECRET_B',
            'body' => 'SLOT_SECRET_B',
        ]);
        self::assertStringContainsString('COMPONENT_PROP_SECRET_A', $first);
        self::assertStringContainsString('COMPONENT_PROP_SECRET_B', $second);
        self::assertStringNotContainsString('COMPONENT_PROP_SECRET_A', $second);

        $compiled = [];
        foreach (glob($this->project->path('Storage/Views/*.php')) ?: [] as $file) {
            $source = file_get_contents($file);
            if (is_string($source) && str_contains($source, $probe)) {
                $compiled[] = $source;
            }
        }
        self::assertCount(1, $compiled);
        foreach (['COMPONENT_PROP_SECRET_A', 'COMPONENT_PROP_SECRET_B',
            'ATTRIBUTE_SECRET_A', 'ATTRIBUTE_SECRET_B',
            'SLOT_SECRET_A', 'SLOT_SECRET_B'] as $secret) {
            self::assertStringNotContainsString($secret, $compiled[0]);
        }
    }

    public function testMixedCyclesFailAndAHealthyRenderStartsClean(): void
    {
        $this->project->write('Project/Views/Pages/Direct.squehub.php',
            "@component('Direct')@endcomponent");
        $this->project->write('Project/Views/Components/Direct.squehub.php',
            "@component('Direct')@endcomponent");
        $this->project->write('Project/Views/Pages/Mixed.squehub.php',
            "@component('Mixed')@endcomponent");
        $this->project->write('Project/Views/Pages/Two.squehub.php',
            "@component('TwoA')@endcomponent");
        $this->project->write('Project/Views/Components/TwoA.squehub.php',
            "@component('TwoB')@endcomponent");
        $this->project->write('Project/Views/Components/TwoB.squehub.php',
            "@component('TwoA')@endcomponent");
        $this->project->write('Project/Views/Components/Mixed.squehub.php',
            "@include('Partials.BackToMixed')");
        $this->project->write('Project/Views/Partials/BackToMixed.squehub.php',
            "@component('Mixed')@endcomponent");
        $this->project->write('Project/Views/Pages/Reverse.squehub.php',
            "@include('Partials.Reverse')");
        $this->project->write('Project/Views/Partials/Reverse.squehub.php',
            "@component('Reverse')@endcomponent");
        $this->project->write('Project/Views/Components/Reverse.squehub.php',
            "@include('Partials.Reverse')");
        $this->project->write('Project/Views/Pages/Good.squehub.php',
            '<main>healthy</main>');
        foreach (['Pages.Direct', 'Pages.Two', 'Pages.Mixed', 'Pages.Reverse'] as $view) {
            [$error, $output] = $this->renderFailure($view);
            self::assertSame('', $output);
            self::assertStringContainsString('circular', strtolower($error->getMessage()));
            self::assertSame('<main>healthy</main>', $this->render('Pages.Good'));
        }
    }

    public function testRepeatedSiblingComponentsAreNotCycles(): void
    {
        $this->project->write('Project/Views/Pages/Badges.squehub.php',
            "@component('Badge', ['text' => 'A'])@endcomponent"
            . "@component('Badge', ['text' => 'B'])@endcomponent");
        $this->project->write('Project/Views/Components/Badge.squehub.php',
            "@props(['text'])<b>{{ \$text }}</b>");
        self::assertSame('<b>A</b><b>B</b>', $this->render('Pages.Badges'));
    }

    public function testApplicationAndRequestComponentStateAreIsolated(): void
    {
        $this->project->write('Project/Views/Pages/State.squehub.php',
            "@component('State', ['title' => \$title]){{ \$body }}@endcomponent");
        $this->project->write('Project/Views/Components/State.squehub.php',
            "@props(['title'])<a>{{ \$title }}:{!! \$slot->toHtml() !!}</a>");
        $this->application->views()->beginRequest(new Request('GET', '/one'));
        try {
            self::assertSame('<a>A:one</a>',
                $this->render('Pages.State', ['title' => 'A', 'body' => 'one']));
        } finally {
            $this->application->views()->endRequest();
        }
        $this->application->views()->beginRequest(new Request('GET', '/two'));
        try {
            self::assertSame('<a>B:two</a>',
                $this->render('Pages.State', ['title' => 'B', 'body' => 'two']));
        } finally {
            $this->application->views()->endRequest();
        }

        $other = new TemporaryProject();
        try {
            $other->write('Project/Views/Pages/State.squehub.php',
                "@component('State', ['title' => \$title]){{ \$body }}@endcomponent");
            $other->write('Project/Views/Components/State.squehub.php',
                "@props(['title'])<b>{{ \$title }}:{!! \$slot->toHtml() !!}</b>");
            RuntimeContext::select(new Application($other->path()));
            self::assertSame('<b>C:other</b>',
                $this->render('Pages.State', ['title' => 'C', 'body' => 'other']));
            RuntimeContext::select($this->application);
            self::assertSame('<a>D:again</a>',
                $this->render('Pages.State', ['title' => 'D', 'body' => 'again']));
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
