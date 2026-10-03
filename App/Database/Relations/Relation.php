<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Identifier;
use App\Database\DatabaseManager;
use App\Database\Collections\ModelCollection;
use App\Database\Model;
use App\Database\ModelQuery;
use App\Database\QueryBuilder;
use ReflectionClass;

/** A model query with its parent-key constraint deferred until execution. */
abstract class Relation
{
    protected ModelQuery $query;
    /** @var class-string<Model>|null */
    protected ?string $targetClass = null;

    /** @param class-string<Model>|null $relatedClass A morph-to relation has no single target query. */
    public function __construct(protected Model $parent, ?string $relatedClass = null)
    {
        if ($relatedClass === null) {
            return;
        }
        if (!is_a($relatedClass, Model::class, true)
            || !(new ReflectionClass($relatedClass))->isInstantiable()) {
            throw new RelationException('A related class must be a concrete App\\Database\\Model.');
        }
        $this->targetClass = $relatedClass;
        $this->query = $relatedClass::queryOn($this->manager());
    }

    /** @return list<class-string<Model>> Target types used to validate nested paths on empty results. */
    public function relatedClasses(): array
    {
        return $this->targetClass === null ? [] : [$this->targetClass];
    }

    /** Uniform relation modifiers cannot be applied to heterogeneous morph targets. */
    private function relatedQuery(): ModelQuery
    {
        if (!isset($this->query)) {
            throw new RelationException('A polymorphic relation has no single related Model query.');
        }
        return $this->query;
    }

    /** A hydrated parent pins its originating Application's connections and morph map. */
    protected function manager(): DatabaseManager
    {
        return $this->parent->relationManager();
    }

    public function filter(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $this->relatedQuery()->filter($column, $operatorOrValue);
        } else {
            $this->relatedQuery()->filter($column, $operatorOrValue, $value);
        }
        return $this;
    }

    /** Familiar spelling for a constrained relation; model queries retain filter(). */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            return $this->filter($column, $operatorOrValue);
        }
        return $this->filter($column, $operatorOrValue, $value);
    }

    public function sort(string $column, string $direction = 'asc'): static
    {
        $this->relatedQuery()->sort($column, $direction);
        return $this;
    }

    public function limit(int $count): static
    {
        $this->relatedQuery()->limit($count);
        return $this;
    }

    public function skip(int $count): static
    {
        $this->relatedQuery()->skip($count);
        return $this;
    }

    public function scope(string $name, mixed ...$arguments): static
    {
        $this->relatedQuery()->scope($name, ...$arguments);
        return $this;
    }

    public function withDeleted(): static
    {
        $this->relatedQuery()->withDeleted();
        return $this;
    }

    public function onlyDeleted(): static
    {
        $this->relatedQuery()->onlyDeleted();
        return $this;
    }

    /**
     * Build a correlated read used by ModelQuery::has()/whereHas(). A relation
     * with heterogeneous targets must opt in explicitly rather than silently
     * selecting one target and returning incorrect existence results.
     */
    public function existenceQuery(string $parentTable, ?callable $constraint = null): QueryBuilder
    {
        throw new RelationException('Existence filtering is unsupported for this relationship type.');
    }

    /**
     * Name the related row inside this relation's correlated existence query.
     * A nested relation must correlate to that row, not to a pivot or the
     * outer table. Subclasses with an intermediate table override this alias.
     */
    public function existenceRelatedAlias(string $parentTable): string
    {
        return self::existenceAlias($parentTable);
    }

    /** Apply filters to a cloned related query before its default Model policy. */
    protected function relatedExistenceQuery(string $parentTable, ?callable $constraint,
        string $alias): QueryBuilder
    {
        $related = $this->query->asRelationConstraint();
        if ($constraint !== null) {
            $result = $constraint($related);
            if ($result !== null && $result !== $related) {
                throw new RelationException('A relation constraint must return the active ModelQuery or nothing.');
            }
        }
        return $related->relationSubquery()->forRelationAlias($alias);
    }

    /** Deterministic aliases keep self-relations separate from the outer table. */
    protected static function existenceAlias(string $parentTable, string $kind = 'related'): string
    {
        return 'squehub_' . $kind . '_' . substr(hash('sha256', $parentTable), 0, 12);
    }

    abstract public function get(): mixed;

    abstract public function first(): ?Model;

    /** @param list<Model> $parents */
    abstract public function eagerLoad(array $parents, string $name): void;

    protected function parentValue(Model $model, string $key): int|string|null
    {
        $attributes = $model->attributes();
        if (!array_key_exists($key, $attributes) || $attributes[$key] === null) {
            return null;
        }
        return self::keyValue($attributes[$key]);
    }

    protected static function keyValue(mixed $value): int|string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new RelationException('A relationship key must be an integer or string.');
        }
        return $value;
    }

    protected static function fingerprint(int|string $value): string
    {
        // PDO may hydrate a numeric key as int while a newly inserted model holds string.
        return 'key:' . (string) $value;
    }

    protected static function column(string $name): string
    {
        return Identifier::simple($name);
    }

    protected static function foreignKey(Model $model): string
    {
        $short = (new ReflectionClass($model))->getShortName();
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
        return self::column($snake . '_id');
    }

    /** @param list<int|string> $keys @return list<Model> */
    protected function batched(string $column, array $keys): array
    {
        $rows = [];
        // Stay below older SQLite placeholder limits without querying per parent.
        foreach (array_chunk($keys, 500) as $chunk) {
            $query = clone $this->query;
            foreach ($query->filterIn($column, $chunk)->all() as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @param list<Model> $parents */
    protected function eagerChildren(array $parents, string $name, string $localKey, string $foreignKey, bool $single): void
    {
        $keys = [];
        foreach ($parents as $parent) {
            $value = $this->parentValue($parent, $localKey);
            if ($value !== null) {
                $keys[self::fingerprint($value)] = $value;
            }
        }
        $grouped = [];
        if ($keys !== []) {
            foreach ($this->batched($foreignKey, array_values($keys)) as $related) {
                $value = $this->parentValue($related, $foreignKey);
                if ($value !== null) {
                    $grouped[self::fingerprint($value)][] = $related;
                }
            }
        }
        foreach ($parents as $parent) {
            $value = $this->parentValue($parent, $localKey);
            $matches = $value === null ? [] : ($grouped[self::fingerprint($value)] ?? []);
            $parent->setRelation($name, $single ? ($matches[0] ?? null) : new ModelCollection($matches));
        }
    }
}
