<?php

declare(strict_types=1);

namespace App\HttpClient;

use Closure;

/** Canonical gateway resolves only the currently bootstrapped Application. */
final class Http
{
    private static ?Closure $resolver = null;
    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }
    public static function client(): HttpClient
    {
        if (self::$resolver === null) throw new HttpConfigurationException('HTTP Client is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }
}
