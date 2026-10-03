<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/** Phase 14C control-flow syntax and diagnostics, independent of View rendering. */
final class ConditionalCompilerTest extends TestCase
{
    public function testIfElseifElseAndUnlessEmitRuntimePhpWithoutChangingLiteralText(): void
    {
        $source = '@if($first)<b>A</b>@elseif ($second)<b>B</b>@else <b>C</b>@endif'
            . '@unless ($disabled)<i>Enabled</i>@else <i>Disabled</i>@endunless';

        $compiled = $this->compile($source);

        self::assertStringContainsString('if ($first)', $compiled);
        self::assertStringContainsString('elseif ($second)', $compiled);
        self::assertStringContainsString('<b>A</b>', $compiled);
        self::assertStringContainsString('<b>B</b>', $compiled);
        self::assertStringContainsString('<b>C</b>', $compiled);
        self::assertMatchesRegularExpression('/if\s*\(\s*!\s*\(\s*\$disabled\s*\)\s*\)/', $compiled);
        self::assertSame(2, substr_count($compiled, 'else:'));
        self::assertStringNotContainsString('@unless', $compiled);
        self::assertStringNotContainsString('@endunless', $compiled);
    }

    public function testSwitchLabelsAndBreakCompileWithWhitespaceBeforeFirstCase(): void
    {
        $source = <<<'TEMPLATE'
@switch($status)
    @case('ready')Ready@break
    @default Unknown
@endswitch
TEMPLATE;

        $compiled = $this->compile($source);

        self::assertStringContainsString('switch ($status)', $compiled);
        self::assertMatchesRegularExpression("/case\\s*\\(?'ready'\\)?\\s*:/", $compiled);
        self::assertStringContainsString('break;', $compiled);
        self::assertStringContainsString('default:', $compiled);
        self::assertStringContainsString('endswitch;', $compiled);
        self::assertStringNotContainsString('@case', $compiled);
        self::assertStringNotContainsString('@default', $compiled);
    }

    public function testMultilineBalancedExpressionsAndProtectedTextRetainTheirMeaning(): void
    {
        $source = <<<'TEMPLATE'
<script>const example = "@if ($fake)";</script>
<!-- @if ($hidden) hidden @endif -->
<?php $literal = '@case("fake")'; ?>
@php $another = '@endswitch'; @endphp
@if (
    in_array($state, ['ready', 'hello, world', ')', '@endif'], true)
    && (match ($state) { 'ready' => true, default => false })
)
    こんにちは
@endif
@switch (
    resolveStatus($order, ['fallback' => 'a, b'])
)
    @case (Status::ACTIVE->value)
        مرحبا
        @break
@endswitch
TEMPLATE;

        $compiled = $this->compile($source);

        self::assertStringContainsString('const example = "@if ($fake)";', $compiled);
        self::assertStringNotContainsString('$hidden', $compiled);
        self::assertStringContainsString('$literal = \'@case("fake")\'', $compiled);
        self::assertStringContainsString('$another = \'@endswitch\'', $compiled);
        self::assertStringContainsString("'hello, world', ')', '@endif'", $compiled);
        self::assertStringContainsString('match ($state)', $compiled);
        self::assertStringContainsString("resolveStatus(\$order, ['fallback' => 'a, b'])", $compiled);
        self::assertStringContainsString('Status::ACTIVE->value', $compiled);
        self::assertStringContainsString('こんにちは', $compiled);
        self::assertStringContainsString('مرحبا', $compiled);
    }

    public function testUnknownAtNamesRemainWholeLiteralNames(): void
    {
        $source = '<p>support@example.com @customThing($flag) @elseB @defaultB</p>';

        self::assertSame($source, $this->compile($source));
    }

    public function testSwitchRejectsTemplateContentBeforeItsFirstLabel(): void
    {
        try {
            $this->compile("@switch(\$state)\nUnexpected output\n@case('ready')A@endswitch",
                'Pages.EarlySwitchOutput');
            self::fail('Expected a compiler error before the first switch label.');
        } catch (CompilerException $exception) {
            self::assertSame('Pages.EarlySwitchOutput', $exception->view());
            self::assertSame(2, $exception->sourceLine());
            self::assertStringContainsString('@switch', $exception->getMessage());
        }
    }

    public function testBareElseAllowsParenthesizedLiteralOnFollowingLine(): void
    {
        $compiled = $this->compile("@if(\$show)A@else\n(literal)@endif");

        self::assertStringContainsString('<?php else: ?>', $compiled);
        self::assertStringContainsString("\n(literal)", $compiled);
    }

    /** @dataProvider invalidStructures */
    public function testInvalidStructuresReportViewLineAndDirective(
        string $source,
        int $line,
        string $directive
    ): void {
        $source = "intro\n" . $source;

        try {
            $this->compile($source, 'Pages.Invalid');
            self::fail('Expected a compiler error for ' . $directive);
        } catch (CompilerException $exception) {
            self::assertSame('Pages.Invalid', $exception->view());
            self::assertSame($line + 1, $exception->sourceLine());
            self::assertStringContainsString($directive, $exception->getMessage());
            self::assertStringNotContainsString('intro', $exception->getMessage());
            self::assertStringNotContainsString(BASE_DIR, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidStructures(): iterable
    {
        yield 'elseif outside if' => ['@elseif($x)', 1, '@elseif'];
        yield 'else outside conditional' => ['@else', 1, '@else'];
        yield 'endif without if' => ['@endif', 1, '@endif'];
        yield 'endunless without unless' => ['@endunless', 1, '@endunless'];
        yield 'endswitch without switch' => ['@endswitch', 1, '@endswitch'];
        yield 'case outside switch' => ["@case('a')", 1, '@case'];
        yield 'default outside switch' => ['@default', 1, '@default'];
        yield 'break outside switch' => ['@break', 1, '@break'];
        yield 'duplicate else' => ['@if($x)A@else B@else C@endif', 1, '@else'];
        yield 'elseif after else' => ['@if($x)A@else B @elseif($y)C@endif', 1, '@elseif'];
        yield 'elseif on unless' => ['@unless($x)A @elseif($y)B@endunless', 1, '@elseif'];
        yield 'duplicate default' => ['@switch($x)@default A@default B@endswitch', 1, '@default'];
        yield 'mismatched closing type' => ["@if(\$x)\n@endunless", 2, '@endunless'];
        yield 'unclosed if' => ['@if($x)A', 1, '@if'];
        yield 'unclosed unless' => ['@unless($x)A', 1, '@unless'];
        yield 'unclosed switch' => ["@switch(\$x)\n@case('a')A", 1, '@switch'];
        yield 'empty if' => ['@if()A@endif', 1, '@if'];
        yield 'empty elseif' => ['@if($x)A @elseif()B@endif', 1, '@elseif'];
        yield 'empty unless' => ['@unless()A@endunless', 1, '@unless'];
        yield 'empty switch' => ['@switch()@default A@endswitch', 1, '@switch'];
        yield 'empty case' => ['@switch($x)@case()A@endswitch', 1, '@case'];
        yield 'missing if parentheses' => ['@if $x', 1, '@if'];
        yield 'missing unless parentheses' => ['@unless $x', 1, '@unless'];
        yield 'missing switch parentheses' => ['@switch $x', 1, '@switch'];
        yield 'else with arguments' => ['@if($x)@else($y)@endif', 1, '@else'];
        yield 'default with arguments' => ['@switch($x)@default($y)@endswitch', 1, '@default'];
    }

    public function testCompilerReuseAfterFailureAndAfterSuccessfulNestedTemplate(): void
    {
        $compiler = new TemplateCompiler();
        try {
            $compiler->compile('@if($x)', 'Pages.Broken');
            self::fail('Expected an unclosed conditional.');
        } catch (CompilerException $exception) {
            self::assertSame('Pages.Broken', $exception->view());
        }

        $valid = '@if($enabled)@switch($state)@case(1)One@break@default Other@endswitch@endif';
        self::assertSame(
            $compiler->compile($valid, 'Pages.Valid'),
            $compiler->compile($valid, 'Pages.Valid')
        );
        self::assertStringContainsString('if ($other)',
            $compiler->compile('@if($other)Other@endif', 'Pages.Other'));
    }

    private function compile(string $source, string $view = 'Pages.Conditionals'): string
    {
        return (new TemplateCompiler())->compile($source, $view);
    }
}
