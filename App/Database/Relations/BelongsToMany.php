<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Connection;
use App\Database\Collections\ModelCollection;
use App\Database\Identifier;
use App\Database\Model;
use App\Database\QueryBuilder;
use Throwable;

/**
 * Maps a unique parent/related pivot edge without merging pivot fields into Model state.
 * The parent, pivot, and related table must use the same Connection instance.
 */
final class BelongsToMany extends Relation
{
    private Connection $connection;
    private string $foreignPivotKey;
    private string $relatedPivotKey;
    private string $parentKey;
    private string $relatedKey;
    /** @var list<string> */
    private array $pivotColumns = [];
    /** @var list<array{string, mixed}> */
    private array $pivotFilters = [];

    /** @param class-string<Model> $relatedClass */
    public function __construct(
        Model $parent,
        private string $relatedClass,
        private string $pivotTable,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ) {
        parent::__construct($parent, $relatedClass);
        $related = new $relatedClass();
        $this->pivotTable = Identifier::simple($pivotTable);
        $this->foreignPivotKey = self::column($foreignPivotKey ?? self::foreignKey($parent));
        $this->relatedPivotKey = self::column($relatedPivotKey ?? self::foreignKey($related));
        $this->parentKey = self::column($parentKey ?? $parent->primaryKeyName());
        $this->relatedKey = self::column($relatedKey ?? $related->primaryKeyName());
        if (strcasecmp($this->foreignPivotKey, $this->relatedPivotKey) === 0) {
            throw new RelationException('A many-to-many pivot requires two distinct key columns.');
        }
        $this->connection = $parent->relationConnection();
        if ($this->connection !== $this->query->connection()) {
            throw new RelationException('A many-to-many relation requires parent, pivot, and related models on the same connection.');
        }
    }

    /** Select only explicitly requested metadata in addition to both pivot keys. */
    public function pivot(array $columns): static
    {
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new RelationException('Pivot columns must be names.');
            }
            $column = self::column($column);
            if (!in_array($column, $this->pivotColumns, true)) {
                $this->pivotColumns[] = $column;
            }
        }
        return $this;
    }

    /** Pivot filters affect reads only; filter() remains a related-table filter. */
    public function pivotFilter(string $column, mixed $value): static
    {
        $this->pivotFilters[] = [self::column($column), $value];
        return $this;
    }

    public function get(): ModelCollection
    {
        return new ModelCollection($this->resolve([$this->parent])[spl_object_id($this->parent)] ?? []);
    }

    public function first(): ?Model
    {
        return $this->get()->first();
    }

    /**
     * Correlate pivot edges to the outer row, then test a related Model query.
     * Nested EXISTS keeps related soft-delete filters unambiguous even when a
     * pivot happens to use the same column names as its target table.
     */
    public function existenceQuery(string $parentTable, ?callable $constraint = null): QueryBuilder
    {
        $pivotAlias = self::existenceAlias($parentTable, 'pivot');
        $relatedAlias = self::existenceAlias($parentTable, 'related');
        $related = $this->relatedExistenceQuery($parentTable, $constraint, $relatedAlias)
            ->requireColumn($relatedAlias . '.' . $this->relatedKey, '=',
                $pivotAlias . '.' . $this->relatedPivotKey);
        $pivot = $this->connection->table($this->pivotTable)->forRelationAlias($pivotAlias);
        foreach ($this->pivotFilters as [$column, $value]) {
            if ($value === null) {
                $pivot->filterNull($column);
            } else {
                $pivot->filter($column, $value);
            }
        }
        return $pivot->filterExists($related)
            ->requireColumn($pivotAlias . '.' . $this->foreignPivotKey, '=',
                $parentTable . '.' . $this->parentKey);
    }

    public function existenceRelatedAlias(string $parentTable): string
    {
        return self::existenceAlias($parentTable, 'related');
    }

    /** @param list<Model> $parents */
    public function eagerLoad(array $parents, string $name): void
    {
        $resolved = $this->resolve($parents);
        foreach ($parents as $parent) {
            $parent->setRelation($name, new ModelCollection($resolved[spl_object_id($parent)] ?? []));
        }
    }

    /** @param int|string|Model|list<int|string|Model> $relatedIds */
    public function attach(int|string|Model|array $relatedIds, array $pivotData = []): int
    {
        $parentId = $this->writeParentKey();
        $ids = $this->ids($relatedIds);
        $data = $this->writePivotData($pivotData);
        if ($ids === []) {
            return 0;
        }
        $attached = $this->transactional(function () use ($parentId, $ids, $data): int {
            $existing = $this->currentIds($parentId, $ids);
            $count = 0;
            foreach ($ids as $fingerprint => $id) {
                if (isset($existing[$fingerprint])) {
                    continue;
                }
                $count += $this->connection->table($this->pivotTable)->insert([
                    $this->foreignPivotKey => $parentId,
                    $this->relatedPivotKey => $id,
                ] + $data);
            }
            return $count;
        });
        if ($attached > 0) {
            $this->parent->clearRelations();
        }
        return $attached;
    }

    /** An empty list detaches nothing. Use detachAll() to remove every edge. */
    public function detach(int|string|Model|array $relatedIds): int
    {
        $parentId = $this->writeParentKey();
        $ids = array_values($this->ids($relatedIds));
        if ($ids === []) {
            return 0;
        }
        $removed = $this->transactional(function () use ($parentId, $ids): int {
            $count = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $count += $this->connection->table($this->pivotTable)
                    ->filter($this->foreignPivotKey, $parentId)
                    ->filterIn($this->relatedPivotKey, $chunk)->delete();
            }
            return $count;
        });
        if ($removed > 0) {
            $this->parent->clearRelations();
        }
        return $removed;
    }

    public function detachAll(): int
    {
        $parentId = $this->writeParentKey();
        $removed = $this->connection->table($this->pivotTable)
            ->filter($this->foreignPivotKey, $parentId)->delete();
        if ($removed > 0) {
            $this->parent->clearRelations();
        }
        return $removed;
    }

    /**
     * Synchronize IDs, preserving unchanged edges and their metadata.
     * Owns a transaction only when the caller has not already begun one.
     *
     * @param list<int|string|Model> $relatedIds
     * @return array{attached: int, detached: int}
     */
    public function sync(array $relatedIds): array
    {
        $parentId = $this->writeParentKey();
        $wanted = $this->ids($relatedIds);
        $result = $this->transactional(function () use ($parentId, $wanted): array {
            $current = $this->currentIds($parentId);
            $remove = array_values(array_diff_key($current, $wanted));
            $add = array_diff_key($wanted, $current);
            $detached = 0;
            foreach (array_chunk($remove, 500) as $chunk) {
                $detached += $this->connection->table($this->pivotTable)
                    ->filter($this->foreignPivotKey, $parentId)
                    ->filterIn($this->relatedPivotKey, $chunk)->delete();
            }
            $attached = 0;
            foreach ($add as $id) {
                $attached += $this->connection->table($this->pivotTable)->insert([
                    $this->foreignPivotKey => $parentId,
                    $this->relatedPivotKey => $id,
                ]);
            }
            return ['attached' => $attached, 'detached' => $detached];
        });
        if ($result['attached'] > 0 || $result['detached'] > 0) {
            $this->parent->clearRelations();
        }
        return $result;
    }

    /** @param list<Model> $parents @return array<int, list<Model>> */
    private function resolve(array $parents): array
    {
        $keys = [];
        $parentsByKey = [];
        foreach ($parents as $parent) {
            if ($parent->relationConnection() !== $this->connection) {
                throw new RelationException('Eager-loaded many-to-many parents must share one connection.');
            }
            if (!$parent->exists()) {
                continue;
            }
            $value = $this->parentValue($parent, $this->parentKey);
            if ($value !== null) {
                $fingerprint = self::fingerprint($value);
                $keys[$fingerprint] = $value;
                $parentsByKey[$fingerprint][] = $parent;
            }
        }
        if ($keys === []) {
            return [];
        }

        $edges = [];
        $relatedKeys = [];
        $targets = [];
        foreach (array_chunk(array_values($keys), 500) as $chunk) {
            $query = $this->pivotQuery()->filterIn($this->foreignPivotKey, $chunk);
            foreach ($query->all() as $row) {
                $parentId = self::keyValue($row[$this->foreignPivotKey]);
                $relatedId = self::keyValue($row[$this->relatedPivotKey]);
                $parentFingerprint = self::fingerprint($parentId);
                $relatedFingerprint = self::fingerprint($relatedId);
                if (isset($edges[$parentFingerprint][$relatedFingerprint])) {
                    throw new RelationException('The pivot contains duplicate parent/related edges; add a unique composite constraint.');
                }
                $edges[$parentFingerprint][$relatedFingerprint] = true;
                $relatedKeys[$relatedFingerprint] = $relatedId;
                foreach ($parentsByKey[$parentFingerprint] ?? [] as $parent) {
                    $targets[$relatedFingerprint][] = [spl_object_id($parent), $row];
                }
            }
        }
        $resolved = [];
        if ($relatedKeys === []) {
            return $resolved;
        }
        // Each edge gets its own Model clone so shared related IDs cannot overwrite pivot metadata.
        foreach ($this->batched($this->relatedKey, array_values($relatedKeys)) as $related) {
            $id = $this->parentValue($related, $this->relatedKey);
            if ($id === null) {
                throw new RelationException('The related query omitted the key required for pivot mapping.');
            }
            $fingerprint = self::fingerprint($id);
            foreach ($targets[$fingerprint] ?? [] as [$parentObjectId, $pivot]) {
                $resolved[$parentObjectId][] = (clone $related)->setPivot($pivot);
            }
        }
        return $resolved;
    }

    private function pivotQuery(): QueryBuilder
    {
        $columns = array_values(array_unique(array_merge(
            [$this->foreignPivotKey, $this->relatedPivotKey], $this->pivotColumns
        )));
        $query = $this->connection->table($this->pivotTable)->select($columns);
        foreach ($this->pivotFilters as [$column, $value]) {
            if ($value === null) {
                $query->filterNull($column);
            } else {
                $query->filter($column, $value);
            }
        }
        return $query;
    }

    private function writeParentKey(): int|string
    {
        if (!$this->parent->exists() || $this->parent->changed($this->parentKey)) {
            throw new RelationException('Pivot writes require a persisted parent with an unchanged relation key.');
        }
        $value = $this->parent->original($this->parentKey);
        if (!is_int($value) && !is_string($value)) {
            throw new RelationException('Pivot writes require a loaded, persisted parent relation key.');
        }
        return $value;
    }

    /** @param int|string|Model|list<int|string|Model> $input @return array<string, int|string> */
    private function ids(int|string|Model|array $input): array
    {
        if (is_array($input) && !array_is_list($input)) {
            throw new RelationException('Pivot IDs must be a list; sync pivot-data maps are not supported.');
        }
        $values = is_array($input) ? $input : [$input];
        $ids = [];
        foreach ($values as $value) {
            if ($value instanceof Model) {
                if (!$value instanceof $this->relatedClass || !$value->exists()
                    || $value->changed($this->relatedKey)) {
                    throw new RelationException('A related model must be persisted with an unchanged relation key.');
                }
                if ($value->relationConnection() !== $this->connection) {
                    throw new RelationException('A related model argument must use the many-to-many connection.');
                }
                $value = $value->original($this->relatedKey);
            }
            if (!is_int($value) && !is_string($value)) {
                throw new RelationException('A related identity must be an integer or string.');
            }
            $ids[self::fingerprint($value)] = $value;
        }
        return $ids;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function writePivotData(array $data): array
    {
        foreach ($data as $column => $value) {
            if (!is_string($column)) {
                throw new RelationException('Pivot data keys must be column names.');
            }
            self::column($column);
            if (strcasecmp($column, $this->foreignPivotKey) === 0
                || strcasecmp($column, $this->relatedPivotKey) === 0) {
                throw new RelationException('Pivot data cannot override relationship keys.');
            }
            if ($value !== null && !is_scalar($value)) {
                throw new RelationException('Pivot values must be scalar or null.');
            }
        }
        return $data;
    }

    /** @param array<string, int|string>|null $only @return array<string, int|string> */
    private function currentIds(int|string $parentId, ?array $only = null): array
    {
        $current = [];
        $chunks = $only === null ? [null] : array_chunk(array_values($only), 500);
        foreach ($chunks as $chunk) {
            $query = $this->connection->table($this->pivotTable)
                ->select([$this->relatedPivotKey])->filter($this->foreignPivotKey, $parentId);
            if ($chunk !== null) {
                $query->filterIn($this->relatedPivotKey, $chunk);
            }
            foreach ($query->all() as $row) {
                $id = self::keyValue($row[$this->relatedPivotKey]);
                $fingerprint = self::fingerprint($id);
                if (isset($current[$fingerprint])) {
                    throw new RelationException('The pivot contains duplicate parent/related edges; add a unique composite constraint.');
                }
                $current[$fingerprint] = $id;
            }
        }
        return $current;
    }

    private function transactional(callable $operation): mixed
    {
        $pdo = $this->connection->pdo();
        if ($pdo->inTransaction()) {
            return $operation();
        }
        $this->connection->begin();
        try {
            $result = $operation();
            $this->connection->commit();
            return $result;
        } catch (Throwable $original) {
            if ($this->connection->inTransaction()) {
                try {
                    $this->connection->rollback();
                } catch (Throwable) {
                    // The failed operation remains the primary error.
                }
            }
            throw $original;
        }
    }
}
