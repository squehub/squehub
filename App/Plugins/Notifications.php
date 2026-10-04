<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Mail\MailAddress;
use App\Notifications\AnonymousNotifiable;
use App\Notifications\Notification as BaseNotification;
use App\Notifications\NotificationManager;
use App\Notifications\Notifications as NotificationGateway;

/** Delivery gateway alongside the extendable App\Plugins\Notification base. */
final class Notifications
{
    public static function manager(): NotificationManager { return NotificationGateway::manager(); }
    public static function send(mixed $notifiable, BaseNotification $notification): void
    {
        self::manager()->send($notifiable, $notification);
    }
    public static function route(string $channel, MailAddress|string $address): AnonymousNotifiable
    {
        return self::manager()->route($channel, $address);
    }
}
