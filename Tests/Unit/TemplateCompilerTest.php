<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/** Checks the lexical contract independently of the View renderer and cache. */
final class TemplateCompilerTest extends TestCase
{
    public function testUnknownAtTextAndOrdinaryMarkupStayByteForByte(): void
    {
        $source = <<<'TEMPLATE'
<style>
@media (max-width: 800px) { .card { color: red; } }
</style>
<script>const value = "@include('not-a-template-directive')"; const user = '@username';</script>
<p>support@example.com @example @futureDirective($flag) @includeIf('fake') @sectionSomething foo@include('fake')</p>
TEMPLATE;

        self::assertSame($source, $this->compile($source));
    }

    public function testRawPhpStringsCommentsAndShortEchoRemainPhp(): void
    {
        $source = <<<'TEMPLATE'
<?php
$directive = '@include("fake")';
$echo = '{{ fake }}';
// @section('fake') {{ also_fake }}
/* {!! still_fake !!} @csrf */
?>
<?= $directive ?>
TEMPLATE;

        self::assertSame($source, $this->compile($source));
    }

    public function testQuotedEchoClosersArePartOfTheirPhpExpressions(): void
    {
        $source = <<<'TEMPLATE'
{{ json_encode(['closing' => '}}', 'nested' => ['x' => 1]]) }}
{!! implode('', ['!!}', '<b>trusted</b>']) !!}
TEMPLATE;

        $compiled = $this->compile($source);
        self::assertSame(1, substr_count($compiled, 'ViewEscaper::escape('));
        self::assertSame(1, substr_count($compiled, 'ViewEscaper::raw('));
        self::assertStringContainsString("'closing' => '}}'", $compiled);
        self::assertStringContainsString("'!!}'", $compiled);
    }

    public function testCompilationIsDeterministicAndDoesNotEmbedRuntimeSecrets(): void
    {
        $source = '{{ $title }}|@csrf';
        $first = $this->compile($source);
        $second = $this->compile($source);

        self::assertSame($first, $second);
        self::assertStringContainsString('ViewEscaper::escape(', $first);
        self::assertStringContainsString('csrf_field()', $first);
        self::assertStringNotContainsString('PRIVATE_RUNTIME_MARKER', $first);
    }

    public function testUtf8LiteralTextIsUnchangedAndLineNumbersCountNewlines(): void
    {
        $literal = "Hello\nこんにちは\nمرحبا\nẸ káàbọ̀";
        self::assertSame($literal, $this->compile($literal));

        try {
            $this->compile($literal . "\n{{ \$title", 'Pages.Unicode');
            self::fail('Expected an unterminated echo diagnostic.');
        } catch (CompilerException $exception) {
            self::assertStringContainsString('Pages.Unicode', $exception->getMessage());
            self::assertMatchesRegularExpression('/\bline\s+5\b/i', $exception->getMessage());
        }
    }

    public function testHtmlCommentsDoNotCompileHiddenSyntaxAndRetainLineCount(): void
    {
        $source = "before<!-- @include('fake')\r\n{{ \$hidden }} -->after";

        self::assertSame("before\r\nafter", $this->compile($source));
    }

    public function testHtmlCommentsAlsoSuppressRawPhpAcrossLexerTokens(): void
    {
        $source = <<<'TEMPLATE'
before<!-- @include('Fake') <?php echo 'not rendered'; ?>
{{ $hidden }} -->after
TEMPLATE;

        self::assertSame("before\nafter", $this->compile($source));
    }

    public function testHeredocAndNowdocContentsCannotCloseExpressionsOrArguments(): void
    {
        $source = <<<'TEMPLATE'
{{ <<<TEXT
}} is part of a PHP string
TEXT
 }}
@include('Partials.Card', ['title' => <<<'TITLE'
, ) }} is part of a PHP string
TITLE
])
TEMPLATE;

        $compiled = $this->compile($source);
        self::assertSame(1, substr_count($compiled, 'ViewEscaper::escape('));
        self::assertStringContainsString('}} is part of a PHP string', $compiled);
        self::assertStringContainsString(', ) }} is part of a PHP string', $compiled);
        self::assertStringContainsString("View::include('Partials.Card'", $compiled);
    }

    public function testDatetimeNameIsDistinctFromDateShortcut(): void
    {
        $compiled = $this->compile("@datetime('Y (m,d)')|@date");

        self::assertStringContainsString("date('Y (m,d)')", $compiled);
        self::assertStringContainsString('date("Y-m-d")', $compiled);
        self::assertStringNotContainsString('@datetime', $compiled);
        self::assertStringNotContainsString('@date', $compiled);
    }

    public function testUtf8BomIsRejectedBeforeItCanBecomeResponseOutput(): void
    {
        try {
            $this->compile("\xEF\xBB\xBF<p>Welcome</p>", 'Pages.Bom');
            self::fail('Expected a BOM diagnostic.');
        } catch (CompilerException $exception) {
            self::assertStringContainsString('Pages.Bom', $exception->getMessage());
            self::assertMatchesRegularExpression('/\bline\s+1\b/i', $exception->getMessage());
            self::assertStringContainsString('BOM', $exception->getMessage());
            self::assertStringNotContainsString("\xEF\xBB\xBF", $exception->getMessage());
        }
    }

    /** @dataProvider malformedTemplates */
    public function testMalformedConstructsReportTheirLogicalViewAndSourceLine(
        string $malformed,
        string $construct,
        string $lineEnding
    ): void {
        $source = str_replace("\n", $lineEnding, "first\nsecond\n" . $malformed);

        try {
            $this->compile($source, 'Pages.Broken');
            self::fail('Expected a compiler diagnostic for ' . $construct);
        } catch (CompilerException $exception) {
            self::assertSame('Pages.Broken', $exception->view());
            self::assertSame(3, $exception->sourceLine());
            self::assertStringContainsString('Pages.Broken', $exception->getMessage());
            self::assertMatchesRegularExpression('/\bline\s+3\b/i', $exception->getMessage());
            self::assertStringContainsString($construct, $exception->getMessage());
            self::assertStringNotContainsString('first' . $lineEnding . 'second', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function malformedTemplates(): iterable
    {
        foreach (["\n" => 'LF', "\r\n" => 'CRLF'] as $lineEnding => $label) {
            yield "unclosed escaped echo $label" => ['{{ $title', '{{', $lineEnding];
            yield "unclosed raw echo $label" => ['{!! $trustedHtml', '{!!', $lineEnding];
            yield "unclosed directive $label" => ["@include(\n    'Partials.Card',\n    ['title' => \$title,", '@include', $lineEnding];
            yield "unclosed quote $label" => ["@include('Partials.Card, ['title' => 'x'])", '@include', $lineEnding];
        }
    }

    private function compile(string $source, string $view = 'Pages.Lexical'): string
    {
        return (new TemplateCompiler())->compile($source, $view);
    }
}
