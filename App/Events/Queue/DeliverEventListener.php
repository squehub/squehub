<?php

declare(strict_types=1);

namespace App\Events\Queue;

use App\Container\Container;
use App\Events\EventDispatcher;
use App\Events\EventException;
use App\Events\QueueableEvent;
use App\Queue\QueueCodec;
use App\Queue\QueueContextAware;
use App\Queue\QueueJob;
use Throwable;

/**
 * Executes exactly one queued subscription. The event snapshot is explicit
 * JSON data; the worker never re-emits it or serializes Application state.
 * Queue retries can run this listener more than once after a partial failure.
 */
final class DeliverEventListener implements QueueJob, QueueContextAware
{
    private ?Container $workerContainer = null;

    /** @param array<string|int, mixed> $data */
    private function __construct(private string $eventClass, private string $listenerClass,
        private array $data, private ?EventDispatcher $runtimeDispatcher = null,
        private int $version = 1)
    {
    }

    public static function capture(QueueableEvent $event, string $listenerClass,
        EventDispatcher $dispatcher): self
    {
        return new self($event::class, $listenerClass, $event->toQueuePayload(), $dispatcher);
    }

    public function handle(): void
    {
        try {
            if ($this->version !== 1 || !QueueCodec::validClass($this->eventClass)
                || !QueueCodec::validClass($this->listenerClass)
                || !class_exists($this->eventClass)
                || !is_subclass_of($this->eventClass, QueueableEvent::class)) {
                throw new EventException('Queued event listener payload is invalid.');
            }
            $class = $this->eventClass;
            $event = $class::fromQueuePayload($this->data);
            if (!$event instanceof QueueableEvent || $event::class !== $class) {
                throw new EventException('Queued event reconstruction is invalid.');
            }
            $dispatcher = $this->workerContainer?->make(EventDispatcher::class)
                ?? $this->runtimeDispatcher;
            if (!$dispatcher instanceof EventDispatcher) {
                throw new EventException('Queued event dispatcher is unavailable.');
            }
        } catch (Throwable) {
            // Reconstruction may throw with private event fields. Only a
            // framework-owned message may cross the Queue worker boundary.
            throw new EventException('Queued event listener reconstruction failed.');
        }
        $dispatcher->deliverQueued($this->listenerClass, $event);
    }

    public function toQueuePayload(): array
    {
        return ['version' => $this->version, 'event' => $this->eventClass,
            'listener' => $this->listenerClass, 'data' => $this->data];
    }

    public static function fromQueuePayload(array $payload): static
    {
        if (!is_int($payload['version'] ?? null)
            || !is_string($payload['event'] ?? null)
            || !is_string($payload['listener'] ?? null)
            || !is_array($payload['data'] ?? null)) {
            throw new EventException('Queued event listener payload is invalid.');
        }
        return new self($payload['event'], $payload['listener'], $payload['data'],
            null, $payload['version']);
    }

    public function setQueueContainer(Container $container): void
    {
        $this->workerContainer = $container;
    }
}
