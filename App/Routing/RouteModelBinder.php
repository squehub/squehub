<?php

declare(strict_types=1);

namespace App\Routing;

use App\Database\DatabaseManager;
use App\Database\Model;
use App\Http\Exception\NotFoundHttpException;
use LogicException;

/** Resolves only declared bindings for the current matched controller call. */
final class RouteModelBinder
{
    public function __construct(private DatabaseManager $database)
    {
    }

    /**
     * Preserve the raw Request route values for middleware and diagnostics.
     * Each binding uses the Model's own connection and default query policy.
     *
     * @param array<string, string> $parameters
     * @return array<string, string|Model>
     */
    public function resolve(RouteDefinition $route, array $parameters): array
    {
        $resolved = $parameters;
        foreach ($route->modelBindings() as $parameter => $binding) {
            $value = $parameters[$parameter] ?? null;
            if (!is_string($value)) {
                throw new LogicException('A declared Model binding has no matched route value.');
            }
            $modelClass = $binding['model'];
            $query = $modelClass::queryOn($this->database);
            $model = $binding['key'] === null
                ? $query->find($value)
                : $query->filter($binding['key'], $value)->first();
            if ($model === null) {
                // A missing or soft-deleted row has the same public 404 shape.
                throw new NotFoundHttpException();
            }
            if (!$model instanceof $modelClass) {
                throw new LogicException('A route binding returned an unexpected Model class.');
            }
            $resolved[$parameter] = $model;
        }
        return $resolved;
    }
}
