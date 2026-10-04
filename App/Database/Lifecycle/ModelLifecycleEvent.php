<?php

declare(strict_types=1);

namespace App\Database\Lifecycle;

use App\Database\Model;

/**
 * Describes one synchronous Model transition without exposing SQL or bindings.
 * An after event means its SQL statement succeeded, not that an outer caller
 * transaction has committed.
 */
final class ModelLifecycleEvent
{
    public function __construct(
        public readonly Model $model,
        public readonly string $phase,
        public readonly string $operation
    ) {
    }
}
