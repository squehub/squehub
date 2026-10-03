<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\ComponentSlot;
use App\View\SlotBag;
use PHPUnit\Framework\TestCase;
use Throwable;

/** Checks captured slot values and the read-only named-slot collection. */
final class ComponentSlotTest extends TestCase
{
    public function testDefaultAndNamedSlotHtmlIsRetainedWithoutSecondEscaping(): void
    {
        $default = new ComponentSlot('<p>&lt;unsafe&gt;</p>');
        $footer = new ComponentSlot('<button>Close</button>');
        $bag = new SlotBag(['footer' => $footer]);

        self::assertSame('<p>&lt;unsafe&gt;</p>', $default->toHtml());
        self::assertSame('<p>&lt;unsafe&gt;</p>', $default->toHtml());
        self::assertTrue($bag->has('footer'));
        self::assertSame($footer, $bag->get('footer'));
        self::assertSame('<button>Close</button>', $bag->get('footer')?->toHtml());
        self::assertFalse($bag->has('header'));
        self::assertNull($bag->get('header'));
    }

    public function testEmptySlotAndReturnedMapDoNotMutateTheBag(): void
    {
        $empty = new ComponentSlot('');
        $bag = new SlotBag(['footer' => $empty]);
        $copy = $bag->all();
        $copy['footer'] = new ComponentSlot('replacement');
        $copy['header'] = new ComponentSlot('added');

        self::assertSame('', $empty->toHtml());
        self::assertSame('', $bag->get('footer')?->toHtml());
        self::assertFalse($bag->has('header'));
        self::assertSame(['footer' => $empty], $bag->all());
    }

    /** @dataProvider invalidSlotNames */
    public function testSlotBagRejectsUnsafeNames(string $name): void
    {
        $error = null;
        try {
            new SlotBag([$name => new ComponentSlot('SLOT_SECRET')]);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(Throwable::class, $error);
        self::assertStringNotContainsString('SLOT_SECRET', $error->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSlotNames(): iterable
    {
        yield 'empty' => [''];
        yield 'traversal' => ['../footer'];
        yield 'slash' => ['header/sub'];
        yield 'control' => ["foot\x01er"];
    }
}
