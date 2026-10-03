<?php

declare(strict_types=1);

namespace App\Database\Pagination;

use App\Database\Collections\ModelCollection;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A forward keyset page. Unlike an offset Page, it has no total or page number:
 * obtaining either would require an additional, potentially expensive count.
 */
final class CursorPage implements JsonSerializable
{
    /** @param list<array<string, mixed>>|ModelCollection $items */
    public function __construct(
        private readonly array|ModelCollection $items,
        private readonly int $perPage,
        private readonly ?string $nextCursor
    ) {
        if ($perPage < 1) {
            throw new InvalidArgumentException('Cursor page size must be positive.');
        }
        if ($nextCursor !== null && count($items) === 0) {
            throw new InvalidArgumentException('An empty cursor page cannot have a next cursor.');
        }
    }

    /** @return list<array<string, mixed>>|ModelCollection */
    public function items(): array|ModelCollection
    {
        return $this->items;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => $this->items instanceof ModelCollection ? $this->items->toArray() : $this->items,
            'per_page' => $this->perPage,
            'next_cursor' => $this->nextCursor,
            'has_more' => $this->hasMore(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }
}
