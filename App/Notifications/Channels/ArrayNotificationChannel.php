<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Notifications\Notification;
use App\Notifications\NotificationChannel;

/**
 * Process-local dispatch record. Retaining class names only avoids keeping
 * account tokens, recipient objects, or arbitrary notification payloads alive.
 */
final class ArrayNotificationChannel implements NotificationChannel
{
    /** @var list<class-string<Notification>> */
    private array $records = [];

    public function send(mixed $notifiable, Notification $notification): void
    {
        $this->records[] = $notification::class;
    }

    /** @return list<class-string<Notification>> */
    public function notifications(): array { return $this->records; }

    public function clear(): void { $this->records = []; }
}
