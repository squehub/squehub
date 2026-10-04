<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Opt-in convenience for Models and plain objects. It adds no persistence,
 * attributes, relation state, or implicit recipient address convention.
 */
trait Notifiable
{
    public function notify(Notification $notification): void
    {
        \notifications()->send($this, $notification);
    }
}
