<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Explicit recipient identity for deferred delivery. The worker reconstructs
 * the recipient so its current notification route is used at delivery time.
 */
interface QueueNotifiable
{
    /** @return array<string|int, mixed> */
    public function notificationQueueIdentity(): array;

    /** @param array<string|int, mixed> $identity */
    public static function resolveNotificationQueueIdentity(array $identity): static;
}
