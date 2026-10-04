<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Mail\MailAddress;
use App\Mail\MailConfigurationException;
use App\Mail\Mailer;
use App\Mail\MailMessage;
use App\Notifications\Notification;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationException;

/** Resolves an explicit recipient route and delegates all MIME/SMTP work to Mail. */
final class MailNotificationChannel implements NotificationChannel
{
    public function __construct(private Mailer $mailer)
    {
    }

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (!is_object($notifiable) || !is_callable([$notifiable, 'routeNotificationForMail'])) {
            throw NotificationException::framework('Mail notification route is unavailable.');
        }
        $route = $notifiable->routeNotificationForMail();
        if ($route === null) throw NotificationException::framework('Mail notification route is unavailable.');
        if (is_string($route)) {
            try { $route = new MailAddress($route); }
            catch (MailConfigurationException $failure) {
                throw NotificationException::framework('Mail notification route is invalid.', $failure);
            }
        }
        if (!$route instanceof MailAddress) {
            throw NotificationException::framework('Mail notification route is invalid.');
        }
        if (!is_callable([$notification, 'toMail'])) {
            throw NotificationException::framework('Mail notification representation is unavailable.');
        }
        $message = $notification->toMail($notifiable);
        if (!$message instanceof MailMessage) {
            throw NotificationException::framework('Mail notification must return a MailMessage.');
        }
        // Recipient routing is authoritative. A content representation must
        // not add To/CC/BCC targets that could receive a private token.
        if ($message->recipients() !== [] || $message->carbonCopies() !== [] || $message->blindCopies() !== []) {
            throw NotificationException::framework('Mail notification must not pre-address recipients.');
        }
        $prepared = $message->snapshot();
        $prepared->to($route->address(), $route->name());
        $this->mailer->send($prepared);
    }
}
