<?php

declare(strict_types=1);

namespace App\Health;

use Closure;
use LogicException;

/** Canonical gateway for the Application-owned health service. */
final class Health
{
    private static ?Closure $resolver = null;
    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }
    public static function manager(): HealthManager
    {
        if (self::$resolver === null) throw new LogicException('Health is unavailable before Application bootstrap.');
        $manager = (self::$resolver)();
        if (!$manager instanceof HealthManager) throw new LogicException('Health resolver is invalid.');
        return $manager;
    }
    public static function live(): HealthReport { return self::manager()->live(); }
    public static function ready(): HealthReport { return self::manager()->ready(); }
    public static function doctor(): HealthReport { return self::manager()->doctor(); }
    public static function infrastructure(): array { return self::manager()->infrastructure(); }
}
