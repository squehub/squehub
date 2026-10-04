<?php

declare(strict_types=1);

namespace App\Notifications;

/** Performs one synchronous channel delivery for a prepared notification. */
interface NotificationChannel
{
    public function send(mixed $notifiable, Notification $notification): void;
}
