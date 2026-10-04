<?php

declare(strict_types=1);

namespace App\View;

use Generator;
use InvalidArgumentException;
use Traversable;

/**
 * Prepares the actual sequence once so count, last, and remaining stay true.
 * Arrays remain copy-on-write; Traversable values are buffered as ordered pairs
 * to retain duplicate yielded keys and avoid rewinding one-pass generators.
 */
final class LoopSequence
{
    /** @param array<array-key, mixed>|null $arraySource
     *  @param list<array{0: array-key, 1: mixed}> $pairs
     */
    private function __construct(
        private readonly ?array $arraySource,
        private readonly array $pairs,
        private readonly int $count
    ) {
    }

    public static function prepare(mixed $source): self
    {
        if (is_array($source)) {
            return new self($source, [], count($source));
        }
        if (!$source instanceof Traversable) {
            throw new InvalidArgumentException('Iterable template loop expects an array or Traversable value.');
        }

        $pairs = [];
        foreach ($source as $key => $value) {
            $pairs[] = [$key, $value];
        }
        return new self(null, $pairs, count($pairs));
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return Generator<array-key, mixed> */
    public function iterate(): Generator
    {
        if ($this->arraySource !== null) {
            foreach ($this->arraySource as $key => $value) {
                yield $key => $value;
            }
            return;
        }

        foreach ($this->pairs as [$key, $value]) {
            yield $key => $value;
        }
    }
}
