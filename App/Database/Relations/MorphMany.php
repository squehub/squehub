<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Collections\ModelCollection;
use App\Database\Model;

/** Related rows constrained by a registered polymorphic alias and parent key. */
final class MorphMany extends MorphChildren
{
    public function get(): ModelCollection
    {
        return $this->children();
    }

    public function first(): ?Model
    {
        return $this->firstChild();
    }

    /** @param list<Model> $parents */
    public function eagerLoad(array $parents, string $name): void
    {
        $this->eagerChildrenFor($parents, $name, false);
    }
}
