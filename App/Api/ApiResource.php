<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Contract\Schema;
use App\Database\Pagination\Page;
use Closure;
use SensitiveParameter;
use Throwable;

/**
 * An application's explicit public representation of one source value.
 *
 * Only fields returned by toArray() are exposed. The source reference is readonly,
 * not a deep object snapshot; application transformations should be side-effect
 * free. No properties, relationships, or JSON serialization hooks are forwarded.
 * Subclass constructors must accept the source as their only required argument
 * so make() and collection() use the same construction contract.
 *
 * @phpstan-consistent-constructor
 */
abstract class ApiResource extends ResourceResult
{
    public function __construct(#[SensitiveParameter] protected readonly mixed $resource)
    {
    }

    /** Choose public fields explicitly and format value objects before returning them. */
    abstract public function toArray(): array;

    /**
     * Optional public representation schema for the Application Contract.
     * Export never constructs a resource or calls toArray() against fake data.
     */
    public static function contractSchema(): ?Schema
    {
        return null;
    }

    public static function make(#[SensitiveParameter] mixed $resource): static
    {
        try {
            return new static($resource);
        } catch (Throwable $exception) {
            throw new ResourceException('API resource could not be constructed from the supplied source.', 0, $exception);
        }
    }

    /** Preserve source iteration order; Page items are transformed with its existing metadata. */
    final public static function collection(#[SensitiveParameter] iterable|Page $resources): ResourceCollection
    {
        return new ResourceCollection(static::class, $resources);
    }

    /** Null has a stable representation even when the definition expects a non-null source. */
    final protected function representation(): ?array
    {
        return $this->resource === null ? null : $this->toArray();
    }

    /**
     * Omit false fields completely. Pass a Closure for expensive or relation-based
     * values; PHP evaluates ordinary argument expressions before this method runs.
     */
    final protected function when(bool $condition, #[SensitiveParameter] mixed $value): mixed
    {
        if (!$condition) {
            return OmittedValue::Field;
        }
        return $value instanceof Closure ? $value() : $value;
    }
}
