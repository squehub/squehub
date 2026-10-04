<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Assets\AssetException;
use App\View\Assets\AssetRegistry;
use App\View\Assets\AssetRenderState;
use App\View\LayoutRenderState;
use PHPUnit\Framework\TestCase;

/** Fragment finalization exposes the same ordered assets as full View stacks. */
final class FragmentAssetResultTest extends TestCase
{
    public function testParticipatingOwnersProduceFinalizedCustomAndDirectStacks(): void
    {
        $registry = new AssetRegistry();
        $registry->for('Pages.Orders')->style('/root.css');
        $registry->for('Layouts.App')->style('/layout.css');
        $assets = new AssetRenderState($registry->snapshot());
        $assets->participateLayout('Pages.Orders', 0);
        $assets->script('Pages.Orders', '/orders.js', 'orders-script');
        $assets->script('Pages.Orders', '/orders.js', 'orders-script');
        $assets->participateInclude('Partials.Chart');
        $assets->style('Partials.Chart', '/chart.css');
        $head = $assets->reserveBlock('Partials.Chart', 'head.meta', null, false);
        $assets->completeBlock($head, '<meta name="chart">');

        $finished = $assets->finalizeFragment('<main>Orders</main>');
        self::assertSame('<main>Orders</main>', $finished['html']);
        self::assertSame(implode("\n", [
            '<link rel="stylesheet" href="/root.css">',
            '<link rel="stylesheet" href="/chart.css">',
        ]), $finished['stacks']['styles']);
        self::assertSame('<script src="/orders.js"></script>', $finished['stacks']['scripts']);
        self::assertSame('<meta name="chart">', $finished['stacks']['head.meta']);
        self::assertStringNotContainsString('/layout.css', implode('', $finished['stacks']));
    }

    public function testFragmentHtmlAndCapturedStackPlaceholdersFinalizeOnce(): void
    {
        $assets = new AssetRenderState();
        $assets->participateLayout('Pages.Page', 0);
        $scripts = $assets->stack('scripts');
        $head = $assets->reserveBlock('Pages.Page', 'head', null, false);
        $assets->completeBlock($head, '<span>' . $scripts . '</span>');
        $assets->script('Pages.Page', '/late.js');

        $finished = $assets->finalizeFragment($assets->stack('head'));
        self::assertSame('<span><script src="/late.js"></script></span>', $finished['html']);
        self::assertSame($finished['html'], $finished['stacks']['head']);
        self::assertSame('<script src="/late.js"></script>', $finished['stacks']['scripts']);
    }

    public function testConflictingOnceKeyFailsBeforePublishingAnyResult(): void
    {
        $state = new LayoutRenderState([], new AssetRenderState());
        $state->enterLayout('Pages.Page', __FILE__);
        try {
            $state->script('/first.js', 'duplicate');
            $state->script('/second.js', 'duplicate');
        } finally {
            $state->leaveLayout();
        }

        $this->expectException(AssetException::class);
        $state->finishFragmentAssets('<main>Unpublished</main>');
    }
}
