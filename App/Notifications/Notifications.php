<?php

declare(strict_types=1);

namespace App\Notifications;

use Closure;

/** Resolves the current Application's NotificationManager for the helper. */
final class Notifications
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): NotificationManager
    {
        if (self::$resolver === null) {
            throw NotificationException::framework('Notifications are unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }
}
