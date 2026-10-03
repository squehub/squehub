<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Model;

/** One related row constrained by a registered polymorphic alias and parent key. */
final class MorphOne extends MorphChildren
{
    public function get(): ?Model
    {
        return $this->first();
    }

    public function first(): ?Model
    {
        return $this->firstChild();
    }

    /** @param list<Model> $parents */
    public function eagerLoad(array $parents, string $name): void
    {
        $this->eagerChildrenFor($parents, $name, true);
    }
}
