<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\MailAddress;

/** One explicit mail route bound to its originating Application manager. */
final readonly class AnonymousNotifiable
{
    public function __construct(private NotificationManager $manager, private MailAddress $mailRoute)
    {
    }

    public function routeNotificationForMail(): MailAddress { return $this->mailRoute; }

    public function send(Notification $notification): void { $this->manager->send($this, $notification); }

    /** Ordinary debugger views should not publish the destination address. */
    public function __debugInfo(): array { return ['route' => '[REDACTED]']; }
}
