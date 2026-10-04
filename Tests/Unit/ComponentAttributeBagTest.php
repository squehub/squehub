<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\ComponentAttributeBag;
use PHPUnit\Framework\TestCase;
use Stringable;
use Throwable;

/** Checks safe HTML attributes without requiring template execution. */
final class ComponentAttributeBagTest extends TestCase
{
    public function testSupportedValuesRenderInStableOrderAndEscapeOnce(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'S"Q';
            }
        };
        $bag = new ComponentAttributeBag([
            'class' => 'card',
            'data-id' => 42,
            'aria-label' => $stringable,
            'disabled' => true,
            'hidden' => false,
            'title' => null,
            'value' => '"><script>ATTRIBUTE_SECRET</script>',
            'id' => '',
        ]);

        $html = $bag->toHtml();
        self::assertStringContainsString('class="card"', $html);
        self::assertStringContainsString('data-id="42"', $html);
        self::assertStringContainsString('aria-label="S&quot;Q"', $html);
        self::assertMatchesRegularExpression('/(?:^|\s)disabled(?:\s|$)/', $html);
        self::assertStringNotContainsString('hidden', $html);
        self::assertStringNotContainsString('title=', $html);
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;ATTRIBUTE_SECRET', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('id=""', $html);

        $positions = [];
        foreach (['class=', 'data-id=', 'aria-label=', 'disabled', 'value=', ' id='] as $name) {
            $position = strpos($html, $name);
            self::assertNotFalse($position);
            $positions[] = $position;
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
    }

    public function testMergeOnlyAndExceptReturnIndependentBags(): void
    {
        $caller = new ComponentAttributeBag([
            'class' => 'caller', 'data-id' => '9', 'disabled' => true,
        ]);
        $merged = $caller->merge(['class' => 'default', 'id' => 'base']);
        self::assertSame([
            'class' => 'caller', 'id' => 'base', 'data-id' => '9', 'disabled' => true,
        ], $merged->all());
        self::assertSame([
            'class' => 'caller', 'data-id' => '9', 'disabled' => true,
        ], $caller->all());
        self::assertSame(['class' => 'caller', 'data-id' => '9'],
            $caller->only(['class', 'data-id'])->all());
        self::assertSame(['class' => 'caller', 'disabled' => true],
            $caller->except(['data-id'])->all());
        self::assertTrue($caller->has('class'));
        self::assertFalse($caller->has('id'));
        self::assertSame('caller', $caller->get('class'));
        self::assertNull($caller->get('id'));
    }

    /** @dataProvider invalidAttributes */
    public function testInvalidNamesAndValuesFailWithoutExposingValues(array $attributes): void
    {
        $error = null;
        try {
            (new ComponentAttributeBag($attributes))->toHtml();
        } catch (Throwable $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(Throwable::class, $error);
        self::assertStringNotContainsString('ATTRIBUTE_SECRET', $error->getMessage());
    }

    /** @return iterable<string, array{array}> */
    public static function invalidAttributes(): iterable
    {
        yield 'empty name' => [['' => 'ATTRIBUTE_SECRET']];
        yield 'whitespace name' => [['data id' => 'ATTRIBUTE_SECRET']];
        yield 'quoted name' => [['onload"evil' => 'ATTRIBUTE_SECRET']];
        yield 'control name' => [["data\x01id" => 'ATTRIBUTE_SECRET']];
        yield 'array value' => [['data-id' => ['ATTRIBUTE_SECRET']]];
        yield 'object value' => [['data-id' => new \stdClass()]];
    }
}
