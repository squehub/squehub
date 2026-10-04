<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/** Checks include grammar and source diagnostics before any View is executed. */
final class IncludeCompilerTest extends TestCase
{
    public function testRequiredOptionalAndConditionalFormsUseCompiledIncludeCalls(): void
    {
        $source = <<<'TEMPLATE'
@include('Partials.Card')
@includeOptional(
    'Partials.Badge',
    ['mode' => 'compact']
)
@includeWhen(
    $showCard && $canView,
    'Partials.Card',
    ['title' => $title]
)
TEMPLATE;

        $compiled = $this->compile($source);
        self::assertStringNotContainsString('@include', $compiled);
        self::assertStringContainsString('Partials.Card', $compiled);
        self::assertStringContainsString('Partials.Badge', $compiled);
        self::assertStringContainsString("'mode' => 'compact'", $compiled);
        self::assertStringContainsString('$showCard && $canView', $compiled);
        self::assertStringContainsString("'title' => \$title", $compiled);
    }

    /** @dataProvider malformedIncludes */
    public function testMalformedIncludeFormsIdentifyViewAndLine(string $source, string $directive): void
    {
        foreach (["\n", "\r\n"] as $ending) {
            $sourceWithPrefix = str_replace("\n", $ending, "first\nsecond\n" . $source);
            try {
                $this->compile($sourceWithPrefix, 'Pages.Broken');
                self::fail("Expected {$directive} to be rejected.");
            } catch (CompilerException $exception) {
                self::assertSame('Pages.Broken', $exception->view());
                self::assertSame(3, $exception->sourceLine());
                self::assertStringContainsString($directive, $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedIncludes(): iterable
    {
        yield 'required no target' => ['@include()', '@include'];
        yield 'required too many arguments' => ["@include('A', [], [])", '@include'];
        yield 'optional no target' => ['@includeOptional()', '@includeOptional'];
        yield 'optional too many arguments' => ["@includeOptional('A', [], [])", '@includeOptional'];
        yield 'conditional no target' => ['@includeWhen(true)', '@includeWhen'];
        yield 'conditional too many arguments' => ["@includeWhen(true, 'A', [], [])", '@includeWhen'];
        yield 'optional dynamic target' => ['@includeOptional($target)', '@includeOptional'];
        yield 'conditional dynamic target' => ['@includeWhen(true, $target)', '@includeWhen'];
        yield 'unclosed optional' => ["@includeOptional('A'", '@includeOptional'];
        yield 'unclosed conditional' => ["@includeWhen(true, 'A'", '@includeWhen'];
    }

    public function testUnknownIncludePrefixesRemainLiteralText(): void
    {
        $source = "@includeOptionalExtra('Fake') @includeWhenElse(true, 'Fake') "
            . "@includeSomething('Fake')";
        self::assertSame($source, $this->compile($source));
    }

    public function testIncludesInCommentsAndQuotedScriptTextStayInert(): void
    {
        $source = <<<'TEMPLATE'
<!-- @includeOptional('Fake') -->
<script>const example = "@includeWhen(true, 'Fake')";</script>
<?php $example = "@include('Fake')"; ?>
TEMPLATE;

        $compiled = $this->compile($source);
        self::assertStringNotContainsString('View::include(', $compiled);
        self::assertStringContainsString("@includeWhen(true, 'Fake')", $compiled);
        self::assertStringContainsString("@include('Fake')", $compiled);
    }

    private function compile(string $source, string $view = 'Pages.IncludeCompiler'): string
    {
        return (new TemplateCompiler())->compile($source, $view);
    }
}
