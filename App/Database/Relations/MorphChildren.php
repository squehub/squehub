<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Collections\ModelCollection;
use App\Database\Model;
use App\Database\QueryBuilder;

/** Shared alias constraint and key mapping for morphOne and morphMany. */
abstract class MorphChildren extends Relation
{
    private string $idColumn;
    private string $localKey;
    private string $typeColumn;
    private string $typeAlias;

    /** @param class-string<Model> $relatedClass */
    public function __construct(Model $parent, string $relatedClass, string $name,
        ?string $typeColumn = null, ?string $idColumn = null, ?string $localKey = null)
    {
        parent::__construct($parent, $relatedClass);
        $name = self::column($name);
        $this->typeColumn = self::column($typeColumn ?? $name . '_type');
        $this->idColumn = self::column($idColumn ?? $name . '_id');
        $this->localKey = self::column($localKey ?? $parent->primaryKeyName());

        // The application registry, never a PHP class name from a row, owns
        // the stored discriminator used by both lazy and eager child queries.
        $this->typeAlias = $this->manager()->morphMap()->aliasFor($parent::class);
        $this->query->filter($this->typeColumn, $this->typeAlias);
    }

    public function existenceQuery(string $parentTable, ?callable $constraint = null): QueryBuilder
    {
        $alias = self::existenceAlias($parentTable);
        $related = $this->relatedExistenceQuery($parentTable, $constraint, $alias);
        // The type predicate must remain mandatory even when a constraint
        // callback adds OR branches to its related Model query.
        return $related->requireValue($alias . '.' . $this->typeColumn, $this->typeAlias)
            ->requireColumn($alias . '.' . $this->idColumn, '=', $parentTable . '.' . $this->localKey);
    }

    protected function firstChild(): ?Model
    {
        $key = $this->parentValue($this->parent, $this->localKey);
        return $key === null ? null : (clone $this->query)->filter($this->idColumn, $key)->first();
    }

    protected function children(): ModelCollection
    {
        $key = $this->parentValue($this->parent, $this->localKey);
        return $key === null ? new ModelCollection() : (clone $this->query)->filter($this->idColumn, $key)->all();
    }

    /** @param list<Model> $parents */
    protected function eagerChildrenFor(array $parents, string $name, bool $single): void
    {
        $this->eagerChildren($parents, $name, $this->localKey, $this->idColumn, $single);
    }
}
