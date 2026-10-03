<?php

declare(strict_types=1);

namespace App\Api;

use App\Database\Pagination\Page;
use ReflectionClass;
use SensitiveParameter;
use Throwable;

/**
 * Ordered resource items, optionally accompanied by an existing Page's metadata.
 *
 * Iterables are captured once, making generators repeatable on subsequent resolve
 * calls. Items retain their source objects; transformation never calls Model or
 * Page serialization and never fetches additional data on the framework's behalf.
 */
final class ResourceCollection extends ResourceResult
{
    /** @var list<mixed> */
    private readonly array $items;

    private readonly ?array $pagination;

    /** @param class-string<ApiResource> $resourceClass */
    public function __construct(
        private readonly string $resourceClass,
        #[SensitiveParameter] iterable|Page $resources
    ) {
        if (!is_subclass_of($resourceClass, ApiResource::class)
            || !(new ReflectionClass($resourceClass))->isInstantiable()) {
            throw new ResourceException('Resource collections require a concrete ApiResource definition.');
        }

        $this->pagination = $resources instanceof Page ? [
            'page' => $resources->page(),
            'per_page' => $resources->perPage(),
            'total' => $resources->total(),
            'pages' => $resources->pages(),
            'from' => $resources->from(),
            'to' => $resources->to(),
            'has_next' => $resources->hasNext(),
            'has_previous' => $resources->hasPrevious(),
        ] : null;

        try {
            $items = [];
            foreach ($resources instanceof Page ? $resources->items() : $resources as $item) {
                $items[] = $item;
            }
            $this->items = $items;
        } catch (Throwable $exception) {
            throw new ResourceException('Resource collection input could not be read.', 0, $exception);
        }
    }

    protected function representation(): array
    {
        $items = [];
        foreach ($this->items as $item) {
            $items[] = $this->resourceClass::make($item);
        }
        return $items;
    }

    protected function paginationMetadata(): ?array
    {
        return $this->pagination;
    }
}
