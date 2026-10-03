<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Collections\ModelCollection;
use App\Database\Connection;
use App\Database\Model;
use App\Database\ModelQuery;
use App\Database\QueryBuilder;
use ReflectionClass;

/**
 * Read final Models through a second Model without a cross-table write API.
 * Intermediate and final reads retain their own Model soft-delete policies.
 */
abstract class ThroughRelation extends Relation
{
    private Connection $connection;
    private ModelQuery $throughQuery;
    private string $firstForeignKey;
    private string $secondForeignKey;
    private string $localKey;
    private string $throughKey;

    /**
     * @param class-string<Model> $relatedClass Final Model returned by this relation.
     * @param class-string<Model> $throughClass Intermediate Model whose FK points to the parent.
     */
    public function __construct(
        Model $parent,
        string $relatedClass,
        string $throughClass,
        ?string $firstForeignKey = null,
        ?string $secondForeignKey = null,
        ?string $localKey = null,
        ?string $throughKey = null
    ) {
        parent::__construct($parent, $relatedClass);
        if (!is_a($throughClass, Model::class, true)
            || !(new ReflectionClass($throughClass))->isInstantiable()) {
            throw new RelationException('A through class must be a concrete App\\Database\\Model.');
        }
        $through = new $throughClass();
        $this->firstForeignKey = self::column($firstForeignKey ?? self::foreignKey($parent));
        $this->secondForeignKey = self::column($secondForeignKey ?? self::foreignKey($through));
        $this->localKey = self::column($localKey ?? $parent->primaryKeyName());
        $this->throughKey = self::column($throughKey ?? $through->primaryKeyName());
        $this->connection = $parent->relationConnection();
        $this->throughQuery = $throughClass::queryOn($this->manager());
        if ($this->connection !== $this->throughQuery->connection()
            || $this->connection !== $this->query->connection()) {
            throw new RelationException('A through relation requires parent, intermediate, and final Models on one connection.');
        }
    }

    /**
     * The final table is the outer subquery so COUNT(*) counts final records.
     * A nested intermediate EXISTS checks the path and its soft-delete policy
     * without multiplying rows when intermediate keys are repeated.
     */
    public function existenceQuery(string $parentTable, ?callable $constraint = null): QueryBuilder
    {
        $finalAlias = $this->existenceRelatedAlias($parentTable);
        $throughAlias = self::existenceAlias($parentTable, 'through_middle');
        $through = $this->throughQuery->relationSubquery()->forRelationAlias($throughAlias)
            ->requireColumn($throughAlias . '.' . $this->firstForeignKey,
                '=', $parentTable . '.' . $this->localKey)
            ->requireColumn($throughAlias . '.' . $this->throughKey,
                '=', $finalAlias . '.' . $this->secondForeignKey);

        return $this->relatedExistenceQuery($parentTable, $constraint, $finalAlias)
            ->requireExists($through);
    }

    /** Nested existence paths continue from the final row, not the intermediate. */
    public function existenceRelatedAlias(string $parentTable): string
    {
        return self::existenceAlias($parentTable, 'through_final');
    }

    /**
     * Resolve a parent batch with at most one intermediate and one final read
     * per 500 distinct keys. Each final record appears once per parent even
     * if duplicate intermediate rows share the same lookup key.
     *
     * @param list<Model> $parents
     * @return array<int, list<Model>> Indexed by parent object identity.
     */
    protected function resolve(array $parents): array
    {
        /** @var array<string, int|string> $parentKeys */
        $parentKeys = [];
        /** @var array<string, list<int>> $parentIdsByKey */
        $parentIdsByKey = [];
        /** @var array<int, list<Model>> $resolved */
        $resolved = [];
        foreach ($parents as $parent) {
            if ($parent->relationConnection() !== $this->connection) {
                throw new RelationException('Through eager loading cannot mix parent connections.');
            }
            $id = spl_object_id($parent);
            $resolved[$id] = [];
            $value = $this->parentValue($parent, $this->localKey);
            if ($value === null) {
                continue;
            }
            $fingerprint = self::fingerprint($value);
            $parentKeys[$fingerprint] = $value;
            $parentIdsByKey[$fingerprint][] = $id;
        }
        if ($parentKeys === []) {
            return $resolved;
        }

        /** @var array<string, int|string> $throughKeys */
        $throughKeys = [];
        /** @var array<string, array<int, true>> $parentIdsByThroughKey */
        $parentIdsByThroughKey = [];
        foreach (array_chunk(array_values($parentKeys), 500) as $keys) {
            $query = clone $this->throughQuery;
            foreach ($query->filterIn($this->firstForeignKey, $keys)->all() as $through) {
                $foreign = $this->parentValue($through, $this->firstForeignKey);
                $lookup = $this->parentValue($through, $this->throughKey);
                if ($foreign === null || $lookup === null) {
                    continue;
                }
                $throughFingerprint = self::fingerprint($lookup);
                $throughKeys[$throughFingerprint] = $lookup;
                foreach ($parentIdsByKey[self::fingerprint($foreign)] ?? [] as $parentId) {
                    $parentIdsByThroughKey[$throughFingerprint][$parentId] = true;
                }
            }
        }
        foreach (array_chunk(array_values($throughKeys), 500) as $keys) {
            $query = clone $this->query;
            foreach ($query->filterIn($this->secondForeignKey, $keys)->all() as $related) {
                $foreign = $this->parentValue($related, $this->secondForeignKey);
                if ($foreign === null) {
                    continue;
                }
                foreach (array_keys($parentIdsByThroughKey[self::fingerprint($foreign)] ?? []) as $parentId) {
                    $resolved[$parentId][] = $related;
                }
            }
        }
        return $resolved;
    }

    /** @param list<Model> $parents */
    protected function eagerResolved(array $parents, string $name, bool $single): void
    {
        $resolved = $this->resolve($parents);
        foreach ($parents as $parent) {
            $matches = $resolved[spl_object_id($parent)] ?? [];
            $parent->setRelation($name, $single ? ($matches[0] ?? null) : new ModelCollection($matches));
        }
    }
}
