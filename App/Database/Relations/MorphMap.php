<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Model;
use ReflectionClass;

/**
 * Application-owned aliases for polymorphic rows. Database values never name
 * PHP classes directly; only registered aliases may resolve a target model.
 */
final class MorphMap
{
    /** @var array<string, class-string<Model>> */
    private array $types = [];
    /** @var array<string, string> */
    private array $aliases = [];

    /** @param class-string<Model> $modelClass */
    public function define(string $alias, string $modelClass): self
    {
        if (!self::validAlias($alias)) {
            throw new RelationException('A polymorphic alias must be a bounded lowercase identifier.');
        }
        if (!is_a($modelClass, Model::class, true)) {
            throw new RelationException('A polymorphic target must be a modern Model.');
        }
        $reflection = new ReflectionClass($modelClass);
        if (!$reflection->isInstantiable()) {
            throw new RelationException('A polymorphic target must be a concrete Model.');
        }
        /** @var class-string<Model> $canonical */
        $canonical = $reflection->getName();
        $classKey = strtolower($canonical);
        if (isset($this->types[$alias]) || isset($this->aliases[$classKey])) {
            throw new RelationException('A polymorphic alias or Model is already registered.');
        }
        $this->types[$alias] = $canonical;
        $this->aliases[$classKey] = $alias;
        return $this;
    }

    /** @return class-string<Model> */
    public function classFor(string $alias): string
    {
        if (!self::validAlias($alias) || !isset($this->types[$alias])) {
            throw new RelationException('A stored polymorphic type is not registered.');
        }
        return $this->types[$alias];
    }

    /** @param class-string<Model> $modelClass */
    public function aliasFor(string $modelClass): string
    {
        $alias = $this->aliases[strtolower($modelClass)] ?? null;
        if ($alias === null) {
            throw new RelationException('The polymorphic Model type is not registered.');
        }
        return $alias;
    }

    /** @return list<class-string<Model>> */
    public function classes(): array
    {
        return array_values($this->types);
    }

    private static function validAlias(string $alias): bool
    {
        return strlen($alias) <= 128
            && preg_match('/\A[a-z][a-z0-9._-]*\z/D', $alias) === 1;
    }
}
