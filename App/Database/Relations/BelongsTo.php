<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Model;
use App\Database\QueryBuilder;

/** A parent row selected by the current model's foreign key. */
final class BelongsTo extends Relation
{
    private string $foreignKey;
    private string $ownerKey;

    /** @param class-string<Model> $relatedClass */
    public function __construct(Model $parent, string $relatedClass, ?string $foreignKey = null, ?string $ownerKey = null)
    {
        parent::__construct($parent, $relatedClass);
        $owner = new $relatedClass();
        $this->foreignKey = self::column($foreignKey ?? self::foreignKey($owner));
        $this->ownerKey = self::column($ownerKey ?? $owner->primaryKeyName());
    }

    public function get(): ?Model
    {
        return $this->first();
    }

    public function first(): ?Model
    {
        $key = $this->parentValue($this->parent, $this->foreignKey);
        return $key === null ? null : (clone $this->query)->filter($this->ownerKey, $key)->first();
    }

    public function existenceQuery(string $parentTable, ?callable $constraint = null): QueryBuilder
    {
        $alias = self::existenceAlias($parentTable);
        return $this->relatedExistenceQuery($parentTable, $constraint, $alias)
            ->requireColumn($alias . '.' . $this->ownerKey, '=', $parentTable . '.' . $this->foreignKey);
    }

    /** @param list<Model> $parents */
    public function eagerLoad(array $parents, string $name): void
    {
        $keys = [];
        foreach ($parents as $parent) {
            $value = $this->parentValue($parent, $this->foreignKey);
            if ($value !== null) {
                $keys[self::fingerprint($value)] = $value;
            }
        }
        $relatedByKey = [];
        if ($keys !== []) {
            foreach ($this->batched($this->ownerKey, array_values($keys)) as $related) {
                $value = $this->parentValue($related, $this->ownerKey);
                if ($value !== null) {
                    $relatedByKey[self::fingerprint($value)] ??= $related;
                }
            }
        }
        foreach ($parents as $parent) {
            $value = $this->parentValue($parent, $this->foreignKey);
            $parent->setRelation($name, $value === null ? null : ($relatedByKey[self::fingerprint($value)] ?? null));
        }
    }
}
