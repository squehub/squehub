<?php

declare(strict_types=1);

namespace App\Events;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\ServiceProvider;
use App\Queue\QueueManager;

/** Registers one dispatcher and loads explicit application listeners during boot. */
final class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(EventDispatcher::class, static fn (Container $container): EventDispatcher =>
            new EventDispatcher($container, $container->has(Diagnostics::class)
                ? $container->make(Diagnostics::class) : null,
                $container->has(QueueManager::class)
                    ? static fn (): QueueManager => $container->make(QueueManager::class) : null));
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $dispatcher = $container->make(EventDispatcher::class);
        $configured = $this->app->config()->get('events.listeners', []);
        if (!is_array($configured)) throw new EventException('Event listeners configuration must be an array.');
        foreach ($configured as $eventType => $definitions) {
            if (!is_string($eventType) || !is_array($definitions) || !array_is_list($definitions)) {
                throw new EventException('Event listener configuration must map event types to listener lists.');
            }
            if (!class_exists($eventType) && !interface_exists($eventType)) {
                throw new EventException('Configured event type is not an autoloadable class or interface.');
            }
            foreach ($definitions as $definition) {
                $priority = 0;
                $listener = $definition;
                $queued = false;
                $afterCommit = true;
                $queue = 'default';
                $connection = null;
                $delay = 0;
                $transactionConnection = null;
                if (is_array($definition)) {
                    if (!array_key_exists('listener', $definition)
                        || array_diff(array_keys($definition), ['listener', 'priority', 'queued',
                            'after_commit', 'queue', 'connection', 'delay',
                            'transaction_connection']) !== []) {
                        throw new EventException('Event listener configuration entry is malformed.');
                    }
                    $listener = $definition['listener'];
                    $priority = array_key_exists('priority', $definition) ? $definition['priority'] : 0;
                    $queued = $definition['queued'] ?? false;
                    $afterCommit = $definition['after_commit'] ?? true;
                    $queue = $definition['queue'] ?? 'default';
                    $connection = $definition['connection'] ?? null;
                    $delay = $definition['delay'] ?? 0;
                    $transactionConnection = $definition['transaction_connection'] ?? null;
                }
                if (!is_int($priority) || !is_bool($queued) || !is_bool($afterCommit)
                    || !is_string($queue) || !is_int($delay)
                    || ($connection !== null && !is_string($connection))
                    || ($transactionConnection !== null && !is_string($transactionConnection))
                    || (!is_string($listener) && !is_callable($listener))) {
                    throw new EventException('Event listener configuration entry has an invalid listener or priority.');
                }
                if (!$queued && is_array($definition)
                    && array_intersect(['after_commit', 'queue', 'connection', 'delay',
                        'transaction_connection'], array_keys($definition)) !== []) {
                    throw new EventException('Queue options require a queued event listener.');
                }
                if ($queued) {
                    $dispatcher->listenQueued($eventType, $listener, $priority, $afterCommit,
                        $queue, $connection, $delay, $transactionConnection);
                } else {
                    $dispatcher->listen($eventType, $listener, $priority);
                }
            }
        }
        // Configuration listeners precede listeners added by providers booted later.
        Events::setResolver(static fn (): EventDispatcher => $container->make(EventDispatcher::class));
    }
}
