<?php

declare(strict_types=1);

namespace App\Database\Collections;

use App\Database\Model;
use App\Database\Relations\RelationLoader;
use ArrayAccess;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

/** An ordered, read-only wrapper for already-hydrated modern Models. */
final class ModelCollection implements Countable, IteratorAggregate, ArrayAccess, JsonSerializable
{
    /** @var list<Model> */
    private array $items;

    /** @param iterable<Model> $items */
    public function __construct(iterable $items = [])
    {
        $this->items = [];
        foreach ($items as $item) {
            if (!$item instanceof Model) {
                throw new InvalidArgumentException('ModelCollection accepts only modern Model instances.');
            }
            $this->items[] = $item;
        }
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    public function first(): ?Model
    {
        return $this->items[0] ?? null;
    }

    public function last(): ?Model
    {
        return $this->items[count($this->items) - 1] ?? null;
    }

    public function at(int $index): ?Model
    {
        return $this->items[$index] ?? null;
    }

    /** @return list<mixed> Explicit attribute access; unlike serialization, it can read hidden fields. */
    public function pluck(string $attribute): array
    {
        return array_map(static fn (Model $model): mixed => $model->getAttribute($attribute), $this->items);
    }

    /** @return list<mixed> */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->items);
    }

    public function filter(callable $predicate): self
    {
        return new self(array_values(array_filter($this->items, $predicate)));
    }

    /** Batch-load nested or one-level relations on the existing model instances. */
    public function load(string|array $relations): self
    {
        RelationLoader::load($this->items, RelationLoader::names($relations));
        return $this;
    }

    /** @return Traversable<int, Model> */
    public function getIterator(): Traversable
    {
        yield from $this->items;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): ?Model
    {
        return is_int($offset) ? $this->at($offset) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('ModelCollection is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('ModelCollection is read-only.');
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (Model $model): array => $model->toArray(), $this->items);
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }
}
