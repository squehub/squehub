<?php

declare(strict_types=1);

namespace App\Database\Pagination;

use App\Database\Collections\ModelCollection;
use InvalidArgumentException;
use JsonSerializable;

/** Offset-page items and metadata without HTTP or URL concerns. */
final class Page implements JsonSerializable
{
    /** @param list<array<string, mixed>>|ModelCollection $items */
    public function __construct(
        private array|ModelCollection $items,
        private int $page,
        private int $perPage,
        private int $total
    ) {
        self::offset($page, $perPage);
        if ($total < 0) {
            throw new InvalidArgumentException('Page total cannot be negative.');
        }
    }

    public static function offset(int $page, int $perPage): int
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Page and items per page must be positive integers.');
        }
        if ($page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw new InvalidArgumentException('Page offset exceeds the supported integer range.');
        }
        $offset = ($page - 1) * $perPage;
        if ($offset > PHP_INT_MAX - $perPage) {
            throw new InvalidArgumentException('Page range exceeds the supported integer range.');
        }
        return $offset;
    }

    /** @return list<array<string, mixed>>|ModelCollection */
    public function items(): array|ModelCollection
    {
        return $this->items;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function pages(): int
    {
        return intdiv($this->total, $this->perPage)
            + ($this->total % $this->perPage === 0 ? 0 : 1);
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages();
    }

    public function hasPrevious(): bool
    {
        return $this->pages() > 0 && $this->page > 1;
    }

    public function nextPage(): ?int
    {
        return $this->hasNext() ? $this->page + 1 : null;
    }

    public function previousPage(): ?int
    {
        return $this->hasPrevious() ? $this->page - 1 : null;
    }

    public function from(): ?int
    {
        return count($this->items) === 0 ? null : self::offset($this->page, $this->perPage) + 1;
    }

    public function to(): ?int
    {
        return count($this->items) === 0 ? null : self::offset($this->page, $this->perPage) + count($this->items);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => $this->items instanceof ModelCollection ? $this->items->toArray() : $this->items,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'pages' => $this->pages(),
            'from' => $this->from(),
            'to' => $this->to(),
            'has_next' => $this->hasNext(),
            'has_previous' => $this->hasPrevious(),
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
