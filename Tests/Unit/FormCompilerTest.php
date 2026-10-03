<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Validation\ErrorBag;
use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/** Proves form directives use the shared parser and request-time ErrorBag. */
final class FormCompilerTest extends TestCase
{
    public function testErrorBlockEscapesFirstMessageAndRestoresCallerMessage(): void
    {
        $source = "before:@error('email')<span>{{ \$message }}</span>@enderror:{{ \$message }}";
        [$output, $hasMessage, $message] = $this->render($source,
            new ErrorBag(['email' => ['<unsafe>', 'second']]), 'original', true);

        self::assertSame('before:<span>&lt;unsafe&gt;</span>:original', $output);
        self::assertTrue($hasMessage);
        self::assertSame('original', $message);
    }

    public function testAbsentErrorDoesNotRenderOrLeakMessage(): void
    {
        [$output, $hasMessage] = $this->render(
            "before:@error('email')hidden@enderror:{{ isset(\$message) ? 'leaked' : 'clean' }}",
            new ErrorBag());

        self::assertSame('before::clean', $output);
        self::assertFalse($hasMessage);
    }

    public function testDynamicFieldExpressionAndMethodStayInCompiledRuntimeCode(): void
    {
        $source = "@error('items.' . \$loop->index . '.name'){{ \$message }}@enderror"
            . "@method(\$verb)";
        $first = $this->compile($source);
        $second = $this->compile($source);

        self::assertSame($first, $second);
        self::assertStringContainsString("\$errors->first('items.' . \$loop->index . '.name')", $first);
        self::assertStringContainsString('method_field($verb)', $first);
        self::assertStringNotContainsString('@error', $first);
        self::assertStringNotContainsString('@method', $first);
    }

    public function testSeparateBlocksHaveSeparateMessageState(): void
    {
        $source = "@error('email'){{ \$message }}@enderror"
            . "|@error('name'){{ \$message }}@enderror"
            . "|{{ isset(\$message) ? 'leaked' : 'clean' }}";
        [$output, $hasMessage] = $this->render($source,
            new ErrorBag(['email' => ['email error'], 'name' => ['name error']]));

        self::assertSame('email error|name error|clean', $output);
        self::assertFalse($hasMessage);
    }

    public function testPreviouslyNullMessageRemainsDefinedAfterErrorBlock(): void
    {
        [$output, $hasMessage, $message] = $this->render(
            "@error('email'){{ \$message }}@enderror",
            new ErrorBag(['email' => ['invalid']]), null, true);

        self::assertSame('invalid', $output);
        self::assertTrue($hasMessage);
        self::assertNull($message);
    }

    public function testErrorBlockRestoresMessageAfterBodyException(): void
    {
        $compiled = $this->compile("@error('email')<?php throw new \\RuntimeException('stop'); ?>@enderror");
        $errors = new ErrorBag(['email' => ['invalid']]);
        $message = 'original';

        try {
            eval('?>' . $compiled);
            self::fail('Expected the template body to throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('stop', $exception->getMessage());
            self::assertSame('original', $message);
        }
    }

    /** @dataProvider malformedForms */
    public function testMalformedFormDirectivesReportSourceLine(
        string $body,
        string $construct
    ): void {
        foreach (["\n", "\r\n"] as $ending) {
            $source = str_replace("\n", $ending, "first\nsecond\n" . $body);
            try {
                $this->compile($source, 'Pages.FormBroken');
                self::fail('Expected a form compiler error.');
            } catch (CompilerException $exception) {
                self::assertSame('Pages.FormBroken', $exception->view());
                self::assertSame(3, $exception->sourceLine());
                self::assertStringContainsString($construct, $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedForms(): iterable
    {
        yield 'orphan enderror' => ['@enderror', '@enderror'];
        yield 'unclosed error' => ["@error('email')body", '@error'];
        yield 'nested error' => ["@error('email')@error('name')x@enderror@enderror", '@error'];
        yield 'missing error argument' => ['@error()', '@error'];
        yield 'too many error arguments' => ["@error('email', 'name')x@enderror", '@error'];
        yield 'unparenthesized error' => ['@error', '@error'];
        yield 'missing method argument' => ['@method()', '@method'];
        yield 'too many method arguments' => ["@method('PUT', 'DELETE')", '@method'];
        yield 'unparenthesized method' => ['@method', '@method'];
    }

    public function testDirectiveLikeTextInProtectedContextsRemainsInert(): void
    {
        $source = <<<'TEMPLATE'
@errors @methodology @enderrorLater
<!-- @error('email')bad@enderror -->
<script>const value = "@method('DELETE')";</script>
<?php $value = '@error("email")'; ?>
TEMPLATE;
        $compiled = $this->compile($source);

        self::assertStringContainsString('@errors @methodology @enderrorLater', $compiled);
        self::assertStringContainsString('const value = "@method(\'DELETE\')";', $compiled);
        self::assertStringContainsString('$value = \'@error("email")\';', $compiled);
        self::assertStringNotContainsString('bad', $compiled);
    }

    private function compile(string $source, string $view = 'Pages.Form'): string
    {
        return (new TemplateCompiler())->compile($source, $view);
    }

    /** @return array{string, bool, mixed} */
    private function render(string $source, ErrorBag $errors,
        ?string $priorMessage = null, bool $bindPrior = false): array
    {
        $compiled = $this->compile($source);
        return (static function () use ($compiled, $errors, $priorMessage, $bindPrior): array {
            if ($bindPrior) {
                $message = $priorMessage;
            }
            $level = ob_get_level();
            ob_start();
            try {
                eval('?>' . $compiled);
                $output = (string) ob_get_clean();
                return [$output, array_key_exists('message', get_defined_vars()), $message ?? null];
            } finally {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
            }
        })();
    }
}
