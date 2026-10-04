<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Plugins\ViewContext;
use App\Support\RuntimeContext;
use App\View\Compiler\CompilerException;
use App\View\ViewNotFoundException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Runs compiler regressions through the real View resolver and renderer. */
final class TemplateCompilerRenderTest extends TestCase
{
    /** @dataProvider lineEndings */
    public function testMultilineIncludeAcceptsNestedModernPhpExpressions(string $lineEnding): void
    {
        $project = new TemporaryProject();
        try {
            $page = <<<'TEMPLATE'
@include(
    'Partials.Card',
    [
        'text' => 'It\'s (working), @include("fake")',
        'double' => "A \"quoted\" value",
        'computed' => strtoupper(trim($title)),
        'closure' => (static function ($item) {
            return strtoupper($item);
        })($title),
        'arrow' => (fn ($item) => strtolower($item))($title),
        'selected' => match ($state) {
            'live' => 'ON',
            default => 'OFF',
        },
        'named' => substr(string: $title, offset: 1),
        'options' => ['links' => ['/a', '/b']],
    ]
)
TEMPLATE;
            $project->write('Project/Views/Pages/Modern.squehub.php',
                str_replace("\n", $lineEnding, $page));
            $project->write('Project/Views/Partials/Card.squehub.php',
                "{{ \$text }}|{{ \$double }}|{{ \$computed }}|{{ \$closure }}|"
                . "{{ \$arrow }}|{{ \$selected }}|{{ \$named }}|"
                . "{{ implode(',', \$options['links']) }}");

            $app = new Application($project->path());
            RuntimeContext::select($app);
            self::assertSame(
                'It&#039;s (working), @include(&quot;fake&quot;)|A &quot;quoted&quot; value|'
                . 'ADA|ADA|ada|ON|da|/a,/b',
                $this->render('Pages.Modern', ['title' => 'Ada', 'state' => 'live'])
            );
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

    public function testMultilineLayoutSectionsYieldAndIncludesKeepContextPrecedence(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Pages/Post.squehub.php', <<<'TEMPLATE'
@section(
    'content'
)
<article>{{ $post }}</article>
@include(
    'Parts.Card',
    ['title' => strtoupper(trim($title))]
)
@endsection
@extends(
    'Layouts.Main'
)
TEMPLATE);
            $project->write('Project/Views/Layouts/Main.squehub.php', <<<'TEMPLATE'
<title>{{ $title }}</title><h1>@yield(
    'heading',
    strtoupper(trim($fallback))
)</h1>@yield('content')
TEMPLATE);
            $project->write('Project/Views/Parts/Card.squehub.php',
                '<span>{{ $brand }}|{{ $title }}|{{ $post }}</span>@include(\'Parts.Nested\')');
            $project->write('Project/Views/Parts/Nested.squehub.php', '<em>{{ $title }}</em>');

            $app = new Application($project->path());
            $app->views()->share('brand', 'Acme');
            $app->views()->share('title', 'Shared');
            $app->views()->provide(static fn (ViewContext $context): array => [
                'title' => 'Provided', 'fallback' => 'fallback',
            ]);
            $app->views()->compose('Pages.Post',
                static fn (ViewContext $context): array => ['title' => 'Composed']);
            $app->views()->compose('Parts.Card',
                static fn (ViewContext $context): array => ['title' => 'Card composer']);
            RuntimeContext::select($app);

            $first = $this->render('Pages.Post', ['title' => 'Post', 'post' => 'P7']);
            self::assertStringContainsString('<title>Post</title>', $first);
            self::assertStringContainsString('<h1>FALLBACK</h1>', $first);
            self::assertStringContainsString('<article>P7</article>', $first);
            self::assertStringContainsString('<span>Acme|POST|P7</span><em>POST</em>', $first);

            $second = $this->render('Pages.Post', ['title' => 'Next', 'post' => 'P8']);
            self::assertStringContainsString('<title>Next</title>', $second);
            self::assertStringContainsString('<span>Acme|NEXT|P8</span><em>NEXT</em>', $second);
            self::assertStringNotContainsString('P7', $second);
        } finally {
            $project->remove();
        }
    }

    public function testMultilineEscapedAndRawEchoesIgnoreQuotedClosers(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Echoes.squehub.php', <<<'TEMPLATE'
<div title="{{ $value }}">{{
    json_encode([
        'closing' => '}}',
        'value' => $value,
    ], JSON_THROW_ON_ERROR)
}}</div><aside>{!!
    implode('', ['!!}', $trusted])
!!}</aside>
TEMPLATE);
            $app = new Application($project->path());
            RuntimeContext::select($app);

            $value = '<>&"\'';
            $rendered = $this->render('Echoes', ['value' => $value, 'trusted' => '<b>yes</b>']);
            self::assertStringContainsString('title="&lt;&gt;&amp;&quot;&#039;"', $rendered);
            self::assertStringContainsString('&quot;closing&quot;', $rendered);
            self::assertStringContainsString('&quot;}}&quot;', $rendered);
            self::assertStringContainsString('<aside>!!}<b>yes</b></aside>', $rendered);
            self::assertStringNotContainsString('<div title="<', $rendered);
        } finally {
            $project->remove();
        }
    }

    public function testHeredocAndNowdocCanContainTemplateClosersAndArgumentCommas(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Heredoc.squehub.php', <<<'TEMPLATE'
{{ <<<TEXT
literal }} text
TEXT
}}|@include('Parts.Heredoc', ['title' => <<<'TITLE'
A, ) }} B
TITLE
])
TEMPLATE);
            $project->write('Project/Views/Parts/Heredoc.squehub.php', '{{ $title }}');
            $app = new Application($project->path());
            RuntimeContext::select($app);

            self::assertSame("literal }} text|A, ) }} B", $this->render('Heredoc'));
        } finally {
            $project->remove();
        }
    }

    public function testEscapedEchoSubstitutesInvalidUtf8AndDoesNotDoubleEscape(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Escape.squehub.php', '{{ $value }}');
            $app = new Application($project->path());
            RuntimeContext::select($app);

            self::assertSame('&lt;&gt;&amp;&quot;&#039;�(',
                $this->render('Escape', ['value' => "<>&\"'\xC3("]));
            self::assertSame('&amp;amp;', $this->render('Escape', ['value' => '&amp;']));
        } finally {
            $project->remove();
        }
    }

    public function testRawPhpStringsAndCommentsAreNotRecursivelyCompiled(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/RawPhp.squehub.php', <<<'TEMPLATE'
<?php
$directive = '@include("fake")';
$echo = '{{ fake }}';
// @csrf {!! fake !!}
/* @section('fake') */
?>
<?= $directive ?>|<?= $echo ?>|{{ strtoupper($title) }}
TEMPLATE);
            $app = new Application($project->path());
            RuntimeContext::select($app);

            self::assertSame('@include("fake")|{{ fake }}|ADA',
                $this->render('RawPhp', ['title' => 'Ada']));
        } finally {
            $project->remove();
        }
    }

    public function testCssJavaScriptAndEmailAtTextRenderLiterally(): void
    {
        $project = new TemporaryProject();
        try {
            $source = <<<'TEMPLATE'
<style>@media (max-width: 800px) { .card { color: red; } }</style>
<script>const value = "@include('not-a-view')"; const user = '@username';</script>
<p>support@example.com @example</p>
TEMPLATE;
            $project->write('Project/Views/Literal.squehub.php', $source);
            $app = new Application($project->path());
            RuntimeContext::select($app);

            self::assertSame($source, $this->render('Literal'));
        } finally {
            $project->remove();
        }
    }

    public function testExistingSimpleConditionalAndLoopSyntaxStillRenders(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/LegacyControl.squehub.php',
                '@if($show)<ul>@foreach($items as $item)<li>{{ $item }}</li>@endforeach</ul>'
                . '@else<p>empty</p>@endif');
            $app = new Application($project->path());
            RuntimeContext::select($app);

            self::assertSame('<ul><li>&lt;A&gt;</li><li>B</li></ul>',
                $this->render('LegacyControl', ['show' => true, 'items' => ['<A>', 'B']]));
            self::assertSame('<p>empty</p>',
                $this->render('LegacyControl', ['show' => false, 'items' => ['ignored']]));
        } finally {
            $project->remove();
        }
    }

    public function testCompiledCacheContainsExpressionsButNeverRenderValues(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Cache.squehub.php', '{{ $privateMarker }}|{{ $title }}');
            $app = new Application($project->path());
            RuntimeContext::select($app);
            $first = 'secret-first-' . bin2hex(random_bytes(6));
            $second = 'secret-second-' . bin2hex(random_bytes(6));

            self::assertSame($first . '|First', $this->render('Cache', [
                'privateMarker' => $first, 'title' => 'First',
            ]));
            self::assertSame($second . '|Second', $this->render('Cache', [
                'privateMarker' => $second, 'title' => 'Second',
            ]));

            $files = glob($project->path('Storage/Views/*.php'));
            self::assertIsArray($files);
            self::assertNotEmpty($files);
            foreach ($files as $file) {
                $compiled = file_get_contents($file);
                self::assertIsString($compiled);
                self::assertStringNotContainsString($first, $compiled);
                self::assertStringNotContainsString($second, $compiled);
            }
        } finally {
            $project->remove();
        }
    }

    public function testChangingSourceProducesUpdatedOutputInsteadOfStaleCompiledCode(): void
    {
        $project = new TemporaryProject();
        try {
            $file = 'Project/Views/Changed.squehub.php';
            $project->write($file, 'old {{ $title }}');
            $app = new Application($project->path());
            RuntimeContext::select($app);
            self::assertSame('old First', $this->render('Changed', ['title' => 'First']));

            $project->write($file, 'new {{ strtoupper($title) }}');
            self::assertSame('new SECOND', $this->render('Changed', ['title' => 'Second']));
        } finally {
            $project->remove();
        }
    }

    public function testDifferentViewAndBodyPairsCannotShareACompiledCacheFile(): void
    {
        $project = new TemporaryProject();
        try {
            $marker = bin2hex(random_bytes(8));
            $firstPath = 'Project/Views/A.squehub.php';
            $secondPath = 'Project/Views/AB.squehub.php';
            $project->write($firstPath, 'B' . $marker);
            $project->write($secondPath, $marker);
            // Keep source mtimes older than the compiled file so a shared key
            // would reuse the first View's body instead of rewriting the file.
            touch($project->path($firstPath), time() - 60);
            touch($project->path($secondPath), time() - 60);
            $app = new Application($project->path());
            RuntimeContext::select($app);

            self::assertSame('B' . $marker, $this->render('A'));
            self::assertSame($marker, $this->render('AB'));
            self::assertSame('B' . $marker, $this->render('A'));
        } finally {
            $project->remove();
        }
    }

    public function testRawPhpEchoUsesCurrentRenderDataWithoutBakingItIntoCompiledCache(): void
    {
        $project = new TemporaryProject();
        try {
            $probe = 'raw-cache-probe-' . bin2hex(random_bytes(6));
            $project->write('Project/Views/RawRuntimeCache.squehub.php',
                '<?php /* ' . $probe . ' */ echo $runtimeValue; ?>');
            $app = new Application($project->path());
            RuntimeContext::select($app);
            $first = 'runtime-first-' . bin2hex(random_bytes(6));
            $second = 'runtime-second-' . bin2hex(random_bytes(6));

            self::assertSame($first, $this->render('RawRuntimeCache', ['runtimeValue' => $first]));
            $firstCache = $this->compiledFilesContaining($probe);
            self::assertCount(1, $firstCache);
            self::assertSame($second, $this->render('RawRuntimeCache', ['runtimeValue' => $second]));
            self::assertSame($firstCache, $this->compiledFilesContaining($probe));

            $compiled = file_get_contents($firstCache[0]);
            self::assertIsString($compiled);
            self::assertStringContainsString('echo $runtimeValue;', $compiled);
            self::assertStringNotContainsString($first, $compiled);
            self::assertStringNotContainsString($second, $compiled);
        } finally {
            $project->remove();
        }
    }

    public function testLayoutSectionOutputIsNeverSubstitutedIntoCompiledCache(): void
    {
        $project = new TemporaryProject();
        try {
            $probe = 'section-cache-probe-' . bin2hex(random_bytes(6));
            $project->write('Project/Views/Sections/Page.squehub.php',
                "@section('content'){{ \$privateValue }}@endsection@extends('Sections.Layout')");
            $project->write('Project/Views/Sections/Layout.squehub.php',
                '<?php /* ' . $probe . ' */ ?>@yield(\'content\')');
            $app = new Application($project->path());
            RuntimeContext::select($app);
            $first = 'section-first-' . bin2hex(random_bytes(6));
            $second = 'section-second-' . bin2hex(random_bytes(6));

            self::assertSame($first, $this->render('Sections.Page', ['privateValue' => $first]));
            self::assertSame($second, $this->render('Sections.Page', ['privateValue' => $second]));
            $files = $this->compiledFilesContaining($probe);
            self::assertCount(1, $files);
            $compiled = file_get_contents($files[0]);
            self::assertIsString($compiled);
            // The cached layout must retain a runtime section lookup. Captured
            // request output never becomes compiled PHP or static HTML.
            self::assertStringContainsString("hasSection('content')", $compiled);
            self::assertStringContainsString("section('content')", $compiled);
            self::assertStringNotContainsString($first, $compiled);
            self::assertStringNotContainsString($second, $compiled);
        } finally {
            $project->remove();
        }
    }

    public function testMalformedTemplatePublishesNoCompiledCacheArtifact(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Unclosed.squehub.php', "line one\n{{ \$title");
            $app = new Application($project->path());
            RuntimeContext::select($app);
            $before = glob($project->path('Storage/Views/*.php')) ?: [];

            try {
                $this->render('Unclosed', ['title' => 'secret-runtime-value']);
                self::fail('Expected malformed template to stop before cache publication.');
            } catch (CompilerException $exception) {
                self::assertStringContainsString('Unclosed', $exception->getMessage());
                self::assertStringNotContainsString('secret-runtime-value', $exception->getMessage());
            }
            self::assertSame($before, glob($project->path('Storage/Views/*.php')) ?: []);
        } finally {
            $project->remove();
        }
    }

    public function testPhysicalTemplateResolvesAndRenders(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Physical.squehub.php', 'PHYSICAL_VIEW_MARKER');
            $app = new Application($project->path());
            RuntimeContext::select($app);

            self::assertNotNull(View::sourceOf('Physical'));
            self::assertSame('PHYSICAL_VIEW_MARKER', $this->render('Physical'));
        } finally {
            $project->remove();
        }
    }

    public function testBrokenFileLinkCannotBeResolved(): void
    {
        $project = new TemporaryProject();
        $link = $project->path('Project/Views/Broken.squehub.php');
        try {
            $project->write('Project/Views/Inside.squehub.php', 'inside');
            if (!@symlink($project->path('Project/Views/Absent.squehub.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            $app = new Application($project->path());
            RuntimeContext::select($app);

            self::assertNull(View::sourceOf('Broken'));
            $this->assertUnavailableView('Broken',
                [$project->path('Project/Views/Absent.squehub.php')]);
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            $project->remove();
        }
    }

    public function testFileLinkWithinViewRootResolvesAndRenders(): void
    {
        $project = new TemporaryProject();
        $link = $project->path('Project/Views/Linked.squehub.php');
        try {
            $project->write('Project/Views/Inside.squehub.php', 'SAFE_LINK_MARKER');
            if (!@symlink($project->path('Project/Views/Inside.squehub.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            $app = new Application($project->path());
            RuntimeContext::select($app);

            self::assertNotNull(View::sourceOf('Linked'));
            self::assertSame('SAFE_LINK_MARKER', $this->render('Linked'));
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            $project->remove();
        }
    }

    public function testLinkedTemplateOutsideApplicationRootCannotBeResolved(): void
    {
        $project = new TemporaryProject();
        $outside = new TemporaryProject();
        $link = $project->path('Project/Views/Linked.squehub.php');
        try {
            $outsideMarker = 'OUTSIDE_SECRET_MARKER_' . bin2hex(random_bytes(6));
            $outside->write('Outside.squehub.php', $outsideMarker);
            $project->write('Project/Views/Inside.squehub.php', 'inside');
            if (!@symlink($outside->path('Outside.squehub.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            $app = new Application($project->path());
            RuntimeContext::select($app);

            self::assertNull(View::sourceOf('Linked'));
            $this->assertUnavailableView('Linked',
                [$outsideMarker, $outside->path('Outside.squehub.php')]);
            self::assertSame([], $this->compiledFilesContaining($outsideMarker));
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            $project->remove();
            $outside->remove();
        }
    }

    public function testCompiledViewCannotFollowSourceReplacedByOutsideFileLink(): void
    {
        $project = new TemporaryProject();
        $outside = new TemporaryProject();
        $viewPath = $project->path('Project/Views/Swapped.squehub.php');
        try {
            $safeMarker = 'safe-view-' . bin2hex(random_bytes(6));
            $outsideMarker = 'OUTSIDE_SECRET_MARKER_' . bin2hex(random_bytes(6));
            $project->write('Project/Views/Swapped.squehub.php', $safeMarker);
            $outside->write('Outside.squehub.php', $outsideMarker);
            $app = new Application($project->path());
            RuntimeContext::select($app);

            self::assertSame($safeMarker, $this->render('Swapped'));
            self::assertCount(1, $this->compiledFilesContaining($safeMarker));

            unlink($viewPath);
            if (!@symlink($outside->path('Outside.squehub.php'), $viewPath)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            clearstatcache(true, $viewPath);

            self::assertNull(View::sourceOf('Swapped'));
            $this->assertUnavailableView('Swapped',
                [$outsideMarker, $outside->path('Outside.squehub.php')]);
            self::assertSame([], $this->compiledFilesContaining($outsideMarker));
        } finally {
            if (is_link($viewPath)) {
                unlink($viewPath);
            }
            $project->remove();
            $outside->remove();
        }
    }

    /**
     * Unavailable roots reach the logical error boundary. A rejected link must
     * never yield partial or cached template output.
     *
     * @param list<string> $privateValues
     */
    private function assertUnavailableView(string $view, array $privateValues): void
    {
        $level = ob_get_level();
        ob_start();
        try {
            View::render($view);
            self::fail('An unavailable root View must fail through the exception boundary.');
        } catch (ViewNotFoundException $error) {
            self::assertSame($view, $error->view());
            self::assertTrue($error->unsafe());
            self::assertSame('View "' . $view . '" is unsafe or unavailable.',
                $error->getMessage());
            foreach ($privateValues as $private) {
                self::assertStringNotContainsString($private, $error->getMessage());
            }
        } finally {
            $output = (string) ob_get_contents();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        self::assertSame('', $output);
    }

    /** @return list<string> */
    private function compiledFilesContaining(string $probe): array
    {
        $found = [];
        foreach (glob(View::application()->basePath('Storage/Views/*.php')) ?: [] as $file) {
            $source = file_get_contents($file);
            if (is_string($source) && str_contains($source, $probe)) {
                $found[] = $file;
            }
        }
        return $found;
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
