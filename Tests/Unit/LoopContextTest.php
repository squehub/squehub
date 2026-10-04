<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\LoopContext;
use Error;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** The iterable loop metadata exposed to templates is truthful and read-only. */
final class LoopContextTest extends TestCase
{
    public function testFirstAndLastIterationsHaveExactMetadata(): void
    {
        $first = new LoopContext(0, 3);
        self::assertSame(0, $first->index);
        self::assertSame(1, $first->iteration);
        self::assertSame(2, $first->remaining);
        self::assertSame(3, $first->count);
        self::assertTrue($first->first);
        self::assertFalse($first->last);
        self::assertFalse($first->even);
        self::assertTrue($first->odd);
        self::assertSame(1, $first->depth);
        self::assertNull($first->parent);

        $last = new LoopContext(2, 3);
        self::assertSame(2, $last->index);
        self::assertSame(3, $last->iteration);
        self::assertSame(0, $last->remaining);
        self::assertSame(3, $last->count);
        self::assertFalse($last->first);
        self::assertTrue($last->last);
        self::assertFalse($last->even);
        self::assertTrue($last->odd);
    }

    public function testParityUsesOneBasedIterationAndSingleItemIsBothFirstAndLast(): void
    {
        $second = new LoopContext(1, 3);
        self::assertSame(2, $second->iteration);
        self::assertTrue($second->even);
        self::assertFalse($second->odd);
        self::assertFalse($second->first);
        self::assertFalse($second->last);

        $only = new LoopContext(0, 1);
        self::assertTrue($only->first);
        self::assertTrue($only->last);
        self::assertSame(0, $only->remaining);
    }

    public function testParentLinksRepresentTheCurrentIterationThroughThreeLevels(): void
    {
        $outer = new LoopContext(1, 4);
        $middle = new LoopContext(0, 2, $outer);
        $inner = new LoopContext(2, 3, $middle);

        self::assertSame(1, $outer->depth);
        self::assertSame(2, $middle->depth);
        self::assertSame(3, $inner->depth);
        self::assertSame($middle, $inner->parent);
        self::assertSame($outer, $inner->parent->parent);
        self::assertSame(2, $inner->parent->parent->iteration);
    }

    public function testPropertiesCannotBeModifiedAfterConstruction(): void
    {
        $context = new LoopContext(0, 2);
        $reflection = new ReflectionClass($context);
        foreach (['index', 'iteration', 'remaining', 'count', 'first', 'last',
            'even', 'odd', 'depth', 'parent'] as $property) {
            self::assertTrue($reflection->getProperty($property)->isReadOnly(), $property);
        }

        try {
            $reflection->getProperty('index')->setValue($context, 99);
            self::fail('LoopContext index must not be mutable.');
        } catch (Error $error) {
            self::assertSame(0, $context->index);
        }
    }
}
