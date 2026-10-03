<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/** Phase 14E layout grammar and source diagnostics, independent of View rendering. */
final class LayoutCompilerTest extends TestCase
{
    public function testExtendsIsOneTemplateDeclarationWithBalancedOptionalData(): void
    {
        $top = <<<'TEMPLATE'
@extends(
    'Layouts.Main',
    ['title' => strtoupper(trim($title)), 'settings' => ['compact' => true]]
)
@section('content')<main>{{ $title }}</main>@endsection
TEMPLATE;
        $bottom = <<<'TEMPLATE'
@section('content')<main>{{ $title }}</main>@endsection
@extends('Layouts.Main')
TEMPLATE;

        $topCompiled = $this->compile($top);
        $bottomCompiled = $this->compile($bottom);

        self::assertStringContainsString('Layouts.Main', $topCompiled);
        self::assertStringContainsString('strtoupper(trim($title))', $topCompiled);
        self::assertStringContainsString("'compact' => true", $topCompiled);
        self::assertStringContainsString('<main>', $topCompiled);
        self::assertStringNotContainsString('@extends', $topCompiled);
        self::assertStringNotContainsString('@section', $topCompiled);
        self::assertStringContainsString('Layouts.Main', $bottomCompiled);
        self::assertStringNotContainsString('@extends', $bottomCompiled);
    }

    public function testDottedLiteralSectionNamesAndControlFlowInsideBodyCompile(): void
    {
        $source = <<<'TEMPLATE'
@section('page.actions')
@if ($show)
    @foreach ($actions as $action)
        <button>{{ $loop->iteration }}:{{ $action }}</button>
    @endforeach
@endif
@endsection
@yield('page.actions', $fallback ?? 'No actions')
TEMPLATE;

        $compiled = $this->compile($source);

        self::assertStringContainsString('page.actions', $compiled);
        self::assertStringContainsString('if ($show)', $compiled);
        self::assertStringContainsString('$actions', $compiled);
        self::assertStringContainsString('$fallback ?? \'No actions\'', $compiled);
        self::assertStringNotContainsString('@section', $compiled);
        self::assertStringNotContainsString('@endsection', $compiled);
        self::assertStringNotContainsString('@yield', $compiled);
    }

    public function testYieldRetainsRuntimeDefaultExpressionAndNoDefaultForm(): void
    {
        $compiled = $this->compile(
            "@yield('title', \$title ?? expensiveFallback())|@yield('optional')"
        );

        self::assertStringContainsString('$title ?? expensiveFallback()', $compiled);
        self::assertStringContainsString('title', $compiled);
        self::assertStringContainsString('optional', $compiled);
        self::assertStringContainsString('|', $compiled);
        self::assertStringNotContainsString('@yield', $compiled);
        // The compiler emits executable PHP; it must not evaluate the default
        // or substitute request values while producing that PHP.
        self::assertSame($compiled, $this->compile(
            "@yield('title', \$title ?? expensiveFallback())|@yield('optional')"
        ));
    }

    /** @dataProvider invalidLayoutStructures */
    public function testInvalidStructuresReportLogicalViewAndExactSourceLine(
        string $source,
        int $line,
        string $directive
    ): void {
        $this->assertInvalid($source, $line, $directive);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidLayoutStructures(): iterable
    {
        yield 'duplicate extends' => [
            "@extends('Layouts.One')\n@extends('Layouts.Two')", 2, 'extends',
        ];
        yield 'duplicate identical extends' => [
            "@extends('Layouts.One')\n@extends('Layouts.One')", 2, 'extends',
        ];
        yield 'extends inside if' => [
            "@if(\$choose)\n@extends('Layouts.One')\n@endif", 2, 'extends',
        ];
        yield 'extends inside foreach' => [
            "@foreach(\$items as \$item)\n@extends('Layouts.One')\n@endforeach", 2, 'extends',
        ];
        yield 'extends inside switch' => [
            "@switch(\$choice)\n@case(1)\n@extends('Layouts.One')\n@endswitch", 3, 'extends',
        ];
        yield 'extends inside section' => [
            "@section('content')\n@extends('Layouts.One')\n@endsection", 2, 'extends',
        ];
        yield 'dynamic extends' => ['@extends($layout)', 1, 'extends'];
        yield 'extends without arguments' => ['@extends', 1, 'extends'];
        yield 'too many extends arguments' => [
            "@extends('Layouts.One', [], [])", 1, 'extends',
        ];
        yield 'duplicate section in same template' => [
            "@section('content')A@endsection\n@section('content')B@endsection", 2, 'section',
        ];
        yield 'nested sections' => [
            "@section('outer')\n@section('inner')X@endsection\n@endsection", 2, 'section',
        ];
        yield 'unmatched endsection' => ["A\n@endsection", 2, 'endsection'];
        yield 'unclosed section' => ["@section('content')\nBody", 1, 'section'];
        yield 'inline section unsupported' => [
            "@section('title', 'Dashboard')", 1, 'section',
        ];
        yield 'empty section name' => ["@section('')", 1, 'section'];
        yield 'dynamic section name' => ['@section($name)', 1, 'section'];
        yield 'invalid dotted section name' => ["@section('page..actions')", 1, 'section'];
        yield 'section without arguments' => ['@section', 1, 'section'];
        yield 'endsection with arguments' => [
            "@section('content')A@endsection('content')", 1, 'endsection',
        ];
        yield 'empty yield name' => ["@yield('')", 1, 'yield'];
        yield 'dynamic yield name' => ['@yield($name)', 1, 'yield'];
        yield 'too many yield arguments' => ["@yield('title', 'A', 'B')", 1, 'yield'];
        yield 'yield without arguments' => ['@yield', 1, 'yield'];
    }

    /** @dataProvider lineEndings */
    public function testDuplicateSectionLineIsAccurateWithLfAndCrLf(string $lineEnding): void
    {
        $source = str_replace("\n", $lineEnding,
            "Heading\n@section('title')A@endsection\nOther\n@section('title')B@endsection"
        );

        $this->assertInvalid($source, 4, 'section');
    }

    /** @return iterable<string, array{string}> */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
    }

    public function testProtectedContentDoesNotDeclareLayoutsOrSections(): void
    {
        $source = <<<'TEMPLATE'
<style>.literal::after { content: "@section('fake')"; }</style>
<script>const layout = "@extends('Fake')";</script>
<p>@extendsSomething @sectionName @yieldExtra @endsectionAfter</p>
<?php $literal = '@extends("Fake") @section("fake")'; ?>
@php $other = '@endsection @yield("fake")'; @endphp
<!-- @extends('Hidden') @section('hidden') @endsection -->
@section('content')Real@endsection
TEMPLATE;

        $compiled = $this->compile($source);

        self::assertStringContainsString("@section('fake')", $compiled);
        self::assertStringContainsString("@extends('Fake')", $compiled);
        self::assertStringContainsString('@extendsSomething @sectionName @yieldExtra @endsectionAfter', $compiled);
        self::assertStringContainsString('$literal =', $compiled);
        self::assertStringContainsString('$other =', $compiled);
        self::assertStringNotContainsString('Hidden', $compiled);
        self::assertStringContainsString('Real', $compiled);
    }

    public function testCompilerCanBeReusedAfterStructuralError(): void
    {
        $compiler = new TemplateCompiler();
        try {
            $compiler->compile("@section('title')A@endsection@section('title')B@endsection",
                'Pages.Invalid');
            self::fail('Expected a duplicate section error.');
        } catch (CompilerException $exception) {
            self::assertSame('Pages.Invalid', $exception->view());
        }

        $valid = "@extends('Layouts.Main')@section('title')OK@endsection";
        self::assertSame($compiler->compile($valid, 'Pages.Valid'),
            $compiler->compile($valid, 'Pages.Valid'));
        self::assertStringContainsString('Other',
            $compiler->compile("@section('content')Other@endsection", 'Pages.Other'));
    }

    private function assertInvalid(string $source, int $line, string $directive): void
    {
        try {
            $this->compile($source, 'Pages.Invalid');
            self::fail('Expected a compiler error for ' . $directive);
        } catch (CompilerException $exception) {
            self::assertSame('Pages.Invalid', $exception->view());
            self::assertSame($line, $exception->sourceLine());
            self::assertStringContainsString('Pages.Invalid', $exception->getMessage());
            self::assertStringContainsString($directive, strtolower($exception->getMessage()));
        }
    }

    private function compile(string $source, string $view = 'Pages.LayoutGrammar'): string
    {
        return (new TemplateCompiler())->compile($source, $view);
    }
}
