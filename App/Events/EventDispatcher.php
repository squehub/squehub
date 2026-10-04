<?php

declare(strict_types=1);

namespace App\Events;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Events\Queue\DeliverEventListener;
use App\Queue\QueueCodec;
use App\Queue\QueueManager;
use Closure;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * One Application's append-only listener registry. Matching is
 * by PHP class/interface type; a stable snapshot defines each emission.
 * Ordinary listeners run synchronously in the caller's transaction context.
 */
final class EventDispatcher
{
    /** @var list<EventSubscription> */
    private array $subscriptions = [];
    private int $nextOrder = 0;

    /**
     * Inspect registrations without resolving listeners or invoking application
     * callbacks. Callable objects and closures remain opaque by design.
     *
     * @return array{items:list<array{event:string,listener:string,priority:int,queued:bool}>,truncated:bool}
     */
    public function subscriptionsSummary(int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));
        $items = [];
        foreach (array_slice($this->subscriptions, 0, $limit) as $subscription) {
            $items[] = [
                'event' => $subscription->eventType,
                'listener' => is_string($subscription->listener)
                    ? $subscription->listener : 'callable',
                'priority' => $subscription->priority,
                'queued' => $subscription->queued,
            ];
        }
        return ['items' => $items, 'truncated' => count($this->subscriptions) > $limit];
    }

    /** @param ?Closure():QueueManager $queueResolver */
    public function __construct(private Container $container, private ?Diagnostics $diagnostics = null,
        private ?Closure $queueResolver = null)
    {
    }

    /** Register one subscription; duplicate calls intentionally run twice. */
    public function listen(string $eventType, string|callable $listener, int $priority = 0): void
    {
        if (!class_exists($eventType) && !interface_exists($eventType)) {
            throw new EventException('Event subscription type is not an autoloadable class or interface.');
        }
        if (is_string($listener)) {
            $this->validateClassListener($listener);
        } elseif (!is_callable($listener)) {
            throw new EventException('Event listener must be a class or callable.');
        }
        $this->subscriptions[] = new EventSubscription($eventType, $listener, $priority, $this->nextOrder++);
    }

    /**
     * Register class work for Queue delivery. A closure cannot cross the
     * process boundary; after-commit is the safe default for database events.
     */
    public function listenQueued(string $eventType, string|callable $listener, int $priority = 0,
        bool $afterCommit = true, string $queue = 'default', ?string $connection = null,
        int $delay = 0, ?string $transactionConnection = null): void
    {
        if ($this->queueResolver === null) {
            throw new EventException('Queued event listeners require the Queue service.');
        }
        if (!class_exists($eventType) && !interface_exists($eventType)) {
            throw new EventException('Event subscription type is not an autoloadable class or interface.');
        }
        if (!is_string($listener)) {
            throw new EventException('Queued event listener must be a class name.');
        }
        $this->validateClassListener($listener);
        QueueManager::name($queue);
        if ($connection !== null) QueueManager::name($connection, 'connection');
        if ($delay < 0 || $delay > 31536000) {
            throw new EventException('Queued event listener delay is invalid.');
        }
        if ($transactionConnection !== null && $transactionConnection === '') {
            throw new EventException('Queued event transaction connection is invalid.');
        }
        $this->subscriptions[] = new EventSubscription($eventType, $listener, $priority,
            $this->nextOrder++, true, $afterCommit, $queue, $connection, $delay,
            $transactionConnection);
    }

    /** Deliver one object to matching listeners and ignore their return values. */
    public function emit(object $event): void
    {
        $started = hrtime(true);
        $invocations = 0;
        $stopped = false;
        $failed = false;
        try {
            if ($event instanceof StoppableEvent && $event->propagationStopped()) {
                $stopped = true;
                return;
            }

            // Listeners added during dispatch belong to the next emission.
            $matching = [];
            foreach ($this->subscriptions as $subscription) {
                if (is_a($event, $subscription->eventType)) $matching[] = $subscription;
            }
            usort($matching, static fn (EventSubscription $a, EventSubscription $b): int =>
                ($b->priority <=> $a->priority) ?: ($a->order <=> $b->order));
            $observeListeners = $this->diagnostics?->observability()?->enabled() === true;

            foreach ($matching as $subscription) {
                $listener = $subscription->listener;
                if ($subscription->queued) {
                    if (!$event instanceof QueueableEvent) {
                        throw new EventException('Queued event requires a QueueableEvent payload.');
                    }
                    if (!is_string($listener)) {
                        throw new EventException('Queued event listener must be a class name.');
                    }
                    try {
                        $job = DeliverEventListener::capture($event, $listener, $this);
                    } catch (Throwable) {
                        // Application payload code may throw with sensitive values.
                        throw new EventException('Queued event payload could not be captured.');
                    }
                    // Sync Queue does not persist or encode jobs, so validate
                    // the same bounded JSON representation before either path.
                    QueueCodec::encode($job);
                    $queue = ($this->queueResolver)();
                    if ($subscription->afterCommit) {
                        $queue->afterCommit($job, $subscription->queue, $subscription->delay,
                            $subscription->connection, $subscription->transactionConnection);
                    } else {
                        $queue->dispatch($job, $subscription->queue, $subscription->delay,
                            $subscription->connection);
                    }
                } elseif (is_string($listener)) {
                    $resolved = $this->resolveClassListener($listener, $event);
                    ++$invocations;
                    if (!$observeListeners) {
                        $resolved->handle($event);
                    } else {
                        $this->diagnostics->span('event.listener', static function () use ($resolved, $event): void {
                            $resolved->handle($event);
                        }, ['listener_type' => $listener, 'event_type' => $event::class,
                            'mode' => 'sync']);
                    }
                } else {
                    ++$invocations;
                    if (!$observeListeners) $listener($event);
                    else $this->diagnostics->span('event.listener',
                        static function () use ($listener, $event): void { $listener($event); },
                        ['listener_type' => is_object($listener) ? $listener::class : 'callable',
                            'event_type' => $event::class, 'mode' => 'sync']);
                }
                if ($event instanceof StoppableEvent && $event->propagationStopped()) {
                    $stopped = true;
                    break;
                }
            }
        } catch (Throwable $exception) {
            $failed = true;
            throw $exception;
        } finally {
            $milliseconds = max(0.0, (hrtime(true) - $started) / 1_000_000);
            try {
                $this->diagnostics?->event($invocations, $stopped, $failed, $milliseconds,
                    $event::class);
            } catch (Throwable) {
                // Observation must not replace a listener's original result.
            }
        }
    }

    /** @internal Deliver one reconstructed event without re-emitting it. */
    public function deliverQueued(string $listener, QueueableEvent $event): void
    {
        $this->validateClassListener($listener);
        if ($this->diagnostics?->observability()?->enabled() !== true) {
            $this->resolveClassListener($listener, $event)->handle($event);
            return;
        }
        $this->diagnostics->span('event.listener', function () use ($listener, $event): void {
            $this->resolveClassListener($listener, $event)->handle($event);
        }, ['listener_type' => $listener, 'event_type' => $event::class, 'mode' => 'queued']);
    }

    private function resolveClassListener(string $listener, object $event): object
    {
        try {
            $resolved = $this->container->make($listener);
        } catch (Throwable $exception) {
            // Constructor resolution is dispatcher infrastructure; preserve
            // its cause without exposing event properties in the message.
            throw new EventException('Cannot resolve listener ' . $listener
                . ' for event ' . $event::class . '.', 0, $exception);
        }
        return $resolved;
    }

    /** Validate class shape without invoking its constructor or handle method. */
    private function validateClassListener(string $listener): void
    {
        if (!class_exists($listener)) {
            throw new EventException('Event listener class is not autoloadable: ' . $listener . '.');
        }
        $class = new ReflectionClass($listener);
        if (!$class->isInstantiable() || !$class->hasMethod('handle')) {
            throw new EventException('Event listener must be instantiable and declare public handle(): ' . $listener . '.');
        }
        $handle = $class->getMethod('handle');
        if (!$handle->isPublic() || $handle->getNumberOfParameters() !== 1
            || $handle->getParameters()[0]->isVariadic()) {
            throw new EventException('Event listener handle() must accept exactly one event: ' . $listener . '.');
        }
        $type = $handle->getParameters()[0]->getType();
        if ($type instanceof ReflectionNamedType && $type->isBuiltin()
            && !in_array($type->getName(), ['object', 'mixed'], true)) {
            throw new EventException('Event listener handle() must accept an object event: ' . $listener . '.');
        }
    }
}
