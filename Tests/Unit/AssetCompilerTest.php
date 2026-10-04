<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/** Phase 14F asset grammar and source diagnostics, independent of rendering. */
final class AssetCompilerTest extends TestCase
{
    public function testDirectAssetsAndStacksCompileRuntimeExpressionsAndNamedOnceArguments(): void
    {
        $source = <<<'TEMPLATE'
@style(
    $themeCss,
    once: 'theme-style'
)
@script($scriptFor($page, ['a,b']), once: 'page-runtime')
@stack('styles')
@stack('scripts-after')
TEMPLATE;

        $compiled = $this->compile($source);

        self::assertSame(1, substr_count($compiled, '$themeCss'));
        self::assertSame(1, substr_count($compiled, '$scriptFor($page, [\'a,b\'])'));
        self::assertStringContainsString('theme-style', $compiled);
        self::assertStringContainsString('page-runtime', $compiled);
        self::assertStringContainsString('scripts-after', $compiled);
        self::assertStringNotContainsString('@style', $compiled);
        self::assertStringNotContainsString('@script', $compiled);
        self::assertStringNotContainsString('@stack', $compiled);
        self::assertSame($compiled, $this->compile($source));
    }

    public function testPushAndPrependCaptureNormalTemplateContent(): void
    {
        $source = <<<'TEMPLATE'
@push(
    'head',
    once: 'api-preconnect'
)
@if ($enabled)
<link href="{{ $url }}">
@endif
@endpush
@prepend('vendor.scripts')<script>{!! $trusted !!}</script>@endprepend
TEMPLATE;

        $compiled = $this->compile($source);

        self::assertStringContainsString('api-preconnect', $compiled);
        self::assertStringContainsString('$enabled', $compiled);
        self::assertStringContainsString('$url', $compiled);
        self::assertStringContainsString('$trusted', $compiled);
        self::assertStringContainsString('vendor.scripts', $compiled);
        foreach (['@push', '@endpush', '@prepend', '@endprepend'] as $directive) {
            self::assertStringNotContainsString($directive, $compiled);
        }
    }

    /** @dataProvider invalidAssetStructures */
    public function testInvalidAssetStructuresReportLogicalViewAndOpeningLine(
        string $source,
        int $line,
        string $directive
    ): void {
        try {
            $this->compile($source, 'Pages.Invalid');
            self::fail('Expected a compiler error for ' . $directive);
        } catch (CompilerException $exception) {
            self::assertSame('Pages.Invalid', $exception->view());
            self::assertSame($line, $exception->sourceLine());
            self::assertStringContainsString($directive, strtolower($exception->getMessage()));
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidAssetStructures(): iterable
    {
        yield 'empty style' => ['@style()', 1, 'style'];
        yield 'empty script' => ['@script()', 1, 'script'];
        yield 'empty stack' => ["@stack('')", 1, 'stack'];
        yield 'dynamic stack' => ['@stack($name)', 1, 'stack'];
        yield 'two stack names' => ["@stack('head', 'styles')", 1, 'stack'];
        yield 'path stack' => ["@stack('../scripts')", 1, 'stack'];
        yield 'dynamic push' => ['@push($name)X@endpush', 1, 'push'];
        yield 'unmatched endpush' => ["Text\n@endpush", 2, 'endpush'];
        yield 'unmatched endprepend' => ["Text\n@endprepend", 2, 'endprepend'];
        yield 'unclosed push' => ["@push('head')\nX", 1, 'push'];
        yield 'unclosed prepend' => ["@prepend('head')\nX", 1, 'prepend'];
        yield 'mismatched close' => ["@push('head')\n@endprepend", 2, 'endprepend'];
        yield 'nested push' => ["@push('head')\n@push('head')X@endpush\n@endpush", 2, 'push'];
        yield 'nested prepend' => ["@prepend('head')\n@push('head')X@endpush\n@endprepend", 2, 'push'];
    }

    /** @dataProvider lineEndings */
    public function testSourceLineIsAccurateWithLfAndCrLf(string $lineEnding): void
    {
        $source = str_replace("\n", $lineEnding,
            "First\n@push('head')\nBody\n@prepend('head')\n@endprepend\n@endpush");
        try {
            $this->compile($source, 'Pages.LineEndings');
            self::fail('Expected nested capture error.');
        } catch (CompilerException $exception) {
            self::assertSame('Pages.LineEndings', $exception->view());
            self::assertSame(4, $exception->sourceLine());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
    }

    public function testProtectedContentAndLongerDirectiveNamesRemainLiteral(): void
    {
        $source = <<<'TEMPLATE'
<!-- @style('/fake-comment.css') @push('head') -->
<style>@media screen { .x::after { content: "@style('/fake-css.css')"; } }</style>
<script>const marker = "@script('/fake-js.js')";</script>
<?php $literal = "@style('/fake-php.css')"; ?>
@php $other = "@stack('fake')"; @endphp
@stylesheet('/literal.css') @scriptsLater('/literal.js') @pushSomething('head')
@style('/real.css')
TEMPLATE;

        $compiled = $this->compile($source);

        self::assertStringNotContainsString('/fake-comment.css', $compiled);
        self::assertStringContainsString('/fake-css.css', $compiled);
        self::assertStringContainsString('/fake-js.js', $compiled);
        self::assertStringContainsString('/fake-php.css', $compiled);
        self::assertStringContainsString("@stack('fake')", $compiled);
        self::assertStringContainsString('@stylesheet', $compiled);
        self::assertStringContainsString('@scriptsLater', $compiled);
        self::assertStringContainsString('@pushSomething', $compiled);
        self::assertStringContainsString('/real.css', $compiled);
        self::assertStringNotContainsString("@style('/real.css')", $compiled);
    }

    private function compile(string $source, string $view = 'Pages.AssetGrammar'): string
    {
        return (new TemplateCompiler())->compile($source, $view);
    }
}
