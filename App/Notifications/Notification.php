<?php

declare(strict_types=1);

namespace App\Notifications;

/** Application-defined delivery intent. Channels own representation and transport. */
abstract class Notification
{
    /** @return list<string> Ordered channel names; an empty list is a valid no-op. */
    abstract public function via(mixed $notifiable): array;

    /** Optional Queue choices. The configured Queue connection remains the default. */
    public function queueConnection(): ?string { return null; }
    public function queueName(): string { return 'default'; }
    public function queueDelay(): int { return 0; }
    /** Opt in to dispatch only after the chosen database transaction commits. */
    public function queueAfterCommit(): bool { return false; }
    public function queueTransactionConnection(): ?string { return null; }
}
