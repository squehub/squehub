<?php

declare(strict_types=1);

namespace App\View;

use InvalidArgumentException;

/**
 * Immutable metadata for one rendered item of an iterable template loop.
 * Positions describe the prepared sequence, independently of its PHP keys.
 */
final readonly class LoopContext
{
    public int $iteration;
    public int $remaining;
    public bool $first;
    public bool $last;
    public bool $even;
    public bool $odd;
    public int $depth;

    public function __construct(
        public int $index,
        public int $count,
        public ?self $parent = null
    ) {
        if ($count < 1 || $index < 0 || $index >= $count) {
            throw new InvalidArgumentException('Loop position must identify an item in a non-empty sequence.');
        }

        $this->iteration = $index + 1;
        $this->remaining = $count - $this->iteration;
        $this->first = $index === 0;
        $this->last = $this->iteration === $count;
        $this->even = $this->iteration % 2 === 0;
        $this->odd = !$this->even;
        $this->depth = ($parent?->depth ?? 0) + 1;
    }
}
