<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/** Checks component and slot structure through the shared template compiler. */
final class ComponentCompilerTest extends TestCase
{
    public function testPairedMultilineComponentAndNamedSlotCompile(): void
    {
        $source = <<<'TEMPLATE'
@component(
    'Forms.Button',
    ['label' => $label, 'disabled' => false],
    ['class' => 'primary', 'data-id' => $id]
)
    Body {{ $label }}
    @slot('footer')
        Footer
    @endslot
@endcomponent
TEMPLATE;

        $compiled = $this->compile($source);
        self::assertStringNotContainsString('@component', $compiled);
        self::assertStringNotContainsString('@slot', $compiled);
        self::assertStringContainsString('Forms.Button', $compiled);
        self::assertStringContainsString("'label' => \$label", $compiled);
        self::assertStringContainsString("'data-id' => \$id", $compiled);
        self::assertStringContainsString('footer', $compiled);
    }

    public function testEmptyAndNestedComponentsAreStructurallyValid(): void
    {
        $source = <<<'TEMPLATE'
@component('Card')
    @slot('footer')
        @component('Icon', ['name' => 'close'])
            @slot('title')Close@endslot
        @endcomponent
    @endslot
@endcomponent
@component('Chart')
@endcomponent
TEMPLATE;
        $compiled = $this->compile($source);
        self::assertStringContainsString('Card', $compiled);
        self::assertStringContainsString('Icon', $compiled);
        self::assertStringContainsString('Chart', $compiled);
    }

    public function testPropsDeclarationIsAcceptedInComponentTemplate(): void
    {
        $compiled = $this->compile(
            "@props(['title', 'variant' => 'default', 'disabled' => false])\n"
            . '<div>{{ $title }}</div>',
            'Components.Card',
            true
        );
        self::assertStringNotContainsString('@props', $compiled);
        self::assertStringContainsString('variant', $compiled);
    }

    /** @dataProvider malformedStructures */
    public function testMalformedStructureReportsSourceViewAndLine(
        string $body,
        string $view,
        string $construct
    ): void {
        foreach (["\n", "\r\n"] as $ending) {
            $source = str_replace("\n", $ending, "first\nsecond\n" . $body);
            try {
                $this->compile($source, $view, str_starts_with($view, 'Components.'));
                self::fail("Expected {$construct} to fail compilation.");
            } catch (CompilerException $exception) {
                self::assertSame($view, $exception->view());
                self::assertGreaterThanOrEqual(3, $exception->sourceLine());
                self::assertStringContainsString($construct, $exception->getMessage());
            }
        }
    }

    public function testSlotOutsideComponentHasExactLfAndCrlfSourceLine(): void
    {
        foreach (["\n", "\r\n"] as $ending) {
            $source = str_replace("\n", $ending,
                "first\nsecond\n@slot('footer')orphan@endslot");
            try {
                $this->compile($source, 'Pages.OrphanSlot');
                self::fail('Expected a slot outside a component to fail.');
            } catch (CompilerException $exception) {
                self::assertSame('Pages.OrphanSlot', $exception->view());
                self::assertSame(3, $exception->sourceLine());
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function malformedStructures(): iterable
    {
        yield 'component with no name' => ['@component()@endcomponent',
            'Pages.Bad', '@component'];
        yield 'component too many arguments' => [
            "@component('Card', [], [], [])@endcomponent", 'Pages.Bad', '@component'];
        yield 'dynamic component name' => [
            '@component($name)@endcomponent', 'Pages.Bad', '@component'];
        yield 'unsafe component name' => [
            "@component('../Outside')@endcomponent", 'Pages.Bad', '@component'];
        yield 'unmatched endcomponent' => ['@endcomponent', 'Pages.Bad', '@endcomponent'];
        yield 'unclosed component' => ["@component('Card')", 'Pages.Bad', '@component'];
        yield 'slot outside component' => [
            "@slot('footer')x@endslot", 'Pages.Bad', '@slot'];
        yield 'unmatched endslot' => ['@endslot', 'Pages.Bad', '@endslot'];
        yield 'endcomponent inside slot' => [
            "@component('Card')@slot('footer')x@endcomponent",
            'Pages.Bad', '@endcomponent'];
        yield 'directly nested slots' => [
            "@component('Card')@slot('footer')@slot('header')x@endslot"
            . '@endslot@endcomponent', 'Pages.Bad', '@slot'];
        yield 'duplicate named slot' => [
            "@component('Card')@slot('footer')A@endslot"
            . "@slot('footer')B@endslot@endcomponent", 'Pages.Bad', '@slot'];
        yield 'invalid slot name' => [
            "@component('Card')@slot('../footer')x@endslot@endcomponent",
            'Pages.Bad', '@slot'];
        yield 'props outside component template' => [
            "@props(['title'])", 'Pages.Bad', '@props'];
        yield 'duplicate props declaration' => [
            "@props(['title'])\n@props(['variant'])",
            'Components.Bad', '@props'];
        yield 'props after output' => [
            "meaningful output\n@props(['title'])",
            'Components.Bad', '@props'];
        yield 'props in conditional' => [
            "@if(true)@props(['title'])@endif",
            'Components.Bad', '@props'];
    }

    public function testUnknownDirectivePrefixesAndProtectedTextRemainInert(): void
    {
        $source = <<<'TEMPLATE'
@componentCard('Fake') @endcomponentLater @slotMachine('footer')
<!-- @component('Fake')@endcomponent -->
<script>const demo = "@component('Fake')";</script>
<?php $demo = "@slot('footer')"; ?>
TEMPLATE;
        $compiled = $this->compile($source);
        self::assertStringContainsString("@componentCard('Fake')", $compiled);
        self::assertStringContainsString('@endcomponentLater', $compiled);
        self::assertStringContainsString("@slotMachine('footer')", $compiled);
        self::assertStringContainsString('const demo = "@component(\'Fake\')"', $compiled);
        self::assertStringContainsString('$demo = "@slot(\'footer\')"', $compiled);
    }

    private function compile(string $source, string $view = 'Pages.ComponentCompiler',
        bool $componentTemplate = false): string
    {
        return (new TemplateCompiler())->compile($source, $view, $componentTemplate);
    }
}
