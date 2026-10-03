<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Model;
use App\Database\Collections\ModelCollection;
use App\Database\QueryBuilder;

/** Related rows selected by a foreign key on their table. */
final class HasMany extends Relation
{
    private string $foreignKey;
    private string $localKey;

    /** @param class-string<Model> $relatedClass */
    public function __construct(Model $parent, string $relatedClass, ?string $foreignKey = null, ?string $localKey = null)
    {
        parent::__construct($parent, $relatedClass);
        $this->foreignKey = self::column($foreignKey ?? self::foreignKey($parent));
        $this->localKey = self::column($localKey ?? $parent->primaryKeyName());
    }

    public function get(): ModelCollection
    {
        $key = $this->parentValue($this->parent, $this->localKey);
        return $key === null ? new ModelCollection() : (clone $this->query)->filter($this->foreignKey, $key)->all();
    }

    public function first(): ?Model
    {
        $key = $this->parentValue($this->parent, $this->localKey);
        return $key === null ? null : (clone $this->query)->filter($this->foreignKey, $key)->first();
    }

    public function existenceQuery(string $parentTable, ?callable $constraint = null): QueryBuilder
    {
        $alias = self::existenceAlias($parentTable);
        return $this->relatedExistenceQuery($parentTable, $constraint, $alias)
            ->requireColumn($alias . '.' . $this->foreignKey, '=', $parentTable . '.' . $this->localKey);
    }

    /** @param list<Model> $parents */
    public function eagerLoad(array $parents, string $name): void
    {
        $this->eagerChildren($parents, $name, $this->localKey, $this->foreignKey, false);
    }
}
