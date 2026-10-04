<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Diagnostics\Diagnostics;
use App\Mail\MailAddress;
use App\Mail\MailException;
use App\Notifications\Queue\DeliverNotification;
use App\Queue\QueueCodec;
use App\Queue\QueueException;
use App\Queue\QueueManager;
use Closure;
use Throwable;

/**
 * Application-owned channel registry. Definitions are lazy and
 * cached per manager; delivery stops at the first failure without rolling
 * back channels that already completed.
 */
final class NotificationManager
{
    /** @var array<string,Closure|NotificationChannel> */
    private array $definitions = [];
    /** @var array<string,NotificationChannel> */
    private array $resolved = [];

    public function __construct(private ?Diagnostics $diagnostics = null,
        private ?QueueManager $queueManager = null)
    {
    }

    /** Register a channel once; a factory is not called until that channel runs. */
    public function registerChannel(string $name, Closure|NotificationChannel $channel): void
    {
        self::validateName($name);
        if (isset($this->definitions[$name])) {
            throw NotificationException::framework('Notification channel is already registered.');
        }
        $this->definitions[$name] = $channel;
    }

    /** Resolve for deliberate Array-channel inspection or custom integrations. */
    public function channel(string $name): NotificationChannel
    {
        self::validateName($name);
        if (!isset($this->definitions[$name])) {
            throw NotificationException::framework('Notification channel is unavailable.');
        }
        if (!isset($this->resolved[$name])) {
            $definition = $this->definitions[$name];
            try {
                $channel = $definition instanceof Closure ? $definition() : $definition;
            } catch (Throwable $failure) {
                if ($failure instanceof NotificationException && $failure->hasFrameworkMessage()) throw $failure;
                throw NotificationException::framework('Notification channel could not be resolved.');
            }
            if (!$channel instanceof NotificationChannel) {
                throw NotificationException::framework('Notification channel factory returned an invalid channel.');
            }
            $this->resolved[$name] = $channel;
        }
        return $this->resolved[$name];
    }

    /**
     * Repeated channel names run once, in first-occurrence order. An empty
     * list succeeds without a channel delivery; invalid names fail before any
     * channel runs so a typo cannot cause avoidable partial delivery.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        if ($notification instanceof ShouldQueue) {
            $this->enqueue($notifiable, $notification);
            return;
        }
        $this->deliver($notifiable, $notification, true);
    }

    /** Worker entry bypasses queue dispatch, preserving the normal channel path. */
    public function deliverQueued(mixed $notifiable, Notification $notification): void
    {
        $this->deliver($notifiable, $notification, false);
    }

    private function enqueue(mixed $notifiable, Notification&ShouldQueue $notification): void
    {
        if ($this->queueManager === null) {
            throw NotificationException::framework('Queue service is unavailable for notifications.');
        }
        $started = hrtime(true);
        try {
            $job = DeliverNotification::capture($notifiable, $notification, $this);
            // The sync driver executes immediately and does not encode jobs;
            // validate JSON shape and size before either Queue driver runs.
            QueueCodec::encode($job);
            if ($notification->queueAfterCommit()) {
                $this->queueManager->afterCommit($job, $notification->queueName(),
                    $notification->queueDelay(), $notification->queueConnection(),
                    $notification->queueTransactionConnection(),
                    fn () => $this->diagnostics?->notification('queued'));
                $this->diagnostics?->notification('attempts');
            } else {
                $this->queueManager->dispatch($job, $notification->queueName(),
                    $notification->queueDelay(), $notification->queueConnection());
                $this->diagnostics?->notification('attempts');
                $this->diagnostics?->notification('queued');
            }
        } catch (Throwable $failure) {
            // On sync Queue, the job may already have reached deliverQueued(),
            // which counted its own channel failure. Count only failures that
            // happened before that delivery boundary.
            if (!isset($job) || !$job->deliveryAttempted()) {
                $this->diagnostics?->notification('failures');
            }
            if ($failure instanceof NotificationException && $failure->hasFrameworkMessage()) throw $failure;
            throw NotificationException::framework('Notification enqueue failed.',
                $failure instanceof QueueException ? $failure : null);
        } finally {
            $this->diagnostics?->notificationTime((hrtime(true) - $started) / 1_000_000);
        }
    }

    private function deliver(mixed $notifiable, Notification $notification, bool $countAttempt): void
    {
        if ($countAttempt) $this->diagnostics?->notification('attempts');
        $started = hrtime(true);
        try {
            $via = $notification->via($notifiable);
            $ordered = [];
            foreach ($via as $name) {
                if (!is_string($name)) throw NotificationException::framework('Notification channel name is invalid.');
                self::validateName($name);
                if (!isset($this->definitions[$name])) {
                    throw NotificationException::framework('Notification channel is unavailable.');
                }
                $ordered[$name] = true;
            }
            foreach (array_keys($ordered) as $name) {
                $this->channel($name)->send($notifiable, $notification);
                $this->diagnostics?->notification('channel_deliveries');
            }
            $this->diagnostics?->notification('sent');
        } catch (Throwable $failure) {
            $this->diagnostics?->notification('failures');
            if ($failure instanceof NotificationException && $failure->hasFrameworkMessage()) throw $failure;
            // Mail exceptions are already content-safe and worth preserving.
            if ($failure instanceof MailException) {
                throw NotificationException::framework('Notification delivery failed.', $failure);
            }
            // Application code and custom channels may throw with token text.
            throw NotificationException::framework('Notification dispatch failed.');
        } finally {
            $this->diagnostics?->notificationTime((hrtime(true) - $started) / 1_000_000);
        }
    }

    public function route(string $channel, MailAddress|string $address): AnonymousNotifiable
    {
        if ($channel !== 'mail') throw NotificationException::framework('Anonymous notification route is unavailable.');
        try {
            return new AnonymousNotifiable($this,
                $address instanceof MailAddress ? $address : new MailAddress($address));
        } catch (MailException $failure) {
            throw NotificationException::framework('Anonymous mail route is invalid.', $failure);
        }
    }

    private static function validateName(string $name): void
    {
        if (strlen($name) > 128 || preg_match('/\A[A-Za-z0-9_][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw NotificationException::framework('Notification channel name is invalid.');
        }
    }
}
