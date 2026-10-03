<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Collections\ModelCollection;
use App\Database\Model;

/** A collection of final Models reached through intermediate Model rows. */
final class HasManyThrough extends ThroughRelation
{
    public function get(): ModelCollection
    {
        return new ModelCollection($this->resolve([$this->parent])[spl_object_id($this->parent)] ?? []);
    }

    public function first(): ?Model
    {
        return $this->get()->first();
    }

    /** @param list<Model> $parents */
    public function eagerLoad(array $parents, string $name): void
    {
        $this->eagerResolved($parents, $name, false);
    }
}
