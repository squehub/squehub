<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Model;
use App\Database\ModelQuery;
use App\Database\QueryBuilder;

/**
 * Resolves a stored type only through the Application's morph map. Eager
 * loading groups rows by alias and key, never querying once per parent.
 */
final class MorphTo extends Relation
{
    private const MAX_EXISTENCE_TYPES = 32;

    private string $typeColumn;
    private string $idColumn;
    private ?string $ownerKey;

    public function __construct(Model $parent, string $name, ?string $typeColumn = null,
        ?string $idColumn = null, ?string $ownerKey = null)
    {
        parent::__construct($parent);
        $name = self::column($name);
        $this->typeColumn = self::column($typeColumn ?? $name . '_type');
        $this->idColumn = self::column($idColumn ?? $name . '_id');
        $this->ownerKey = $ownerKey === null ? null : self::column($ownerKey);
    }

    /** @return list<class-string<Model>> */
    public function relatedClasses(): array
    {
        return $this->manager()->morphMap()->classes();
    }

    /**
     * A heterogeneous relation has no single ModelQuery for a generic
     * callback. Unconstrained existence checks only the finite, trusted map.
     */
    public function existenceQuery(string $parentTable, ?callable $constraint = null): QueryBuilder
    {
        if ($constraint !== null) {
            throw new RelationException('A MorphTo constraint requires an explicit callback for each selected alias.');
        }
        $map = $this->manager()->morphMap();
        $aliases = [];
        foreach ($map->classes() as $class) {
            $aliases[$map->aliasFor($class)] = null;
        }
        return $this->buildExistenceQuery($parentTable, $aliases, true);
    }

    /**
     * @param array<array-key, mixed> $constraints Untrusted input is validated before SQL is assembled.
     * @internal The query API accepts only explicitly selected MorphMap aliases.
     */
    public function existenceQueryForAliases(string $parentTable, array $constraints): QueryBuilder
    {
        return $this->buildExistenceQuery($parentTable, $constraints, false);
    }

    /**
     * A polymorphic target is a different SQL branch for each registered
     * alias. There is no single target-row alias for a deeper EXISTS hop,
     * even when this Application currently registers only one type.
     */
    public function existenceRelatedAlias(string $parentTable): string
    {
        throw new RelationException('Nested relation existence cannot traverse beyond MorphTo.');
    }

    /** @param array<array-key, mixed> $constraints */
    private function buildExistenceQuery(string $parentTable, array $constraints, bool $allowEmpty): QueryBuilder
    {
        if ((!$allowEmpty && $constraints === []) || count($constraints) > self::MAX_EXISTENCE_TYPES) {
            throw new RelationException('MorphTo existence requires one to 32 registered aliases.');
        }

        $connection = $this->parent->relationConnection();
        $parentAlias = self::existenceAlias($parentTable . ':' . $this->typeColumn, 'morph_parent');
        $parent = $connection->table($this->parent->tableName())->forRelationAlias($parentAlias);
        $parent->requireColumn($parentAlias . '.' . $this->parent->primaryKeyName(), '=',
            $parentTable . '.' . $this->parent->primaryKeyName());

        if ($constraints === []) {
            // An empty registry cannot resolve any stored discriminator.
            return $parent->filterIn($parentAlias . '.' . $this->parent->primaryKeyName(), []);
        }

        $alternatives = [];
        $map = $this->manager()->morphMap();
        foreach ($constraints as $alias => $constraint) {
            if (!is_string($alias) || ($constraint !== null && !is_callable($constraint))) {
                throw new RelationException('MorphTo existence aliases require a callable constraint or null.');
            }
            $class = $map->classFor($alias);
            $related = $class::queryOn($this->manager());
            if ($related->connection() !== $connection) {
                throw new RelationException('MorphTo existence requires the same database connection for every target.');
            }
            $related = $related->asRelationConstraint();
            if ($constraint !== null) {
                $result = $constraint($related);
                if ($result !== null && $result !== $related) {
                    throw new RelationException('A MorphTo constraint must return the active ModelQuery or nothing.');
                }
            }
            // Type aliases are bound values; only framework-generated names
            // and Model metadata become SQL identifiers.
            $targetAlias = self::existenceAlias($parentAlias . ':' . $alias, 'morph_target');
            $ownerKey = $this->ownerKey ?? (new $class())->primaryKeyName();
            $target = $related->relationSubquery()->forRelationAlias($targetAlias)
                ->requireColumn($targetAlias . '.' . $ownerKey, '=', $parentAlias . '.' . $this->idColumn);
            $alternatives[] = [$alias, $target];
        }

        $typeColumn = $this->typeColumn;
        $parent->filterGroup(static function (QueryBuilder $group) use ($alternatives, $parentAlias, $typeColumn): void {
            foreach ($alternatives as [$alias, $target]) {
                $group->orFilterGroup(static function (QueryBuilder $branch) use (
                    $alias, $target, $parentAlias, $typeColumn
                ): void {
                    $branch->filter($parentAlias . '.' . $typeColumn, $alias)
                        ->filterExists($target);
                });
            }
        });

        return $parent;
    }

    public function get(): ?Model
    {
        return $this->first();
    }

    public function first(): ?Model
    {
        $class = $this->targetClass($this->parent);
        $key = $this->parentValue($this->parent, $this->idColumn);
        if ($class === null || $key === null) {
            return null;
        }
        $ownerKey = $this->ownerKey ?? (new $class())->primaryKeyName();
        return $class::queryOn($this->manager())->filter($ownerKey, $key)->first();
    }

    /** @param list<Model> $parents */
    public function eagerLoad(array $parents, string $name): void
    {
        /** @var array<string, array{class: class-string<Model>, keys: array<string, int|string>, parents: list<Model>}> $groups */
        $groups = [];
        /** @var array<int, Model|null> $resolved */
        $resolved = [];
        foreach ($parents as $parent) {
            $class = $this->targetClass($parent);
            $key = $this->parentValue($parent, $this->idColumn);
            $resolved[spl_object_id($parent)] = null;
            if ($class === null || $key === null) {
                continue;
            }
            $groups[$class] ??= ['class' => $class, 'keys' => [], 'parents' => []];
            $groups[$class]['keys'][self::fingerprint($key)] = $key;
            $groups[$class]['parents'][] = $parent;
        }

        foreach ($groups as $group) {
            $class = $group['class'];
            $ownerKey = $this->ownerKey ?? (new $class())->primaryKeyName();
            $targets = [];
            foreach (array_chunk(array_values($group['keys']), 500) as $chunk) {
                foreach ($class::queryOn($this->manager())->filterIn($ownerKey, $chunk)->all() as $model) {
                    $value = $this->parentValue($model, $ownerKey);
                    if ($value !== null) {
                        $targets[self::fingerprint($value)] ??= $model;
                    }
                }
            }
            foreach ($group['parents'] as $parent) {
                $key = $this->parentValue($parent, $this->idColumn);
                if ($key !== null) {
                    $resolved[spl_object_id($parent)] = $targets[self::fingerprint($key)] ?? null;
                }
            }
        }
        // Publish cache values only after every type query has succeeded.
        foreach ($parents as $parent) {
            $parent->setRelation($name, $resolved[spl_object_id($parent)]);
        }
    }

    /** @return class-string<Model>|null */
    private function targetClass(Model $parent): ?string
    {
        $type = $parent->attributes()[$this->typeColumn] ?? null;
        if ($type === null) {
            return null;
        }
        if (!is_string($type)) {
            throw new RelationException('A stored polymorphic type must be an alias string.');
        }
        return $this->manager()->morphMap()->classFor($type);
    }
}
