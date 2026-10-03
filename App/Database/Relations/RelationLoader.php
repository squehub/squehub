<?php

declare(strict_types=1);

namespace App\Database\Relations;

use App\Database\Collections\ModelCollection;
use App\Database\Model;

/** Batches each requested relation level and shares common dotted path prefixes. */
final class RelationLoader
{
    private const MAX_DEPTH = 8;

    /** @param string|list<string> $names @return list<string> */
    public static function names(string|array $names): array
    {
        $names = is_string($names) ? [$names] : array_values($names);
        $unique = [];
        foreach ($names as $name) {
            if (!is_string($name) || strlen($name) > 512
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/D', $name) !== 1
                || substr_count($name, '.') >= self::MAX_DEPTH) {
                throw new RelationException('Relationship paths must contain at most eight valid method names.');
            }
            $unique[$name] = true;
        }
        return array_keys($unique);
    }

    /** @param list<Model> $models @param list<string> $names @param class-string<Model>|null $modelClass */
    public static function load(array $models, array $names, ?string $modelClass = null): void
    {
        if ($names === []) {
            return;
        }
        /** @var array<string, array> $tree */
        $tree = [];
        foreach (self::names($names) as $path) {
            $branch = &$tree;
            foreach (explode('.', $path) as $segment) {
                $branch[$segment] ??= [];
                $branch = &$branch[$segment];
            }
            unset($branch);
        }
        self::loadTree($models, $tree, $modelClass);
    }

    /**
     * @param list<Model> $models
     * @param array<string, array> $tree
     * @param class-string<Model>|null $modelClass
     */
    private static function loadTree(array $models, array $tree, ?string $modelClass = null): void
    {
        if ($models === []) {
            if ($modelClass !== null) {
                $prototype = new $modelClass();
                foreach ($tree as $name => $descendants) {
                    $relation = $prototype->relation($name);
                    foreach ($relation->relatedClasses() as $relatedClass) {
                        self::loadTree([], $descendants, $relatedClass);
                    }
                }
            }
            return;
        }

        /** @var array<string, list<Model>> $groups */
        $groups = [];
        foreach ($models as $model) {
            // One related query may serve only parents from the same source
            // connection. Mixed collections can contain two Applications'
            // instances of the same Model class.
            $key = $model::class . ':' . spl_object_id($model->relationConnection());
            $groups[$key][] = $model;
        }
        foreach ($tree as $name => $descendants) {
            /** @var array<int, Model> $children */
            $children = [];
            /** @var array<class-string<Model>, true> $possibleClasses */
            $possibleClasses = [];
            foreach ($groups as $group) {
                $relation = $group[0]->relation($name);
                $relation->eagerLoad($group, $name);
                foreach ($relation->relatedClasses() as $relatedClass) {
                    $possibleClasses[$relatedClass] = true;
                }
                foreach ($group as $parent) {
                    $value = $parent->getRelation($name);
                    if ($value instanceof Model) {
                        $children[spl_object_id($value)] = $value;
                    } elseif ($value instanceof ModelCollection) {
                        foreach ($value as $child) {
                            $children[spl_object_id($child)] = $child;
                        }
                    } elseif ($value !== null) {
                        throw new RelationException('An eager relationship returned an unsupported value.');
                    }
                }
            }
            if ($descendants === []) {
                continue;
            }
            if ($children !== []) {
                self::loadTree(array_values($children), $descendants);
            } else {
                // Keep unknown nested relationship errors deterministic even
                // when a valid parent query has no related rows to traverse.
                foreach (array_keys($possibleClasses) as $relatedClass) {
                    self::loadTree([], $descendants, $relatedClass);
                }
            }
        }
    }
}
