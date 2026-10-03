<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Plugins\FragmentRenderResult as PublicFragmentRenderResult;
use App\View\Assets\AssetException;
use App\View\FragmentRenderResult;
use PHPUnit\Framework\TestCase;

/** The public result is a stable snapshot rather than a deferred render handle. */
final class FragmentRenderResultTest extends TestCase
{
    public function testFinalizedHtmlAndNamedStacksAreImmutableReadbacks(): void
    {
        $result = new FragmentRenderResult('<article>Ready</article>', [
            'styles' => '<link rel="stylesheet" href="/ready.css">',
            'head.meta' => '<meta name="ready">',
        ]);

        self::assertSame('<article>Ready</article>', $result->html());
        self::assertTrue($result->hasStack('styles'));
        self::assertSame('<link rel="stylesheet" href="/ready.css">', $result->stack('styles'));
        self::assertSame($result->stack('styles'), $result->stack('styles'));
        self::assertSame('<meta name="ready">', $result->stack('head.meta'));
        self::assertFalse($result->hasStack('scripts'));
        self::assertSame('', $result->stack('scripts'));

        $stacks = $result->stacks();
        $stacks['styles'] = 'changed';
        $stacks['scripts'] = 'new';
        self::assertSame('<link rel="stylesheet" href="/ready.css">', $result->stack('styles'));
        self::assertFalse($result->hasStack('scripts'));
        self::assertCount(2, $result->stacks());
    }

    public function testPluginSymbolIsTheExactCanonicalResultType(): void
    {
        $result = new FragmentRenderResult('ready', []);
        self::assertInstanceOf(PublicFragmentRenderResult::class, $result);
        self::assertSame(FragmentRenderResult::class,
            (new \ReflectionClass(PublicFragmentRenderResult::class))->getName());
    }

    public function testInvalidStackNameFailsWithoutReturningContent(): void
    {
        $result = new FragmentRenderResult('ready', []);
        $this->expectException(AssetException::class);
        $result->stack('../secret');
    }

    public function testInvalidConstructorStackValueFails(): void
    {
        $this->expectException(AssetException::class);
        new FragmentRenderResult('ready', ['scripts' => ['not HTML']]);
    }
}
