<?php

declare(strict_types=1);

namespace App\Idempotency;

use Closure;

/** Resolves the manager selected for the current Application request. */
final class Idempotency
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): IdempotencyManager
    {
        if (self::$resolver === null) {
            throw new IdempotencyException('Idempotency is unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }
}
