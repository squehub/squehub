<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiler\CompilerException;
use App\View\FragmentNotFoundException;
use App\View\Compiler\TemplateCompiler;
use App\View\LayoutRenderState;
use PHPUnit\Framework\TestCase;

/** Static fragment boundaries compile into independently selectable renderers. */
final class FragmentCompilerTest extends TestCase
{
    public function testSelectedRendererSkipsSiblingsAndBothModesExecuteBodyOnce(): void
    {
        $source = <<<'VIEW'
@php $counter->outside++; @endphp
BEFORE
@fragment('orders.list')
    @php $counter->inside++; @endphp
    <b>{{ $name }}</b>
@endfragment
AFTER
VIEW;
        $compiled = $this->compile($source);
        $counter = (object) ['outside' => 0, 'inside' => 0];
        $scope = ['counter' => $counter, 'name' => 'Ada'];

        $selected = $this->render($compiled, 'orders.list', $scope);
        self::assertStringContainsString('<b>Ada</b>', $selected);
        self::assertStringNotContainsString('BEFORE', $selected);
        self::assertStringNotContainsString('AFTER', $selected);
        self::assertSame(0, $counter->outside);
        self::assertSame(1, $counter->inside);

        $full = $this->render($compiled, null, $scope);
        self::assertStringContainsString('BEFORE', $full);
        self::assertStringContainsString('<b>Ada</b>', $full);
        self::assertStringContainsString('AFTER', $full);
        self::assertSame(1, $counter->outside);
        self::assertSame(2, $counter->inside);
        self::assertSame(1, substr_count($compiled, '<b>'));
    }

    public function testOneCompiledMapSelectsEitherFragmentWithFreshData(): void
    {
        $source = <<<'VIEW'
@fragment('alpha')A{{ $value }}@endfragment
@fragment('beta')B{{ $value }}@endfragment
VIEW;
        $compiler = new TemplateCompiler();
        $alpha = $compiler->compile($source, 'Pages.Fragments', false, true, 'alpha');
        $beta = $compiler->compile($source, 'Pages.Fragments', false, true, 'beta');

        self::assertSame($alpha, $beta);
        self::assertSame(1, substr_count($alpha, 'A<?php echo'));
        self::assertSame(1, substr_count($alpha, 'B<?php echo'));
        self::assertStringContainsString('A1', preg_replace('/\s+/', '',
            $this->render($alpha, 'alpha', ['value' => 1])));
        self::assertStringContainsString('B2', preg_replace('/\s+/', '',
            $this->render($alpha, 'beta', ['value' => 2])));
        self::assertStringNotContainsString('B', $this->render($alpha, 'alpha', ['value' => 3]));
    }

    public function testFragmentInsideSectionSkipsSectionAndSiblingLocalScope(): void
    {
        $source = <<<'VIEW'
@php $sibling = 'not shared'; @endphp
@section('content')
BEFORE
@fragment('target'){{ isset($sibling) ? $sibling : 'isolated' }}@endfragment
AFTER
@endsection
VIEW;
        $compiled = $this->compile($source);

        $selected = $this->render($compiled, 'target');
        self::assertSame('isolated', trim($selected));
        self::assertStringNotContainsString('BEFORE', $selected);
        self::assertStringNotContainsString('AFTER', $selected);

        // Both call sites use the same isolated closure, even if a sibling
        // PHP statement established a local in the full-page render scope.
        $state = new LayoutRenderState();
        $this->render($compiled, null, [], $state);
        self::assertStringContainsString('BEFORE', $state->section('content'));
        self::assertStringContainsString('isolated', $state->section('content'));
        self::assertStringContainsString('AFTER', $state->section('content'));
        self::assertStringNotContainsString('not shared', $state->section('content'));
    }

    public function testMissingFragmentFailsBeforeUnrelatedPhpCanExecute(): void
    {
        $source = <<<'VIEW'
@php throw new RuntimeException('SIBLING_SECRET'); @endphp
@fragment('known')KNOWN@endfragment
VIEW;
        try {
            $this->compile($source, 'Pages.Target', 'missing');
            self::fail('Expected a missing Fragment diagnostic.');
        } catch (FragmentNotFoundException $exception) {
            self::assertSame('Pages.Target', $exception->view());
            self::assertSame('missing', $exception->fragment());
            self::assertFalse(method_exists($exception, 'sourceLine'));
            self::assertStringContainsString('missing', $exception->getMessage());
            self::assertStringNotContainsString('SIBLING_SECRET', $exception->getMessage());
        }

        $compiled = $this->compile('@php throw new RuntimeException("secret"); @endphp');
        self::assertStringNotContainsString('fragmentRenderers', $compiled);
        try {
            $this->compile('@php throw new RuntimeException("secret"); @endphp',
                'Pages.NoFragment', 'missing');
            self::fail('A missing selected Fragment must fail before PHP executes.');
        } catch (FragmentNotFoundException $exception) {
            self::assertSame('Pages.NoFragment', $exception->view());
            self::assertSame('missing', $exception->fragment());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    /** @dataProvider invalidFragments */
    public function testInvalidFragmentStructuresHaveSourceAwareErrors(
        string $source, int $line, string $reason
    ): void {
        $this->assertInvalid($source, $line, $reason);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidFragments(): iterable
    {
        yield 'orphan closer' => ["first\n@endfragment", 2, 'endfragment'];
        yield 'unclosed' => ["@fragment('one')\nBody", 1, 'fragment'];
        yield 'duplicate' => [
            "@fragment('one')A@endfragment\n@fragment('one')B@endfragment", 2, 'Duplicate',
        ];
        yield 'nested' => [
            "@fragment('one')\n@fragment('two')B@endfragment\n@endfragment", 2, 'nested',
        ];
        yield 'in conditional' => [
            "@if(true)\n@fragment('one')A@endfragment\n@endif", 2, 'fragment',
        ];
        yield 'in unless' => [
            "@unless(false)\n@fragment('one')A@endfragment\n@endunless", 2, 'fragment',
        ];
        yield 'in switch' => [
            "@switch(\$kind)\n@case('a')\n@fragment('one')A@endfragment\n@endswitch",
            3, 'fragment',
        ];
        yield 'in section conditional' => [
            "@section('content')\n@if(true)\n@fragment('one')A@endfragment\n@endif\n@endsection",
            3, 'fragment',
        ];
        yield 'in loop' => [
            "@foreach(\$items as \$item)\n@fragment('one')A@endfragment\n@endforeach",
            2, 'fragment',
        ];
        yield 'in forelse' => [
            "@forelse(\$items as \$item)\n@fragment('one')A@endfragment\n@empty\n@endforelse",
            2, 'fragment',
        ];
        yield 'in for' => [
            "@for(\$i = 0; \$i < 1; ++\$i)\n@fragment('one')A@endfragment\n@endfor",
            2, 'fragment',
        ];
        yield 'in while' => [
            "@while(\$ready)\n@fragment('one')A@endfragment\n@endwhile", 2, 'fragment',
        ];
        yield 'in component' => [
            "@component('Card')\n@fragment('one')A@endfragment\n@endcomponent",
            2, 'fragment',
        ];
        yield 'in slot' => [
            "@component('Card')\n@slot('title')\n@fragment('one')A@endfragment\n@endslot\n@endcomponent",
            3, 'fragment',
        ];
        yield 'in asset block' => [
            "@push('scripts')\n@fragment('one')A@endfragment\n@endpush", 2, 'fragment',
        ];
        yield 'in prepend block' => [
            "@prepend('scripts')\n@fragment('one')A@endfragment\n@endprepend",
            2, 'fragment',
        ];
        yield 'in Auth branch' => [
            "@auth\n@fragment('one')A@endfragment\n@endauth", 2, 'fragment',
        ];
        yield 'in legacy do block' => [
            "@do\n@fragment('one')A@endfragment\n@enddo(false)", 2, 'fragment',
        ];
        yield 'in notification block' => [
            "@hasNotification('success')\n@fragment('one')A@endfragment\n@endhasNotification",
            2, 'fragment',
        ];
        yield 'section within fragment' => [
            "@fragment('one')\n@section('content')A@endsection\n@endfragment", 2, 'section',
        ];
        yield 'empty name' => ["@fragment('')", 1, 'fragment'];
        yield 'dynamic name' => ['@fragment($name)', 1, 'fragment'];
        yield 'path separator' => ["@fragment('../orders')", 1, 'fragment'];
        yield 'double dot' => ["@fragment('orders..list')", 1, 'fragment'];
        yield 'missing argument' => ['@fragment', 1, 'fragment'];
        yield 'two names' => ["@fragment('a', 'b')", 1, 'fragment'];
        yield 'closer with argument' => [
            "@fragment('one')A@endfragment('one')", 1, 'endfragment',
        ];
    }

    /** @dataProvider lineEndings */
    public function testDuplicateDiagnosticLinesAreStableAcrossLineEndings(string $ending): void
    {
        $source = str_replace("\n", $ending,
            "Heading\n@fragment('one')A@endfragment\nOther\n@fragment('one')B@endfragment");
        $this->assertInvalid($source, 4, 'Duplicate');
    }

    /** @return iterable<string, array{string}> */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
    }

    public function testProtectedContextsAndLongerDirectiveNamesStayLiteral(): void
    {
        $source = <<<'VIEW'
<?php $literal = "@fragment('php') @endfragment"; ?>
@php $other = '@fragment("directive")'; @endphp
<!-- @fragment('comment') @endfragment -->
<script>const literal = "@fragment('script')"; // @fragment('script')
</script>
<style>@fragment('css') { color: red; }</style>
@fragmented('not-a-fragment') @fragmentList('no') @endfragmentLater
@fragment('real')YES@endfragment
VIEW;
        $compiled = $this->compile($source);
        self::assertStringContainsString("@fragment('php')", $compiled);
        self::assertStringContainsString('@fragment("directive")', $compiled);
        self::assertStringContainsString("@fragment('script')", $compiled);
        self::assertStringContainsString("@fragment('css')", $compiled);
        self::assertStringContainsString('@fragmented', $compiled);
        self::assertStringContainsString('@fragmentList', $compiled);
        self::assertStringContainsString('@endfragmentLater', $compiled);
        self::assertSame(1, substr_count($compiled, "'real' => static function"));
    }

    public function testNestedTemplatesCannotDeclareExternallyAddressableFragments(): void
    {
        $source = "@fragment('private')VALUE@endfragment";
        $compiler = new TemplateCompiler();
        foreach ([false, true] as $component) {
            try {
                $compiler->compile($source, 'Partials.Private', $component, false);
                self::fail('Expected a nested View Fragment rejection.');
            } catch (CompilerException $exception) {
                self::assertSame('Partials.Private', $exception->view());
                self::assertSame(1, $exception->sourceLine());
                self::assertStringContainsString('root View', $exception->getMessage());
            }
        }
    }

    public function testLeadingStrictDeclarationRemainsFirstPhpStatement(): void
    {
        $source = <<<'VIEW'
<?php declare(strict_types=1); ?>
@fragment('safe'){{ $value }}@endfragment
VIEW;
        $compiled = $this->compile($source);
        self::assertStringStartsWith('<?php declare(strict_types=1);', $compiled);
        self::assertSame('x', trim($this->render($compiled, 'safe', ['value' => 'x'])));
    }

    public function testUnsupportedLeadingDeclareSyntaxFailsBeforeCaching(): void
    {
        $this->assertInvalid(
            "<?php declare(ticks=1): ?>\n@fragment('safe')X@endfragment\n<?php enddeclare; ?>",
            1, 'declare'
        );
    }

    public function testPhpNamespaceDeclarationFailsBeforeGeneratedMapIsCached(): void
    {
        $this->assertInvalid(
            "<?php namespace App\\Views; ?>\n@fragment('safe')X@endfragment",
            1, 'namespace'
        );
    }

    private function compile(string $source, string $view = 'Pages.Fragments',
        ?string $selected = null): string
    {
        return (new TemplateCompiler())->compile($source, $view, false, true, $selected);
    }

    private function assertInvalid(string $source, int $line, string $reason,
        string $view = 'Pages.Invalid', ?string $selected = null): void
    {
        try {
            $this->compile($source, $view, $selected);
            self::fail('Expected a Fragment compiler diagnostic.');
        } catch (CompilerException $exception) {
            self::assertSame($view, $exception->view());
            self::assertSame($line, $exception->sourceLine());
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $scope */
    private function render(string $compiled, ?string $selected,
        array $scope = [], ?LayoutRenderState $state = null): string
    {
        $file = tempnam(sys_get_temp_dir(), 'fragment_compiler_');
        self::assertIsString($file);
        file_put_contents($file, $compiled);
        try {
            return (static function (string $file, ?string $selected,
                array $scope, LayoutRenderState $state): string {
                $__squehub_fragmentSelection = $selected;
                $__squehub_fragmentScope = $scope;
                $__squehub_layoutState = $state;
                extract($scope, EXTR_SKIP);
                $state->enterLayout('Pages.Fragments', $file);
                ob_start();
                try {
                    include $file;
                    return (string) ob_get_clean();
                } catch (\Throwable $exception) {
                    ob_end_clean();
                    throw $exception;
                } finally {
                    $state->leaveLayout();
                }
            })($file, $selected, $scope, $state ?? new LayoutRenderState());
        } finally {
            unlink($file);
        }
    }
}
