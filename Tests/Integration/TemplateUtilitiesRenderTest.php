<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Support\RuntimeContext;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Exercises Template Utilities through cached, real View renders. */
final class TemplateUtilitiesRenderTest extends TestCase
{
    public function testJsonClassesAndFormStateStayRuntimeDrivenAcrossCachedRenders(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Utilities.squehub.php', <<<'TEMPLATE'
<script>window.pageData = @json($payload);</script>
<div data-phase14k="utility-render-cache" data-config="{{ json($payload) }}" class="{{ classes([
    'card',
    'card-featured' => $featured,
    $extraClass,
]) }}">
<input type="checkbox" {{ checked($enabled) }}>
<option {{ selected($chosen) }}>Nigeria</option>
@foreach($items as $item)
<span class="{{ classes(['item', 'item-current' => $item['current']]) }}">{{ $item['label'] }}</span>
@endforeach
</div>
TEMPLATE);

            $app = new Application($project->path());
            RuntimeContext::select($app);
            $payloadA = ['name' => '</script><script>alert(1)</script>', 'unicode' => 'Ẹ káàbọ̀ 😀'];
            $first = $this->render('Pages.Utilities', [
                'payload' => $payloadA,
                'featured' => true,
                'extraClass' => '" onmouseover="PRIVATE_SECRET',
                'enabled' => true,
                'chosen' => false,
                'items' => [['current' => true, 'label' => '<A>']],
            ]);
            self::assertSame(1, substr_count($first, '<script>'));
            self::assertStringContainsString('window.pageData = ' . \json($payloadA) . ';', $first);
            self::assertStringNotContainsString('</script><script>alert(1)</script>', $first);
            self::assertStringContainsString('data-config="' . htmlspecialchars(\json($payloadA),
                ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"', $first);
            self::assertStringContainsString('class="card card-featured &quot; onmouseover=&quot;PRIVATE_SECRET"',
                $first);
            self::assertStringContainsString('<input type="checkbox" checked>', $first);
            self::assertStringContainsString('<option >Nigeria</option>', $first);
            self::assertStringContainsString('class="item item-current"', $first);
            self::assertStringContainsString('&lt;A&gt;', $first);

            $cachePattern = $project->path('Storage/Views/*.php');
            $cacheAfterFirst = glob($cachePattern);
            self::assertIsArray($cacheAfterFirst);
            self::assertNotEmpty($cacheAfterFirst);
            $second = $this->render('Pages.Utilities', [
                'payload' => ['name' => 'B'],
                'featured' => false,
                'extraClass' => 'rounded',
                'enabled' => false,
                'chosen' => true,
                'items' => [['current' => false, 'label' => 'B']],
            ]);
            self::assertSame($cacheAfterFirst, glob($cachePattern));
            self::assertStringContainsString('window.pageData = ' . \json(['name' => 'B']) . ';', $second);
            self::assertStringContainsString('class="card rounded"', $second);
            self::assertStringContainsString('<input type="checkbox" >', $second);
            self::assertStringContainsString('<option selected>Nigeria</option>', $second);
            self::assertStringContainsString('class="item"', $second);
            self::assertStringNotContainsString('PRIVATE_SECRET', $second);
            self::assertStringNotContainsString('card-featured', $second);
            $matchingCompiledViews = 0;
            foreach ($cacheAfterFirst as $path) {
                $compiled = file_get_contents($path);
                self::assertIsString($compiled);
                if (!str_contains($compiled, 'data-phase14k="utility-render-cache"')) {
                    continue;
                }
                ++$matchingCompiledViews;
                self::assertStringNotContainsString('PRIVATE_SECRET', $compiled);
                self::assertStringNotContainsString('Ẹ káàbọ̀', $compiled);
            }
            self::assertGreaterThan(0, $matchingCompiledViews);
        } finally {
            $project->remove();
        }
    }

    public function testUtilitiesComposeThroughLayoutPartialComponentPropsSlotsAndAttributes(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/App.squehub.php',
                '<main>@yield(\'content\')</main>');
            $project->write('Project/Views/Partials/Details.squehub.php',
                '<i class="{{ classes([\'detail\', \'detail-active\' => $active]) }}">'
                . '{{ $label }}</i>');
            $project->write('Project/Views/Components/Card.squehub.php', <<<'TEMPLATE'
@props(['featured', 'config'])
<article {!! $attributes->merge(['class' => 'card'])->toHtml() !!}>
<div class="{{ classes(['body', 'body-featured' => $featured]) }}">@json($config)</div>
{!! $slot->toHtml() !!}
</article>
TEMPLATE);
            $project->write('Project/Views/Pages/Composition.squehub.php', <<<'TEMPLATE'
@extends('Layouts.App')
@section('content')
@component('Card', ['featured' => $featured, 'config' => $config], [
    'class' => classes(['card', 'card-featured' => $featured]),
    'data-state' => 'ready',
])
@include('Partials.Details', ['active' => $featured, 'label' => $label])
@endcomponent
@endsection
TEMPLATE);

            RuntimeContext::select(new Application($project->path()));
            $output = $this->render('Pages.Composition', [
                'featured' => true,
                'config' => ['title' => '<Unsafe>'],
                'label' => '<Details>',
            ]);
            self::assertStringContainsString('<main>', $output);
            self::assertStringContainsString('class="card card-featured" data-state="ready"', $output);
            self::assertStringContainsString('class="body body-featured"', $output);
            self::assertStringContainsString(\json(['title' => '<Unsafe>']), $output);
            self::assertStringContainsString('class="detail detail-active"', $output);
            self::assertStringContainsString('&lt;Details&gt;', $output);
            self::assertStringNotContainsString('<Unsafe>', $output);
        } finally {
            $project->remove();
        }
    }

    public function testEnvironmentAndDebugControlMarkupAndAssetParticipationPerApplication(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/App.squehub.php',
                '<head>@stack(\'scripts\')</head><body>@yield(\'content\')</body>');
            $project->write('Project/Views/Pages/Environment.squehub.php', <<<'TEMPLATE'
@extends('Layouts.App')
@if(environment('local'))
    @script('/assets/dev-tools.js')
@endif
@section('content')
@if(environment('local', 'testing'))<small>local or test</small>@endif
@if(debugging())<small>debug mode</small>@endif
@endsection
TEMPLATE);
            $local = new Application($project->path());
            $local->config()->set('app.env', 'local');
            $local->config()->set('app.debug', true);
            $production = new Application($project->path());
            $production->config()->set('app.env', 'production');
            $production->config()->set('app.debug', false);

            RuntimeContext::select($local);
            $localOutput = $this->render('Pages.Environment');
            self::assertStringContainsString('/assets/dev-tools.js', $localOutput);
            self::assertStringContainsString('<small>local or test</small>', $localOutput);
            self::assertStringContainsString('<small>debug mode</small>', $localOutput);

            $cachePattern = $project->path('Storage/Views/*.php');
            $cacheAfterFirst = glob($cachePattern);
            RuntimeContext::select($production);
            $productionOutput = $this->render('Pages.Environment');
            self::assertSame($cacheAfterFirst, glob($cachePattern));
            self::assertStringNotContainsString('/assets/dev-tools.js', $productionOutput);
            self::assertStringNotContainsString('<small>', $productionOutput);

            RuntimeContext::select($local);
            self::assertSame($localOutput, $this->render('Pages.Environment'));
        } finally {
            $project->remove();
        }
    }

    public function testInlineJsonInsideCapturedScriptAssetUsesCurrentRenderData(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/ScriptAsset.squehub.php', <<<'TEMPLATE'
<head>@stack('scripts')</head>
@push('scripts')<script>window.asset = @json($payload);</script>@endpush
TEMPLATE);
            RuntimeContext::select(new Application($project->path()));

            $first = $this->render('Pages.ScriptAsset', [
                'payload' => ['message' => '</script><script>alert(1)</script>'],
            ]);
            self::assertSame(1, substr_count($first, '<script>'));
            self::assertStringContainsString('window.asset = ' . \json([
                'message' => '</script><script>alert(1)</script>',
            ]) . ';', $first);
            self::assertStringNotContainsString('<script>alert(1)', $first);

            $second = $this->render('Pages.ScriptAsset', [
                'payload' => ['message' => 'fresh'],
            ]);
            self::assertSame(1, substr_count($second, '<script>'));
            self::assertStringContainsString('window.asset = ' . \json([
                'message' => 'fresh',
            ]) . ';', $second);
            self::assertStringNotContainsString('alert(1)', $second);
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
}
