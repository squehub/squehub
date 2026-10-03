<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Plugins\ViewContext;
use App\Support\RuntimeContext;
use App\View\Compiler\CompilerException;
use App\View\FragmentNotFoundException;
use App\View\ViewNotFoundException;
use App\View\FragmentRenderResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Real compiled Views prove that Fragment selection never runs sibling code. */
final class FragmentRenderTest extends TestCase
{
    public function testFragmentInsideSectionRunsOnlySelectedBodyAndItsParticipants(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/App.squehub.php',
                "<header>LAYOUT</header>@yield('content')@script('/layout.js')@stack('scripts')");
            $project->write('Project/Views/Partials/Sibling.squehub.php',
                '@php $sibling(); @endphp<strong>SIBLING</strong>@script(\'/sibling.js\')');
            $project->write('Project/Views/Partials/Target.squehub.php',
                '<i>{{ $label }}</i>@style(\'/target.css\')');
            $project->write('Project/Views/Components/Sibling.squehub.php',
                '@props([])<aside>COMPONENT</aside>@script(\'/component.js\')');
            $project->write('Project/Views/Pages/Index.squehub.php', <<<'TEMPLATE'
@extends('Layouts.App')
@section('content')
<h1>BEFORE</h1>
@php $outside(); $siblingLocal = 'SIBLING_LOCAL'; @endphp
@include('Partials.Sibling')
@component('Sibling')@endcomponent
@fragment('orders.list')
<main>{{ $title }}|{{ $siblingLocal ?? 'isolated' }}|{{ $tick() }}</main>
@include('Partials.Target', ['label' => $label])
@script('/target.js', once: 'target-script')
@endfragment
@fragment('orders.other')
<p>OTHER</p>@script('/other.js')
@endfragment
<h2>AFTER</h2>
@endsection
TEMPLATE);

            $app = new Application($project->path());
            RuntimeContext::select($app);
            View::assets()->for('Pages.Index')->style('/root.css');
            View::assets()->for('Layouts.App')->style('/layout.css');
            $counts = ['outside' => 0, 'sibling' => 0, 'target' => 0,
                'rootComposer' => 0, 'layoutComposer' => 0, 'siblingComposer' => 0,
                'targetComposer' => 0];
            View::compose('Pages.Index', static function (ViewContext $context) use (&$counts): array {
                ++$counts['rootComposer'];
                return ['title' => 'Composed title'];
            });
            View::compose('Layouts.App', static function (ViewContext $context) use (&$counts): array {
                ++$counts['layoutComposer'];
                return [];
            });
            View::compose('Partials.Sibling', static function (ViewContext $context) use (&$counts): array {
                ++$counts['siblingComposer'];
                return [];
            });
            View::compose('Partials.Target', static function (ViewContext $context) use (&$counts): array {
                ++$counts['targetComposer'];
                return [];
            });
            $data = [
                'label' => '<Target>',
                'outside' => static function () use (&$counts): void { ++$counts['outside']; },
                'sibling' => static function () use (&$counts): void { ++$counts['sibling']; },
                'tick' => static function () use (&$counts): int { return ++$counts['target']; },
            ];

            $full = $this->render('Pages.Index', $data);
            self::assertStringContainsString('LAYOUT', $full);
            self::assertStringContainsString('BEFORE', $full);
            self::assertStringContainsString('Composed title|isolated|1', $full);
            self::assertStringContainsString('AFTER', $full);
            self::assertStringContainsString('OTHER', $full);
            self::assertSame(1, $counts['outside']);
            self::assertSame(1, $counts['sibling']);
            self::assertSame(1, $counts['target']);

            foreach ($counts as $name => $_value) { $counts[$name] = 0; }
            ob_start();
            try {
                $result = View::fragment('Pages.Index', 'orders.list', $data);
                self::assertSame('', (string) ob_get_contents(), 'Fragment API must not echo.');
            } finally {
                ob_end_clean();
            }
            self::assertInstanceOf(FragmentRenderResult::class, $result);
            self::assertStringContainsString('Composed title|isolated|1', $result->html());
            self::assertStringContainsString('&lt;Target&gt;', $result->html());
            foreach (['LAYOUT', 'BEFORE', 'AFTER', 'SIBLING', 'COMPONENT', 'OTHER'] as $excluded) {
                self::assertStringNotContainsString($excluded, $result->html());
            }
            self::assertSame(0, $counts['outside']);
            self::assertSame(0, $counts['sibling']);
            self::assertSame(1, $counts['target']);
            self::assertSame(1, $counts['rootComposer']);
            self::assertSame(0, $counts['layoutComposer']);
            self::assertSame(0, $counts['siblingComposer']);
            self::assertSame(1, $counts['targetComposer']);
            self::assertStringContainsString('/root.css', $result->stack('styles'));
            self::assertStringContainsString('/target.css', $result->stack('styles'));
            self::assertStringNotContainsString('/layout.css', $result->stack('styles'));
            self::assertStringContainsString('/target.js', $result->stack('scripts'));
            foreach (['/layout.js', '/sibling.js', '/component.js', '/other.js'] as $excluded) {
                self::assertStringNotContainsString($excluded, implode('', $result->stacks()));
            }
        } finally {
            $project->remove();
        }
    }

    public function testRootContextPrecedenceAndMultipleFragmentsReuseOneCompiledView(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Context.squehub.php', <<<'TEMPLATE'
@fragment('first'){{ $brand }}|{{ $title }}|{{ $provider }}|{{ $composer }}@endfragment
@fragment('second'){{ $title }}|{{ $brand }}@endfragment
TEMPLATE);
            $app = new Application($project->path());
            RuntimeContext::select($app);
            $calls = ['provider' => 0, 'composer' => 0];
            View::share('brand', 'Shared');
            View::share('title', 'Shared');
            View::provide(static function (ViewContext $context) use (&$calls): array {
                ++$calls['provider'];
                return ['title' => 'Provided', 'provider' => 'Yes'];
            });
            View::compose('Pages.Context', static function (ViewContext $context) use (&$calls): array {
                ++$calls['composer'];
                return ['title' => 'Composed', 'composer' => 'Yes'];
            });

            self::assertSame('Shared|Explicit|Yes|Yes',
                View::fragment('Pages.Context', 'first', ['title' => 'Explicit'])->html());
            self::assertSame(['provider' => 1, 'composer' => 1], $calls);
            $cached = glob($project->path('Storage/Views/*.php'));
            self::assertIsArray($cached);
            self::assertSame('Composed|Shared',
                View::fragment('Pages.Context', 'second')->html());
            self::assertSame($cached, glob($project->path('Storage/Views/*.php')));
            self::assertSame(['provider' => 2, 'composer' => 2], $calls);
        } finally {
            $project->remove();
        }
    }

    public function testFragmentComposesLoopsComponentsSlotsUtilitiesAndCustomAssets(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Components/Card.squehub.php', <<<'TEMPLATE'
@props(['item'])
<article>{{ $item }}:{!! $slot->toHtml() !!}</article>
@style('/card.css', once: 'card-style')
TEMPLATE);
            $project->write('Project/Views/Pages/Cards.squehub.php', <<<'TEMPLATE'
@fragment('cards')
<div class="{{ classes(['grid', 'active' => $active]) }}">
@foreach($items as $item)
@component('Card', ['item' => $item])<span>{{ $loop->iteration }}</span>@endcomponent
@endforeach
</div>
@push('head.meta')<meta name="cards" content="{{ json($config) }}">@endpush
@prepend('head.meta')<meta name="first">@endprepend
<script>window.cards = @json($config);</script>
@script('/cards.js', once: 'cards-script')
@endfragment
TEMPLATE);
            RuntimeContext::select(new Application($project->path()));
            $result = View::fragment('Pages.Cards', 'cards', [
                'items' => ['<One>', 'Two'], 'active' => true, 'config' => ['count' => 2],
            ]);
            self::assertStringContainsString('class="grid active"', $result->html());
            self::assertStringContainsString('&lt;One&gt;:<span>1</span>', $result->html());
            self::assertStringContainsString('Two:<span>2</span>', $result->html());
            self::assertStringContainsString('<script>window.cards = {"count":2};</script>',
                $result->html());
            self::assertSame(1, substr_count($result->stack('styles'), '/card.css'));
            self::assertStringContainsString('/cards.js', $result->stack('scripts'));
            self::assertSame('<meta name="first">' . "\n" . '<meta name="cards" content="'
                . '{&quot;count&quot;:2}">', $result->stack('head.meta'));
            self::assertSame($result->stack('head.meta'), $result->stack('head.meta'));
        } finally {
            $project->remove();
        }
    }

    public function testUnrelatedThrowingCodeAndMissingNameDoNotExecuteSiblings(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Safe.squehub.php', <<<'TEMPLATE'
@php throw new \RuntimeException('OUTSIDE_FRAGMENT_SECRET'); @endphp
@fragment('safe')SAFE@endfragment
TEMPLATE);
            RuntimeContext::select(new Application($project->path()));
            self::assertSame('SAFE', View::fragment('Pages.Safe', 'safe')->html());
            try {
                View::fragment('Pages.Safe', 'missing');
                self::fail('Missing fragment must fail.');
            } catch (FragmentNotFoundException $exception) {
                self::assertStringContainsString('Pages.Safe', $exception->getMessage());
                self::assertStringContainsString('missing', $exception->getMessage());
                self::assertFalse(method_exists($exception, 'sourceLine'));
                self::assertStringNotContainsString('OUTSIDE_FRAGMENT_SECRET', $exception->getMessage());
            }
            self::assertSame('SAFE', View::fragment('Pages.Safe', 'safe')->html());
        } finally {
            $project->remove();
        }
    }

    public function testNestedFailureDiscardsOutputAssetsAndCaptureStateBeforeNextRender(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Failed.squehub.php', <<<'TEMPLATE'
@fragment('broken')
<p>PARTIAL_FRAGMENT_SECRET</p>
@push('head', once: 'head-resource')<meta name="failed">@endpush
@component('Card')@include('Partials.Throw')@endcomponent
@endfragment
TEMPLATE);
            $project->write('Project/Views/Components/Card.squehub.php',
                '@props([])<section>{!! $slot->toHtml() !!}</section>');
            $project->write('Project/Views/Partials/Throw.squehub.php',
                "<?php throw new \\RuntimeException('NESTED_FRAGMENT_SECRET'); ?>");
            $project->write('Project/Views/Pages/Good.squehub.php',
                "@fragment('good')<p>GOOD</p>@push('head', once: 'head-resource')"
                . '<meta name="good">@endpush@endfragment');
            RuntimeContext::select(new Application($project->path()));

            [$error, $leaked] = $this->failedFragment('Pages.Failed', 'broken');
            self::assertInstanceOf(RuntimeException::class, $error);
            self::assertSame('', $leaked);
            $fresh = View::fragment('Pages.Good', 'good');
            self::assertSame('<p>GOOD</p>', $fresh->html());
            self::assertSame('<meta name="good">', $fresh->stack('head'));
            self::assertStringNotContainsString('failed', implode('', $fresh->stacks()));
        } finally {
            $project->remove();
        }
    }

    public function testApplicationSwitchKeepsContextAndExternalAssetsIsolated(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Environment.squehub.php', <<<'TEMPLATE'
@fragment('state')@if(environment('local') && debugging())LOCAL@else PRODUCTION@endif@endfragment
TEMPLATE);
            $local = new Application($project->path());
            $local->config()->set('app.env', 'local');
            $local->config()->set('app.debug', true);
            $production = new Application($project->path());
            $production->config()->set('app.env', 'production');
            $production->config()->set('app.debug', false);

            RuntimeContext::select($local);
            View::assets()->for('Pages.Environment')->script('/local-only.js');
            self::assertSame('LOCAL', trim(View::fragment('Pages.Environment', 'state')->html()));
            self::assertStringContainsString('/local-only.js',
                View::fragment('Pages.Environment', 'state')->stack('scripts'));

            RuntimeContext::select($production);
            $other = View::fragment('Pages.Environment', 'state');
            self::assertSame('PRODUCTION', trim($other->html()));
            self::assertFalse($other->hasStack('scripts'));
            self::assertSame('', $other->stack('scripts'));

            RuntimeContext::select($local);
            self::assertSame('LOCAL', trim(View::fragment('Pages.Environment', 'state')->html()));
        } finally {
            $project->remove();
        }
    }

    public function testEnabledPackageViewCanOwnAFragmentButDisabledPackageCannotResolveIt(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Packages/FragmentAddon/FragmentAddon.php',
                '<?php namespace Packages\\FragmentAddon; final class FragmentAddon extends '
                . '\\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Packages/FragmentAddon/Views/PackageWidget.squehub.php', <<<'TEMPLATE'
BEFORE
@fragment('summary')<p>{{ $label }}</p>@style('/package-widget.css')@endfragment
AFTER
TEMPLATE);

            $disabled = new Application($project->path());
            $disabled->bootstrap();
            RuntimeContext::select($disabled);
            try {
                View::fragment('PackageWidget', 'summary', ['label' => 'Ready']);
                self::fail('Disabled Package View must not resolve.');
            } catch (ViewNotFoundException $exception) {
                self::assertStringContainsString('PackageWidget', $exception->getMessage());
            }
            $packages = $disabled->container()->make(PackageManager::class);
            self::assertTrue($packages->apply($packages->planEnable('FragmentAddon'))->complete());
            $enabled = new Application($project->path());
            $enabled->bootstrap();
            RuntimeContext::select($enabled);
            $result = View::fragment('PackageWidget', 'summary', ['label' => '<Ready>']);
            self::assertSame('<p>&lt;Ready&gt;</p>', trim($result->html()));
            self::assertStringContainsString('/package-widget.css', $result->stack('styles'));
            self::assertStringNotContainsString('BEFORE', $result->html());
            self::assertStringNotContainsString('AFTER', $result->html());
        } finally {
            $project->remove();
        }
    }

    public function testFragmentRendersWithoutAnHttpRequestOrSession(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Cli.squehub.php',
                '@fragment(\'summary\'){{ $label }}|{{ $provider }}@endfragment');
            $app = new Application($project->path());
            $app->views()->provide(static function (ViewContext $context): array {
                self::assertNull($context->request());
                return ['provider' => 'CLI'];
            });
            RuntimeContext::select($app);
            self::assertSame('&lt;CLI&gt;|CLI',
                View::fragment('Pages.Cli', 'summary', ['label' => '<CLI>'])->html());
        } finally {
            $project->remove();
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

    /** @return array{Throwable, string} */
    private function failedFragment(string $view, string $name): array
    {
        $level = ob_get_level();
        $error = null;
        ob_start();
        try {
            View::fragment($view, $name);
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            $output = (string) ob_get_contents();
            while (ob_get_level() > $level) { ob_end_clean(); }
        }
        self::assertNotNull($error, 'Expected Fragment render to fail.');
        return [$error, $output];
    }
}
