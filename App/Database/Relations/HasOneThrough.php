<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Model;

/** A singular, read-only final Model reached through an intermediate Model. */
final class HasOneThrough extends ThroughRelation
{
    public function get(): ?Model
    {
        return $this->first();
    }

    public function first(): ?Model
    {
        return $this->resolve([$this->parent])[spl_object_id($this->parent)][0] ?? null;
    }

    /** @param list<Model> $parents */
    public function eagerLoad(array $parents, string $name): void
    {
        $this->eagerResolved($parents, $name, true);
    }
}
