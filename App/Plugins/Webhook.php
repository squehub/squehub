<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Webhooks\Webhook as Gateway;
use App\Webhooks\WebhookEndpoint;
use App\Webhooks\WebhookManager;
use App\Webhooks\WebhookSource;
use App\Webhooks\WebhookEvent;

/** Application-facing gateway for named outgoing and incoming SqueHub peers. */
final class Webhook
{
    public static function manager(): WebhookManager { return Gateway::manager(); }
    public static function endpoint(string $name): WebhookEndpoint { return Gateway::endpoint($name); }
    public static function source(string $name): WebhookSource { return Gateway::source($name); }
    /** @param array<string|int,mixed> $data */
    public static function event(string $type, array $data): WebhookEvent
    {
        return Gateway::event($type, $data);
    }
}
