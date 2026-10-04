<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Assets\AssetException;
use App\View\Assets\AssetRegistry;
use App\View\Assets\AssetRenderState;
use PHPUnit\Framework\TestCase;
use Stringable;

/** One top-level render collects, orders, deduplicates and then emits stacks. */
final class AssetRenderStateTest extends TestCase
{
    public function testNormalResourcesFollowLayoutPageAndFirstPartialParticipationOrder(): void
    {
        $state = new AssetRenderState();
        // Runtime layout execution is child first. Final resource order is not.
        $state->participateLayout('Pages.Dashboard', 0);
        $state->style('Pages.Dashboard', '/assets/page.css');
        $state->participateInclude('Partials.Analytics');
        $state->style('Partials.Analytics', '/assets/analytics.css');
        $state->participateInclude('Partials.Editor');
        $state->style('Partials.Editor', '/assets/editor.css');
        $state->participateInclude('Partials.Analytics');
        $state->participateLayout('Layouts.Admin', 1);
        $state->style('Layouts.Admin', '/assets/admin.css');
        $state->participateLayout('Layouts.Base', 2);
        $state->style('Layouts.Base', '/assets/base.css');

        $output = $state->finalize($state->stack('styles'));
        self::assertSame([
            '<link rel="stylesheet" href="/assets/base.css">',
            '<link rel="stylesheet" href="/assets/admin.css">',
            '<link rel="stylesheet" href="/assets/page.css">',
            '<link rel="stylesheet" href="/assets/analytics.css">',
            '<link rel="stylesheet" href="/assets/editor.css">',
        ], explode("\n", $output));
    }

    public function testExternalAssetsActivateOnlyWithOwnerAndPrecedeItsTemplateDeclarations(): void
    {
        $registry = new AssetRegistry();
        $registry->for('Pages.Absent')->style('/assets/absent.css');
        $registry->for(['Pages.Dashboard', 'Pages.Report'])
            ->style('/assets/external.css');
        $state = new AssetRenderState($registry->snapshot());
        $registry->for('Pages.Dashboard')->style('/assets/registered-later.css');

        $state->participateLayout('Pages.Dashboard', 0);
        $state->style('Pages.Dashboard', '/assets/template.css');
        $state->style('Pages.Dashboard', '/assets/external.css');
        $output = $state->finalize($state->stack('styles'));

        self::assertSame([
            '<link rel="stylesheet" href="/assets/external.css">',
            '<link rel="stylesheet" href="/assets/template.css">',
        ], explode("\n", $output));
        self::assertStringNotContainsString('/assets/absent.css', $output);
        self::assertStringNotContainsString('/assets/registered-later.css', $output);
    }

    public function testDirectDedupUsesKindUrlAndTargetStackWithoutDroppingQueryVariants(): void
    {
        $state = new AssetRenderState();
        $state->participateLayout('Pages.Resources', 0);
        $state->style('Pages.Resources', '/assets/shared.css?v=1');
        $state->style('Pages.Resources', '/assets/shared.css?v=1');
        $state->style('Pages.Resources', '/assets/shared.css?v=2');
        $state->script('Pages.Resources', '/assets/shared.css?v=1');
        $state->script('Pages.Resources', '/assets/shared.css?v=1');

        $output = $state->finalize($state->stack('styles') . '|' . $state->stack('scripts'));
        self::assertSame(2, substr_count($output, '<link rel="stylesheet"'));
        self::assertSame(1, substr_count($output, '<script src='));
        self::assertSame(2, substr_count($output, '/assets/shared.css?v=1'));
        self::assertSame(1, substr_count($output, '/assets/shared.css?v=2'));
    }

    public function testPrependOrderGenericDuplicatesAndExplicitOnceAreSeparate(): void
    {
        $state = new AssetRenderState();
        $state->participateLayout('Pages.Blocks', 0);
        foreach ([
            ['<i>A</i>', null, true],
            ['<i>B</i>', null, true],
            ['<b>C</b>', null, false],
            ['<b>C</b>', null, false],
            ['<u>Once</u>', 'once-block', false],
            ['<u>Once</u>', 'once-block', false],
        ] as [$html, $once, $prepend]) {
            $index = $state->reserveBlock('Pages.Blocks', 'head', $once, $prepend);
            $state->completeBlock($index, $html);
        }

        self::assertSame(implode("\n", [
            '<i>A</i>', '<i>B</i>', '<b>C</b>', '<b>C</b>', '<u>Once</u>',
        ]), $state->finalize($state->stack('head')));
    }

    public function testConflictingOnceKeyFailsPrivatelyAndFreshStateMayReuseIt(): void
    {
        $state = new AssetRenderState();
        $state->participateLayout('Pages.One', 0);
        $state->script('Pages.One', '/assets/SECRET-first.js', 'runtime');
        $state->script('Pages.One', '/assets/SECRET-second.js', 'runtime');
        try {
            $state->finalize($state->stack('scripts'));
            self::fail('Conflicting once values must fail.');
        } catch (AssetException $exception) {
            self::assertStringContainsString('once key', $exception->getMessage());
            self::assertStringNotContainsString('SECRET', $exception->getMessage());
        }

        $fresh = new AssetRenderState();
        $fresh->participateLayout('Pages.Two', 0);
        $fresh->script('Pages.Two', '/assets/second.js', 'runtime');
        self::assertStringContainsString('/assets/second.js',
            $fresh->finalize($fresh->stack('scripts')));
    }

    public function testConflictingCapturedBlocksUsingOneKeyDoNotExposeTheirBodies(): void
    {
        $state = new AssetRenderState();
        $state->participateLayout('Pages.One', 0);
        $first = $state->reserveBlock('Pages.One', 'head', 'shared', false);
        $state->completeBlock($first, '<meta content="SECRET-first">');
        $second = $state->reserveBlock('Pages.One', 'head', 'shared', false);
        $state->completeBlock($second, '<meta content="SECRET-second">');

        try {
            $state->finalize($state->stack('head'));
            self::fail('Conflicting captured content must fail.');
        } catch (AssetException $exception) {
            self::assertStringContainsString('once key', $exception->getMessage());
            self::assertStringNotContainsString('SECRET', $exception->getMessage());
        }
    }

    public function testStackPositionsShareFinalContentAndUndefinedStackIsEmpty(): void
    {
        $state = new AssetRenderState();
        $state->participateLayout('Pages.One', 0);
        $first = $state->stack('vendor.scripts');
        $second = $state->stack('vendor.scripts');
        self::assertSame($first, $second);

        $index = $state->reserveBlock('Pages.One', 'vendor.scripts', null, false);
        $state->completeBlock($index, '<script>ready()</script>');
        $output = $state->finalize('<head>' . $first . '</head><body>' . $second
            . $state->stack('optional') . '</body>');
        self::assertSame('<head><script>ready()</script></head>'
            . '<body><script>ready()</script></body>', $output);
        self::assertStringNotContainsString($first, $output);
    }

    public function testCapturedStackReferenceResolvesAfterReferencedStackIsComplete(): void
    {
        $state = new AssetRenderState();
        $state->participateLayout('Pages.One', 0);
        $head = $state->stack('head');
        $scripts = $state->stack('scripts-after');

        $headBlock = $state->reserveBlock('Pages.One', 'head', null, false);
        $state->completeBlock($headBlock, '<div>' . $scripts . '</div>');
        $scriptBlock = $state->reserveBlock('Pages.One', 'scripts-after', null, false);
        $state->completeBlock($scriptBlock, '<script>ready()</script>');

        self::assertSame('<div><script>ready()</script></div>',
            $state->finalize($head));
    }

    public function testCircularCapturedStackReferenceFailsWithoutLeakingPlaceholder(): void
    {
        $state = new AssetRenderState();
        $state->participateLayout('Pages.One', 0);
        $head = $state->stack('head');
        $block = $state->reserveBlock('Pages.One', 'head', null, false);
        $state->completeBlock($block, '<div>' . $head . '</div>');

        try {
            $state->finalize($head);
            self::fail('Expected a circular stack error.');
        } catch (AssetException $exception) {
            self::assertStringContainsString('Circular asset stack', $exception->getMessage());
            self::assertStringNotContainsString($head, $exception->getMessage());
        }
    }

    public function testRuntimeUrlsAcceptStringableAndEscapeHtmlAttributesOnce(): void
    {
        $state = new AssetRenderState();
        $state->participateLayout('Pages.One', 0);
        $url = new class implements Stringable {
            public function __toString(): string
            {
                return '/assets/app.css" onload="SECRET';
            }
        };
        $state->style('Pages.One', $url);
        $output = $state->finalize($state->stack('styles'));

        self::assertSame('<link rel="stylesheet" href="/assets/app.css&quot; onload=&quot;SECRET">',
            $output);
        self::assertStringNotContainsString('onload="SECRET"', $output);
    }

    /** @dataProvider invalidStackNames */
    public function testRuntimeStackValidationRejectsMalformedNames(string $name): void
    {
        $this->expectException(AssetException::class);
        (new AssetRenderState())->stack($name);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidStackNames(): iterable
    {
        yield 'empty' => [''];
        yield 'leading digit' => ['1head'];
        yield 'traversal' => ['../head'];
        yield 'slash' => ['vendor/scripts'];
        yield 'control' => ["head\n"];
    }
}
