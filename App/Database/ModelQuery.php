<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Collections\ModelCollection;
use App\Database\Pagination\Page;
use App\Database\Pagination\CursorPage;
use App\Database\Relations\RelationLoader;
use App\Database\Relations\RelationException;
use App\Database\Relations\MorphTo;
use App\Database\Exception\ScopeException;
use LogicException;
use Throwable;

/** A thin, model-aware read query over the SqueHub table QueryBuilder. */
final class ModelQuery
{
    /** @var list<string> */
    private array $eagerRelations = [];
    /** Deleted-record policy is applied to a cloned builder at consumption time. */
    private string $deletedMode = 'default';
    /** Nested relation-existence callbacks need a separate alias plan. */
    private bool $insideRelationConstraint = false;
    /** @var array<string, string> Public count name => framework SQL alias. */
    private array $countProjections = [];

    /** @param class-string<Model> $modelClass */
    public function __construct(
        private string $modelClass,
        private QueryBuilder $query,
        private string $primaryKey,
        private ?Connection $connection = null,
        private ?ModelClock $clock = null,
        private ?string $table = null,
        private ?string $deletedColumn = null,
        // Pin the originating manager until deferred hydration completes. A
        // Connection only keeps a weak owner reference to avoid a cycle.
        private ?DatabaseManager $manager = null
    ) {
    }

    /**
     * Apply an explicit Model scope lazily to this query. A failed or invalid
     * scope restores prior query state and names the scope in its exception.
     */
    public function scope(string $name, mixed ...$arguments): self
    {
        $modelClass = $this->modelClass;
        $scope = $modelClass::namedScope($name);
        $before = clone $this;
        try {
            $result = $scope($this, ...$arguments);
        } catch (Throwable $exception) {
            $this->query = $before->query;
            $this->eagerRelations = $before->eagerRelations;
            $this->deletedMode = $before->deletedMode;
            $this->countProjections = $before->countProjections;
            throw new ScopeException("Scope {$modelClass}::{$name} failed.", 0, $exception);
        }
        if ($result !== $this) {
            $this->query = $before->query;
            $this->eagerRelations = $before->eagerRelations;
            $this->deletedMode = $before->deletedMode;
            $this->countProjections = $before->countProjections;
            throw new ScopeException("Scope {$modelClass}::{$name} must return the active ModelQuery.");
        }
        return $this;
    }

    public function withDeleted(): self
    {
        $this->requireSoftDeletes();
        $this->deletedMode = 'include';
        return $this;
    }

    public function onlyDeleted(): self
    {
        $this->requireSoftDeletes();
        $this->deletedMode = 'only';
        return $this;
    }

    private function requireSoftDeletes(): void
    {
        if ($this->deletedColumn === null) {
            throw new LogicException("Model {$this->modelClass} does not use soft deletes.");
        }
    }

    private function effectiveQuery(): QueryBuilder
    {
        $query = clone $this->query;
        if ($this->deletedColumn !== null) {
            if ($this->deletedMode === 'default') {
                $query->requireNull($this->deletedColumn);
            } elseif ($this->deletedMode === 'only') {
                $query->requireNull($this->deletedColumn, true);
            }
        }
        return $query;
    }

    /** @internal Expose only a cloned, policy-constrained table query to relation SQL. */
    public function relationSubquery(): QueryBuilder
    {
        return $this->effectiveQuery();
    }

    /** @internal A user callback cannot introduce another relation predicate implicitly. */
    public function asRelationConstraint(): self
    {
        $query = clone $this;
        $query->insideRelationConstraint = true;
        return $query;
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }

    /** @internal Used to reject many-to-many mappings that cannot share one transaction. */
    public function connection(): ?Connection
    {
        return $this->connection;
    }

    /** @param string|list<string> $relations */
    public function with(string|array $relations): self
    {
        $this->eagerRelations = array_values(array_unique(array_merge(
            $this->eagerRelations,
            RelationLoader::names($relations)
        )));
        return $this;
    }

    /**
     * Add correlated counts to the SELECT. They are read-only Model projections:
     * neither hydration nor a later save can treat them as persisted columns.
     *
     * @param string|array<array-key, mixed> $relations Names or name => constraint pairs.
     */
    public function withCount(string|array $relations): self
    {
        if ($this->connection === null || $this->table === null) {
            throw new LogicException('Relation counts require an originating Model connection and table.');
        }
        $definitions = is_string($relations) ? [$relations] : $relations;
        if ($definitions === []) {
            throw new RelationException('withCount requires at least one relation.');
        }
        $candidate = clone $this->query;
        $projections = $this->countProjections;
        $modelClass = $this->modelClass;
        $prototype = $modelClass::hydrate([], $this->connection, $this->clock, $this->table, $this->manager);
        foreach ($definitions as $key => $value) {
            $name = is_int($key) ? $value : $key;
            $constraint = is_int($key) ? null : $value;
            if (!is_string($name) || ($constraint !== null && !is_callable($constraint))) {
                throw new RelationException('withCount requires relation names and optional callable constraints.');
            }
            $names = RelationLoader::names($name);
            if (count($names) !== 1 || str_contains($name, '.')) {
                throw new RelationException('withCount currently supports one direct relation per name.');
            }
            $public = $name . '_count';
            if (isset($projections[$public])) {
                throw new RelationException('A relation count projection is already selected.');
            }
            $relation = $prototype->relation($name);
            $subquery = $relation->existenceQuery($this->table, $constraint);
            $internal = 'squehub_relcount_' . substr(hash('sha256', $modelClass . ':' . $public), 0, 24);
            $candidate->countProjection($internal, $subquery);
            $projections[$public] = $internal;
        }
        $this->query = $candidate;
        $this->countProjections = $projections;
        return $this;
    }

    /** @param list<string> $columns */
    public function select(array $columns): self
    {
        $this->query->select($columns);

        return $this;
    }

    public function filter(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $this->query->filter($column, $operatorOrValue);
        } else {
            $this->query->filter($column, $operatorOrValue, $value);
        }

        return $this;
    }

    public function orFilter(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $this->query->orFilter($column, $operatorOrValue);
        } else {
            $this->query->orFilter($column, $operatorOrValue, $value);
        }

        return $this;
    }

    /** Group table predicates while retaining the Model's default policies. */
    public function filterGroup(callable $callback): self
    {
        $this->query->filterGroup($callback);
        return $this;
    }

    public function orFilterGroup(callable $callback): self
    {
        $this->query->orFilterGroup($callback);
        return $this;
    }

    public function filterColumn(string $left, string $operator, string $right): self
    {
        $this->query->filterColumn($left, $operator, $right);
        return $this;
    }

    public function filterExists(QueryBuilder $subquery): self
    {
        $this->query->filterExists($subquery);
        return $this;
    }

    public function filterNotExists(QueryBuilder $subquery): self
    {
        $this->query->filterNotExists($subquery);
        return $this;
    }

    public function filterInQuery(string $column, QueryBuilder $subquery): self
    {
        $this->query->filterInQuery($column, $subquery);
        return $this;
    }

    public function filterNotInQuery(string $column, QueryBuilder $subquery): self
    {
        $this->query->filterNotInQuery($column, $subquery);
        return $this;
    }

    public function filterSubquery(string $column, string $operator, QueryBuilder $subquery): self
    {
        $this->query->filterSubquery($column, $operator, $subquery);
        return $this;
    }

    /** Keep related rows out of memory while filtering by a declared relation. */
    public function has(string $relation): self
    {
        return $this->addRelationExists($relation, null, false);
    }

    public function doesntHave(string $relation): self
    {
        return $this->addRelationExists($relation, null, true);
    }

    /** The callback receives a related ModelQuery for filters and scopes. */
    public function whereHas(string $relation, callable $constraint): self
    {
        return $this->addRelationExists($relation, $constraint, false);
    }

    /**
     * Select only trusted MorphMap aliases. Each target gets its own callback
     * because heterogeneous Models cannot share a generic filter safely.
     *
     * @param array<string, callable(ModelQuery): mixed|null> $constraints
     */
    public function whereHasMorph(string $relation, array $constraints): self
    {
        if ($this->insideRelationConstraint) {
            throw new RelationException('Nested relation predicates inside a constraint require a dotted relation path.');
        }
        if ($this->connection === null || $this->table === null) {
            throw new LogicException('MorphTo existence requires an originating Model connection and table.');
        }
        RelationLoader::names($relation);
        if (str_contains($relation, '.')) {
            throw new RelationException('whereHasMorph requires a direct MorphTo relation.');
        }
        $modelClass = $this->modelClass;
        $prototype = $modelClass::hydrate([], $this->connection, $this->clock, $this->table, $this->manager);
        $target = $prototype->relation($relation);
        if (!$target instanceof MorphTo) {
            throw new RelationException('whereHasMorph requires a MorphTo relation.');
        }
        $subquery = $target->existenceQueryForAliases($this->table, $constraints);
        $this->query->requireExists($subquery);
        return $this;
    }

    private function addRelationExists(string $name, ?callable $constraint, bool $negative): self
    {
        if ($this->insideRelationConstraint) {
            throw new RelationException('Nested relation predicates inside a constraint require a dotted relation path.');
        }
        if ($this->connection === null || $this->table === null) {
            throw new LogicException('Relation existence requires an originating Model connection and table.');
        }
        $path = RelationLoader::names($name)[0];
        $subquery = $this->nestedRelationExists(explode('.', $path), $this->table, $constraint);
        $this->query->requireExists($subquery, $negative);
        return $this;
    }

    /**
     * Compose one correlated EXISTS per relation hop. Only the final relation
     * receives the caller's callback; intermediate predicates remain mandatory
     * even if that callback adds OR conditions to the terminal Model query.
     *
     * @param non-empty-list<string> $segments
     */
    private function nestedRelationExists(array $segments, string $parentAlias,
        ?callable $terminalConstraint): QueryBuilder
    {
        if ($this->connection === null || $this->table === null) {
            throw new LogicException('Relation existence requires an originating Model connection and table.');
        }
        $modelClass = $this->modelClass;
        $prototype = $modelClass::hydrate([], $this->connection, $this->clock, $this->table, $this->manager);
        $relation = $prototype->relation(array_shift($segments));
        if ($segments === []) {
            return $relation->existenceQuery($parentAlias, $terminalConstraint);
        }
        if ($relation instanceof MorphTo) {
            throw new RelationException('Nested existence cannot traverse beyond a MorphTo relation; select a type-specific path.');
        }

        $classes = $relation->relatedClasses();
        if (count($classes) !== 1) {
            throw new RelationException('Nested relation existence requires one known related Model type per hop.');
        }
        $relatedClass = $classes[0];
        $relatedQuery = $relatedClass::queryOn($prototype->relationManager());
        $child = $relatedQuery->nestedRelationExists($segments,
            $relation->existenceRelatedAlias($parentAlias), $terminalConstraint);
        // A closure declared here may access the cloned ModelQuery's private
        // builder without exposing relation-only mutation as public API.
        return $relation->existenceQuery($parentAlias,
            static function (ModelQuery $query) use ($child): ModelQuery {
                $query->query->requireExists($child);
                return $query;
            });
    }

    public function filterIn(string $column, array $values): self
    {
        $this->query->filterIn($column, $values);

        return $this;
    }

    public function filterNotIn(string $column, array $values): self
    {
        $this->query->filterNotIn($column, $values);

        return $this;
    }

    public function filterBetween(string $column, mixed $minimum, mixed $maximum): self
    {
        $this->query->filterBetween($column, $minimum, $maximum);

        return $this;
    }

    public function filterNull(string $column): self
    {
        $this->query->filterNull($column);

        return $this;
    }

    public function filterNotNull(string $column): self
    {
        $this->query->filterNotNull($column);

        return $this;
    }

    public function sort(string $column, string $direction = 'asc'): self
    {
        $this->query->sort($column, $direction);

        return $this;
    }

    public function limit(int $count): self
    {
        $this->query->limit($count);

        return $this;
    }

    public function skip(int $count): self
    {
        $this->query->skip($count);

        return $this;
    }

    public function lockForUpdate(): self
    {
        $this->query->lockForUpdate();
        return $this;
    }

    public function lockShared(): self
    {
        $this->query->lockShared();
        return $this;
    }

    public function all(): ModelCollection
    {
        return $this->hydrateRows($this->effectiveQuery()->all());
    }

    public function get(): ModelCollection
    {
        return $this->all();
    }

    public function page(int $page, int $perPage): Page
    {
        $rows = $this->effectiveQuery()->page($page, $perPage);
        return new Page($this->hydrateRows($rows->items()), $page, $perPage, $rows->total());
    }

    /** Hydrate only the current keyset window and eager-load its relations in batches. */
    public function cursorPage(int $limit, ?string $after = null): CursorPage
    {
        $rows = $this->effectiveQuery()->cursorPage($limit, $after, $this->primaryKey);
        return new CursorPage($this->hydrateRows($rows->items()), $limit, $rows->nextCursor());
    }

    /** Visit bounded hydrated batches; a false callback result stops traversal. */
    public function chunk(int $size, callable $callback): int
    {
        $after = null;
        $batches = 0;
        do {
            $page = $this->cursorPage($size, $after);
            $items = $page->items();
            if (!$items instanceof ModelCollection) {
                throw new LogicException('Model cursor pages must contain a ModelCollection.');
            }
            if ($items->isEmpty()) {
                break;
            }
            ++$batches;
            if ($callback($items, $batches) === false) {
                break;
            }
            $after = $page->nextCursor();
        } while ($after !== null);
        return $batches;
    }

    /** @param list<array<string, mixed>> $rows */
    private function hydrateRows(array $rows): ModelCollection
    {
        $models = array_map(
            fn (array $row): Model => $this->hydrateRow($row),
            $rows
        );
        $modelClass = $this->modelClass;
        RelationLoader::load($models, $this->eagerRelations, $modelClass);
        return new ModelCollection($models);
    }

    /** @param array<string, mixed> $row */
    private function hydrateRow(array $row): Model
    {
        $counts = [];
        foreach ($this->countProjections as $public => $internal) {
            if (!array_key_exists($internal, $row)
                || (!is_int($row[$internal]) && !is_string($row[$internal]))
                || preg_match('/\A[0-9]+\z/D', (string) $row[$internal]) !== 1) {
                throw new LogicException('A relation count projection was unavailable or invalid.');
            }
            $counts[$public] = (int) $row[$internal];
            unset($row[$internal]);
        }
        $modelClass = $this->modelClass;
        $model = $modelClass::hydrate($row, $this->connection, $this->clock, $this->table, $this->manager);
        foreach ($counts as $name => $value) {
            $model->setQueryProjection($name, $value);
        }
        return $model;
    }

    public function first(): ?Model
    {
        $row = $this->effectiveQuery()->first();
        if ($row === null) {
            RelationLoader::load([], $this->eagerRelations, $this->modelClass);
            return null;
        }

        $model = $this->hydrateRow($row);
        RelationLoader::load([$model], $this->eagerRelations);
        return $model;
    }

    public function find(int|string $id): ?Model
    {
        return $this->filter($this->primaryKey, $id)->first();
    }

    public function exists(): bool
    {
        return $this->effectiveQuery()->exists();
    }

    public function count(): int
    {
        return $this->effectiveQuery()->count();
    }

    public function toSql(): string
    {
        return $this->effectiveQuery()->toSql();
    }

    public function bindings(): array
    {
        return $this->effectiveQuery()->bindings();
    }
}
