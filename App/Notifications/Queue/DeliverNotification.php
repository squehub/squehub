<?php

declare(strict_types=1);

namespace App\Notifications\Queue;

use App\Container\Container;
use App\Mail\MailAddress;
use App\Notifications\AnonymousNotifiable;
use App\Notifications\Notification;
use App\Notifications\NotificationException;
use App\Notifications\NotificationManager;
use App\Notifications\Notifications;
use App\Notifications\QueueNotifiable;
use App\Notifications\ShouldQueue;
use App\Queue\QueueCodec;
use App\Queue\QueueJob;
use App\Queue\QueueContextAware;
use Throwable;

/**
 * One Queue job delivers all channels in their declared order. Retrying can
 * repeat channels already completed, as with any at-least-once Queue job.
 */
final class DeliverNotification implements QueueJob, QueueContextAware
{
    private ?Container $workerContainer = null;
    private bool $deliveryAttempted = false;
    /** @param array<string|int, mixed> $data
     *  @param array<string, mixed> $recipient
     */
    private function __construct(private string $notificationClass,
        private array $data, private array $recipient,
        private ?NotificationManager $runtimeManager = null,
        private int $version = 1)
    {
    }

    public static function capture(mixed $notifiable, Notification&ShouldQueue $notification,
        NotificationManager $manager): self
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            $route = $notifiable->routeNotificationForMail();
            $recipient = ['type' => 'anonymous', 'mail' => [
                'address' => $route->address(), 'name' => $route->name(),
            ]];
        } elseif ($notifiable instanceof QueueNotifiable) {
            $recipient = ['type' => 'object', 'class' => $notifiable::class,
                'identity' => $notifiable->notificationQueueIdentity()];
        } else {
            throw NotificationException::framework('Queued notification recipient needs an explicit identity.');
        }
        return new self($notification::class, $notification->toQueuePayload(), $recipient, $manager);
    }

    public function handle(): void
    {
        if ($this->version !== 1) {
            throw NotificationException::framework('Queued notification job version is unsupported.');
        }
        try {
            $class = $this->notificationClass;
            if (!QueueCodec::validClass($class) || !class_exists($class)
                || !is_subclass_of($class, Notification::class)
                || !is_subclass_of($class, ShouldQueue::class)) {
                throw NotificationException::framework('Queued notification class is invalid.');
            }
            $notification = $class::fromQueuePayload($this->data);
            $manager = $this->workerContainer?->make(NotificationManager::class)
                ?? $this->runtimeManager ?? Notifications::manager();
            $recipient = $this->restoreRecipient($manager);
        } catch (Throwable $failure) {
            if ($failure instanceof NotificationException && $failure->hasFrameworkMessage()) throw $failure;
            // Application reconstruction can throw with payload text. Worker
            // failure metadata must see only this framework-authored message.
            throw NotificationException::framework('Queued notification reconstruction failed.');
        }
        $this->deliveryAttempted = true;
        $manager->deliverQueued($recipient, $notification);
    }

    /** Sync dispatch uses this to avoid counting one delivery failure twice. */
    public function deliveryAttempted(): bool { return $this->deliveryAttempted; }

    public function toQueuePayload(): array
    {
        return ['version' => $this->version, 'notification' => $this->notificationClass,
            'data' => $this->data, 'recipient' => $this->recipient];
    }

    public function setQueueContainer(Container $container): void { $this->workerContainer = $container; }

    public static function fromQueuePayload(array $payload): static
    {
        if (!is_int($payload['version'] ?? null)
            || !is_string($payload['notification'] ?? null)
            || !is_array($payload['data'] ?? null)
            || !is_array($payload['recipient'] ?? null)) {
            throw NotificationException::framework('Queued notification payload is invalid.');
        }
        return new self($payload['notification'], $payload['data'], $payload['recipient'],
            null, $payload['version']);
    }

    private function restoreRecipient(NotificationManager $manager): mixed
    {
        if (($this->recipient['type'] ?? null) === 'anonymous') {
            $mail = $this->recipient['mail'] ?? null;
            if (!is_array($mail) || !is_string($mail['address'] ?? null)
                || !(is_string($mail['name'] ?? null) || ($mail['name'] ?? null) === null)) {
                throw NotificationException::framework('Queued notification recipient is invalid.');
            }
            return $manager->route('mail', new MailAddress($mail['address'], $mail['name'] ?? null));
        }
        if (($this->recipient['type'] ?? null) !== 'object'
            || !is_string($this->recipient['class'] ?? null)
            || !is_array($this->recipient['identity'] ?? null)) {
            throw NotificationException::framework('Queued notification recipient is invalid.');
        }
        $class = $this->recipient['class'];
        if (!QueueCodec::validClass($class) || !class_exists($class)
            || !is_subclass_of($class, QueueNotifiable::class)) {
            throw NotificationException::framework('Queued notification recipient class is invalid.');
        }
        $recipient = $class::resolveNotificationQueueIdentity($this->recipient['identity']);
        if (!$recipient instanceof QueueNotifiable) {
            throw NotificationException::framework('Queued notification recipient reconstruction is invalid.');
        }
        return $recipient;
    }
}
