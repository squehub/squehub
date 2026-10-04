<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/** Phase 14D syntax and diagnostics independent of the View renderer. */
final class LoopCompilerTest extends TestCase
{
    public function testIterableAndNativeLoopsCompileFromBalancedMultilineExpressions(): void
    {
        $source = <<<'TEMPLATE'
@foreach (
    collectUsers($team, ['status' => 'active', 'as' => 'literal'])
    as
    $key => $user
)
    {{ $key }}:{{ $user->name }}
@endforeach
@forelse ($users as $user)
    {{ $user->name }}
@empty
    Empty
@endforelse
@for (
    $i = startIndex();
    shouldContinue($i);
    $i = nextIndex($i)
)
    {{ $i }}
@endfor
@while (
    $queue->hasItems()
)
    {{ $queue->take() }}
@endwhile
TEMPLATE;

        $compiled = $this->compile($source);
        foreach (['@foreach', '@endforeach', '@forelse', '@empty', '@endforelse',
            '@for', '@endfor', '@while', '@endwhile'] as $directive) {
            self::assertStringNotContainsString($directive, $compiled, $directive);
        }
        self::assertStringContainsString("collectUsers(\$team, ['status' => 'active', 'as' => 'literal'])", $compiled);
        self::assertStringContainsString('shouldContinue($i)', $compiled);
        self::assertStringContainsString('$queue->hasItems()', $compiled);
        self::assertSame(5, substr_count($compiled, 'ViewEscaper::escape('));
    }

    public function testIterableExpressionsAppearExactlyOnceInCompiledRuntimePhp(): void
    {
        $foreach = $this->compile("@foreach (loadUsers('as') as \$user){{ \$user }}@endforeach");
        $forelse = $this->compile("@forelse (loadReports('as') as \$report){{ \$report }}@empty None@endforelse");

        self::assertSame(1, substr_count($foreach, "loadUsers('as')"));
        self::assertSame(1, substr_count($forelse, "loadReports('as')"));
    }

    public function testTopLevelAsIsNotConfusedWithQuotedOrNestedAs(): void
    {
        $source = <<<'TEMPLATE'
@foreach (makeItems('as', fn ($value) => ['as' => $value]) as $key => $item)
    {{ $key }}:{{ $item }}
@endforeach
TEMPLATE;

        $compiled = $this->compile($source);
        self::assertSame(1, substr_count($compiled, "makeItems('as', fn (\$value) => ['as' => \$value])"));
        self::assertStringNotContainsString('@foreach', $compiled);
    }

    public function testConditionsAndSwitchesCanNestWithLoopsAndBreakContinue(): void
    {
        $source = <<<'TEMPLATE'
@if ($show)
    @forelse ($items as $item)
        @if ($item->hidden)
            @continue
        @endif
        @switch ($item->status)
            @case('done')
                @break
            @default
                {{ $item->status }}
        @endswitch
    @empty
        None
    @endforelse
@endif
@switch ($mode)
    @case('all')
        @foreach ($items as $item)
            @break
        @endforeach
        @break
@endswitch
TEMPLATE;

        $compiled = $this->compile($source);
        self::assertStringContainsString('continue;', $compiled);
        self::assertSame(3, substr_count($compiled, 'break;'));
        self::assertStringNotContainsString('@forelse', $compiled);
    }

    public function testNativeForAndWhileAcceptLoopControlWithoutFabricatedMetadata(): void
    {
        $source = <<<'TEMPLATE'
@for ($i = 0; $i < 3; ++$i)
    @if ($i === 1)@continue@endif
    @break
@endfor
@while ($queue->hasItems())
    @continue
    @break
@endwhile
TEMPLATE;

        $compiled = $this->compile($source);
        self::assertStringContainsString('for ($i = 0; $i < 3; ++$i)', $compiled);
        self::assertStringContainsString('while ($queue->hasItems())', $compiled);
        self::assertSame(2, substr_count($compiled, 'continue;'));
        self::assertSame(2, substr_count($compiled, 'break;'));
        self::assertStringNotContainsString('new \\App\\View\\LoopContext', $compiled);
    }

    public function testContinueCannotMistakeSwitchForOuterLoop(): void
    {
        $valid = "@switch(\$mode)@case('all')@foreach(\$items as \$item)@continue@endforeach@break@endswitch";
        self::assertStringContainsString('continue;', $this->compile($valid));

        $invalid = "@foreach(\$items as \$item)@switch(\$mode)@case('all')@continue@endswitch@endforeach";
        $this->assertInvalid($invalid, 1, '@continue');
        $this->assertInvalid("@switch(\$mode)@case('all')@continue@endswitch", 1, '@continue');
    }

    /** @dataProvider invalidLoopStructures */
    public function testInvalidLoopsReportLogicalViewAndExactSourceLine(
        string $source,
        int $line,
        string $directive
    ): void {
        $this->assertInvalid("intro\n" . $source, $line + 1, $directive);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidLoopStructures(): iterable
    {
        yield 'unmatched endforeach' => ['@endforeach', 1, '@endforeach'];
        yield 'unmatched endforelse' => ['@endforelse', 1, '@endforelse'];
        yield 'unmatched endfor' => ['@endfor', 1, '@endfor'];
        yield 'unmatched endwhile' => ['@endwhile', 1, '@endwhile'];
        yield 'empty outside forelse' => ['@empty', 1, '@empty'];
        yield 'unclosed foreach' => ['@foreach($items as $item)', 1, '@foreach'];
        yield 'unclosed forelse' => ['@forelse($items as $item)@empty None', 1, '@forelse'];
        yield 'unclosed for' => ['@for($i = 0; $i < 2; ++$i)', 1, '@for'];
        yield 'unclosed while' => ['@while($ready)', 1, '@while'];
        yield 'mismatched endforeach' => ['@while($ready)@endforeach', 1, '@endforeach'];
        yield 'mismatched endforelse' => ['@foreach($items as $item)@endforelse', 1, '@endforelse'];
        yield 'mismatched endfor' => ['@foreach($items as $item)@endfor', 1, '@endfor'];
        yield 'mismatched endwhile' => ['@for($i=0;$i<2;++$i)@endwhile', 1, '@endwhile'];
        yield 'duplicate empty' => ['@forelse($items as $item)@empty A@empty B@endforelse', 1, '@empty'];
        yield 'empty belongs to nearest forelse' => ['@forelse($items as $item)@if($show)@empty@endif@endforelse', 1, '@empty'];
        yield 'break outside loop or switch' => ['@break', 1, '@break'];
        yield 'continue outside loop' => ['@continue', 1, '@continue'];
        yield 'break inside only if' => ['@if($show)@break@endif', 1, '@break'];
        yield 'continue inside only if' => ['@if($show)@continue@endif', 1, '@continue'];
        yield 'empty foreach clause' => ['@foreach()@endforeach', 1, '@foreach'];
        yield 'missing foreach as' => ['@foreach($items)@endforeach', 1, '@foreach'];
        yield 'empty forelse clause' => ['@forelse()@empty@endforelse', 1, '@forelse'];
        yield 'missing forelse as' => ['@forelse($items)@empty@endforelse', 1, '@forelse'];
        yield 'missing foreach parentheses' => ['@foreach $items as $item', 1, '@foreach'];
        yield 'missing forelse parentheses' => ['@forelse $items as $item', 1, '@forelse'];
        yield 'empty for expression' => ['@for()@endfor', 1, '@for'];
        yield 'empty while expression' => ['@while()@endwhile', 1, '@while'];
        yield 'empty arguments' => ['@forelse($items as $item)@empty($flag)@endforelse', 1, '@empty'];
        yield 'break arguments' => ['@foreach($items as $item)@break(2)@endforeach', 1, '@break'];
        yield 'continue arguments' => ['@foreach($items as $item)@continue(2)@endforeach', 1, '@continue'];
        yield 'by-reference foreach target' => ['@foreach($items as &$item)@endforeach', 1, '@foreach'];
        yield 'framework loop target' => ['@foreach($items as $loop)@endforeach', 1, '@foreach'];
        yield 'framework internal target' => ['@foreach($items as $__squehub_item)@endforeach', 1, '@foreach'];
    }

    /** @dataProvider lineEndings */
    public function testInvalidEmptyHasCorrectLineWithLfAndCrLf(string $lineEnding): void
    {
        $source = str_replace("\n", $lineEnding,
            "Heading\n@foreach(\$items as \$item)\n@empty\n@endforeach");
        $this->assertInvalid($source, 3, '@empty');
    }

    /** @return iterable<string, array{string}> */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
    }

    public function testProtectedPhpCommentsScriptStyleAndUnknownNamesStayLiteral(): void
    {
        $source = <<<'TEMPLATE'
<style>@media (min-width: 1px) { .x { color: red; } }</style>
<script>const syntax = '@foreach($fake as $item)@endforeach';</script>
<p>support@example.com @emptyState @endforeachExtra @continueLater</p>
<?php $literal = '@forelse($fake as $item)'; // @empty @endforelse ?>
@php $other = '@continue'; @endphp
<!-- @foreach($hidden as $value)
@endforeach -->
TEMPLATE;

        $compiled = $this->compile($source);
        self::assertStringContainsString('@media (min-width: 1px)', $compiled);
        self::assertStringContainsString("const syntax = '@foreach(\$fake as \$item)@endforeach'", $compiled);
        self::assertStringContainsString('support@example.com @emptyState @endforeachExtra @continueLater', $compiled);
        self::assertStringContainsString("\$literal = '@forelse(\$fake as \$item)'", $compiled);
        self::assertStringContainsString("\$other = '@continue'", $compiled);
        self::assertStringNotContainsString('$hidden', $compiled);
    }

    public function testCompilerCanBeReusedAfterMalformedLoop(): void
    {
        $compiler = new TemplateCompiler();
        try {
            $compiler->compile('@forelse($items as $item)@empty', 'Pages.Broken');
            self::fail('Expected an unclosed forelse.');
        } catch (CompilerException $exception) {
            self::assertSame('Pages.Broken', $exception->view());
        }

        $valid = '@foreach($items as $item){{ $item }}@endforeach';
        self::assertSame($compiler->compile($valid, 'Pages.Valid'),
            $compiler->compile($valid, 'Pages.Valid'));
        self::assertStringContainsString('while ($ready)',
            $compiler->compile('@while($ready)@break@endwhile', 'Pages.Other'));
    }

    private function compile(string $source): string
    {
        return (new TemplateCompiler())->compile($source, 'Pages.Loops');
    }

    private function assertInvalid(string $source, int $line, string $directive): void
    {
        try {
            (new TemplateCompiler())->compile($source, 'Pages.InvalidLoops');
            self::fail('Expected a compiler diagnostic for ' . $directive);
        } catch (CompilerException $exception) {
            self::assertSame('Pages.InvalidLoops', $exception->view());
            self::assertSame($line, $exception->sourceLine(), $exception->getMessage());
            self::assertStringContainsString($directive, $exception->getMessage());
            self::assertStringNotContainsString('intro', $exception->getMessage());
            self::assertStringNotContainsString(__DIR__, $exception->getMessage());
        }
    }
}
