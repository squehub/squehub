<?php

declare(strict_types=1);

namespace App\Webhooks;

use Closure;

/** Application gateway; mutable delivery and receipt state stays in its manager. */
final class Webhook
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): WebhookManager
    {
        if (self::$resolver === null) {
            throw new WebhookException('Webhooks are unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }

    public static function endpoint(string $name): WebhookEndpoint
    {
        return self::manager()->endpoint($name);
    }

    public static function source(string $name): WebhookSource
    {
        return self::manager()->source($name);
    }

    /** @param array<string|int,mixed> $data */
    public static function event(string $type, array $data): WebhookEvent
    {
        return self::manager()->event($type, $data);
    }
}
