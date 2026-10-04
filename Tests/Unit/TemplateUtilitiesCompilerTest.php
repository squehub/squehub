<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/** The @json exception to raw script text is deliberately narrow. */
final class TemplateUtilitiesCompilerTest extends TestCase
{
    public function testJsonCompilesBalancedMultilineExpressionWithEitherLineEnding(): void
    {
        $source = <<<'VIEW'
<script>
const data = @json(
    [
        'user' => normalize($user, ['level' => nested(1, 2)]),
        'message' => 'a ) and , inside a string',
    ]
);
</script>
VIEW;

        foreach (["\n", "\r\n"] as $ending) {
            $compiled = $this->compile(str_replace("\n", $ending, $source));

            self::assertSame(1, substr_count($compiled, '\\json('));
            self::assertStringContainsString("normalize(\$user, ['level' => nested(1, 2)])", $compiled);
            self::assertStringContainsString("'a ) and , inside a string'", $compiled);
            self::assertStringContainsString('<script>', $compiled);
            self::assertStringContainsString('</script>', $compiled);
        }
    }

    public function testOnlyJsonIsRecognizedInScriptCodeAndProtectedTextStaysLiteral(): void
    {
        $source = <<<'VIEW'
<script>
const double = "@json($data)";
const single = '@json($data)';
const template = `@json($data)`;
const regex = /@json\($data\)/;
// @json($data)
/* @json($data) */
<!-- @json($data)
const other = '@if($flag)';
@if($flag)
const value = @json($data);
</script>
<style>@json($data) @media all { body { color: red; } }</style>
VIEW;

        $compiled = $this->compile($source);

        self::assertSame(1, substr_count($compiled, '\\json('));
        self::assertSame(7, substr_count($compiled, '@json($data)'));
        self::assertStringContainsString('@if($flag)', $compiled);
        self::assertStringContainsString('<style>@json($data) @media', $compiled);
    }

    public function testPhpHtmlCommentsAndDirectiveNameBoundariesStayProtected(): void
    {
        $source = <<<'VIEW'
<?php
$literal = '@json($data)';
// @json($data)
?>
<!-- @json($data) -->
person@json.example @jsonData($data) @jsonify($data)
@json($data)
VIEW;

        $compiled = $this->compile($source);

        self::assertSame(1, substr_count($compiled, '\\json('));
        self::assertStringContainsString("\$literal = '@json(\$data)'", $compiled);
        self::assertStringContainsString('person@json.example', $compiled);
        self::assertStringContainsString('@jsonData($data) @jsonify($data)', $compiled);
        self::assertStringNotContainsString('<!--', $compiled);
    }

    public function testRegexAfterKeywordAndTemplateInterpolationStayProtected(): void
    {
        $source = <<<'VIEW'
<script>
function first() { return /@json($data)/; }
function second() { return /[a-z\/@json($data)]/; }
const text = `before ${@json($data)} \` after @json($data)`;
</script>
VIEW;

        self::assertSame($source, $this->compile($source));
    }

    public function testRegexAfterControlConditionStaysLiteralBeforeJsonValue(): void
    {
        $source = <<<'VIEW'
<script>if (ok) /@json($data)/.test(value); const payload = @json($data);</script>
VIEW;

        $compiled = $this->compile($source);

        self::assertStringContainsString('/@json($data)/.test(value)', $compiled);
        self::assertSame(1, substr_count($compiled, '\\json('));
    }

    /** @dataProvider malformedJson */
    public function testMalformedJsonReportsSourceAwareDiagnostics(
        string $source,
        string $ending
    ): void {
        $view = 'Pages.JsonBroken';
        $source = str_replace("\n", $ending, "first\nsecond\n" . $source);

        try {
            $this->compile($source, $view);
            self::fail('Expected @json compiler diagnostic.');
        } catch (CompilerException $exception) {
            self::assertSame($view, $exception->view());
            self::assertSame(3, $exception->sourceLine());
            self::assertStringContainsString('@json', $exception->getMessage());
            self::assertStringNotContainsString('first' . $ending . 'second',
                $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedJson(): iterable
    {
        foreach (["\n" => 'LF', "\r\n" => 'CRLF'] as $ending => $label) {
            yield "missing expression $label" => ['@json', $ending];
            yield "empty expression $label" => ['@json()', $ending];
            yield "multiple expressions $label" => ['@json($left, $right)', $ending];
            yield "unbalanced expression $label" => ['@json([1, 2)', $ending];
        }
    }

    public function testJsonExpressionEvaluatesOnceAtRuntimeAndCachedPhpUsesNewData(): void
    {
        $compiled = $this->compile('<script>window.data = @json($provider->value());</script>');
        $provider = new class {
            public int $calls = 0;

            /** @return array{value: int} */
            public function value(): array
            {
                return ['value' => ++$this->calls];
            }
        };

        $first = $this->render($compiled, ['provider' => $provider]);
        $second = $this->render($compiled, ['provider' => $provider]);

        self::assertSame(2, $provider->calls);
        self::assertStringContainsString('window.data = {"value":1}', $first);
        self::assertStringContainsString('window.data = {"value":2}', $second);
    }

    public function testJsonScriptBreakoutIsEncodedAndSkippedBranchIsLazy(): void
    {
        $payload = ['unsafe' => '</script><script>alert(1)</script>',
            'unicode' => 'Ẹ káàbọ̀ こんにちは مرحبا 😀'];
        $output = $this->render($this->compile(
            '<script>window.data = @json($payload);</script>'
        ), ['payload' => $payload]);

        self::assertStringNotContainsString('</script><script>alert(1)</script>', $output);
        self::assertMatchesRegularExpression('/window\.data = (.+);<\/script>/s', $output);
        preg_match('/window\.data = (.+);<\/script>/s', $output, $matches);
        self::assertSame($payload, json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR));

        $provider = new class {
            public int $calls = 0;
            public function value(): int { return ++$this->calls; }
        };
        self::assertSame('', $this->render($this->compile(
            '@if(false)@json($provider->value())@endif'
        ), ['provider' => $provider]));
        self::assertSame(0, $provider->calls);
    }

    private function compile(string $source, string $view = 'Pages.Json'): string
    {
        return (new TemplateCompiler())->compile($source, $view);
    }

    /** @param array<string, mixed> $variables */
    private function render(string $compiled, array $variables): string
    {
        require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

        return (static function (string $compiled, array $variables): string {
            extract($variables, EXTR_SKIP);
            ob_start();
            try {
                eval('?>' . $compiled);
                return (string) ob_get_clean();
            } catch (\Throwable $exception) {
                ob_end_clean();
                throw $exception;
            }
        })($compiled, $variables);
    }
}
