<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\LoopSequence;
use ArrayIterator;
use IteratorAggregate;
use PHPUnit\Framework\TestCase;
use Traversable;

/** Sequence preparation counts actual items without changing their keys or values. */
final class LoopSequenceTest extends TestCase
{
    public function testArrayKeysAndValuesRemainUnchangedWhilePositionsStaySeparate(): void
    {
        $source = [10 => 'A', 40 => 'B', 'guest' => 'C'];
        $sequence = LoopSequence::prepare($source);

        self::assertSame(3, $sequence->count());
        self::assertSame([[10, 'A'], [40, 'B'], ['guest', 'C']], self::pairs($sequence->iterate()));
        self::assertSame([10 => 'A', 40 => 'B', 'guest' => 'C'], $source);
    }

    public function testGeneratorIsConsumedOnceAndDuplicateYieldedKeysArePreserved(): void
    {
        $starts = 0;
        $yields = 0;
        $generator = (static function () use (&$starts, &$yields): Traversable {
            ++$starts;
            ++$yields;
            yield 'same' => 'A';
            ++$yields;
            yield 'same' => 'B';
            ++$yields;
            yield 40 => 'C';
        })();

        $sequence = LoopSequence::prepare($generator);
        self::assertSame(3, $sequence->count());
        self::assertSame([['same', 'A'], ['same', 'B'], [40, 'C']],
            self::pairs($sequence->iterate()));
        self::assertSame(1, $starts);
        self::assertSame(3, $yields);
    }

    public function testEmptyGeneratorAndIteratorAggregateHaveAccurateCounts(): void
    {
        $empty = (static function (): Traversable {
            yield from [];
        })();
        $emptySequence = LoopSequence::prepare($empty);
        self::assertSame(0, $emptySequence->count());
        self::assertSame([], self::pairs($emptySequence->iterate()));

        $aggregate = new class implements IteratorAggregate {
            public function getIterator(): Traversable
            {
                return new ArrayIterator(['admin' => 'Ada', 'guest' => 'Bea']);
            }
        };
        $sequence = LoopSequence::prepare($aggregate);
        self::assertSame(2, $sequence->count());
        self::assertSame([['admin', 'Ada'], ['guest', 'Bea']],
            self::pairs($sequence->iterate()));
    }

    /** @return list<array{int|string, mixed}> */
    private static function pairs(iterable $items): array
    {
        $pairs = [];
        foreach ($items as $key => $value) {
            $pairs[] = [$key, $value];
        }
        return $pairs;
    }
}
