<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Plugins\ViewRenderResult as PublicViewRenderResult;
use App\Plugins\ViewTestResult as PublicViewTestResult;
use App\Testing\ViewTestResult;
use App\View\Assets\AssetException;
use App\View\FragmentRenderResult;
use App\View\ViewRenderResult;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/** The View assertion wrapper inspects fixed render snapshots, not templates. */
final class ViewTestResultTest extends TestCase
{
    public function testOutputAssertionsAreExactCaseSensitiveAndFluent(): void
    {
        $result = new ViewTestResult('Pages.Example', null,
            new ViewRenderResult('<p>Ẹ káàbọ̀</p><b>&lt;script&gt;</b><i>Raw</i>', []));

        self::assertSame($result, $result->assertSee('Ẹ káàbọ̀')
            ->assertSeeEscaped('<script>')->assertSee('<i>Raw</i>')
            ->assertDontSee('<script>')->assertSeeInOrder(['<p>', 'Ẹ káàbọ̀', '<b>']));
        self::assertSame($result->html(), $result->html());

        $failure = self::failure(static fn (): ViewTestResult => $result->assertSee('raw'));
        self::assertStringContainsString('Pages.Example', $failure->getMessage());
        self::assertStringContainsString('assertSee', $failure->getMessage());
        self::assertStringContainsString('raw', $failure->getMessage());

        $escapedFailure = self::failure(static fn (): ViewTestResult =>
            $result->assertSeeEscaped('<missing>'));
        self::assertStringContainsString('assertSeeEscaped', $escapedFailure->getMessage());
    }

    public function testOrderedNeedlesCannotReuseOneOccurrence(): void
    {
        $result = new ViewTestResult('Pages.Repeats', null,
            new ViewRenderResult('A A B', []));
        $result->assertSeeInOrder(['A', 'A', 'B']);

        $failure = self::failure(static fn (): ViewTestResult =>
            $result->assertSeeInOrder(['A', 'A', 'A']));
        self::assertStringContainsString('item 3', $failure->getMessage());
        self::assertStringContainsString('Pages.Repeats', $failure->getMessage());
    }

    public function testFragmentMetadataAndNamedStackAssertions(): void
    {
        $result = new ViewTestResult('Orders.Index', 'orders.list',
            new FragmentRenderResult('<li>Order</li>', [
                'head.meta' => '<meta name="orders">',
                'scripts-after' => '<script src="/orders.js"></script>',
            ]));
        self::assertSame($result, $result->assertStackContains('head.meta', 'orders')
            ->assertStackMissing('scripts-after', '/admin.js')
            ->assertStackMissing('unknown', '/anything.js'));
        self::assertTrue($result->hasStack('head.meta'));
        self::assertFalse($result->hasStack('unknown'));
        self::assertSame('', $result->stack('unknown'));
        self::assertCount(2, $result->stacks());

        $failure = self::failure(static fn (): ViewTestResult =>
            $result->assertStackContains('unknown', 'missing'));
        self::assertStringContainsString('Orders.Index', $failure->getMessage());
        self::assertStringContainsString('orders.list', $failure->getMessage());
        self::assertStringContainsString('unknown', $failure->getMessage());
    }

    public function testMethodFieldUsesTheNormalBrowserFormAllowlist(): void
    {
        $result = new ViewTestResult('Orders.Form', null,
            new ViewRenderResult('<input type="hidden" name="_method" value="PATCH">', []));
        $result->assertHasMethodField('patch');
        self::failure(static fn (): ViewTestResult => $result->assertHasMethodField('DELETE'));
        $this->expectException(InvalidArgumentException::class);
        $result->assertHasMethodField('GET');
    }

    public function testInvalidAssertionValuesAndStackNamesFailClearly(): void
    {
        $result = new ViewTestResult('Pages.Empty', null, new ViewRenderResult('', []));
        foreach ([
            static fn () => $result->assertSee(''),
            static fn () => $result->assertDontSee(''),
            static fn () => $result->assertSeeInOrder([]),
        ] as $assertion) {
            try {
                $assertion();
                self::fail('An empty assertion value must fail.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(AssetException::class);
        $result->stack('../unsafe');
    }

    public function testFailureExcerptIsBoundedAndPluginsAreExactAliases(): void
    {
        $result = new ViewTestResult('Pages.Long', null,
            new ViewRenderResult(str_repeat('X', 100_000), []));
        $failure = self::failure(static fn (): ViewTestResult => $result->assertSee('absent'));
        self::assertLessThan(500, strlen($failure->getMessage()));

        self::assertSame(ViewTestResult::class,
            (new \ReflectionClass(PublicViewTestResult::class))->getName());
        self::assertSame(ViewRenderResult::class,
            (new \ReflectionClass(PublicViewRenderResult::class))->getName());
    }

    public function testFullRenderResultKeepsItsFinalizedStacksImmutable(): void
    {
        $captured = new ViewRenderResult('<h1>Ready</h1>', [
            'styles' => '<link href="/ready.css">',
        ]);
        $readback = $captured->stacks();
        $readback['styles'] = 'changed';
        $readback['scripts'] = 'added';

        self::assertSame('<h1>Ready</h1>', $captured->html());
        self::assertTrue($captured->hasStack('styles'));
        self::assertFalse($captured->hasStack('scripts'));
        self::assertSame('<link href="/ready.css">', $captured->stack('styles'));
        self::assertSame('', $captured->stack('scripts'));
    }

    public function testFullRenderResultRejectsInvalidStackData(): void
    {
        $this->expectException(AssetException::class);
        new ViewRenderResult('ready', ['../unsafe' => '<script></script>']);
    }

    private static function failure(callable $assertion): AssertionFailedError
    {
        try {
            $assertion();
        } catch (AssertionFailedError $failure) {
            return $failure;
        }
        self::fail('Expected the View assertion to fail.');
    }
}
