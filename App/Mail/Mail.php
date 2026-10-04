<?php

declare(strict_types=1);

namespace App\Mail;

use Closure;

/** Resolves the current Application's Mailer; no independent global instance. */
final class Mail
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): Mailer
    {
        if (self::$resolver === null) throw new MailConfigurationException('Mailer is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }

    public static function queue(MailMessage $message, ?string $connection = null,
        string $queue = 'default', int $delay = 0, ?string $via = null): void
    {
        self::manager()->queue($message, $connection, $queue, $delay, $via);
    }
}
