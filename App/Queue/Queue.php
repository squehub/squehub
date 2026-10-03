<?php

declare(strict_types=1);

namespace App\Queue;

use Closure;
use App\Queue\Composition\CompositionHandle;
use App\Queue\Composition\CompositionStatus;

/** Resolve the currently bootstrapped Application's QueueManager. */
final class Queue
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): QueueManager
    {
        if (self::$resolver === null) throw new QueueException('Queue is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }

    /** @param non-empty-list<QueueJob> $jobs */
    public static function chain(array $jobs, string $queue = 'default',
        ?string $connection = null): CompositionHandle
    {
        return self::manager()->chain($jobs, $queue, $connection);
    }

    /** @param non-empty-list<QueueJob> $jobs */
    public static function batch(array $jobs, string $queue = 'default',
        ?string $connection = null): CompositionHandle
    {
        return self::manager()->batch($jobs, $queue, $connection);
    }

    public static function composition(string $id, ?string $connection = null): ?CompositionStatus
    {
        return self::manager()->compositionStatus($id, $connection);
    }

    public static function cancelComposition(string $id, ?string $connection = null): bool
    {
        return self::manager()->cancelComposition($id, $connection);
    }

    public static function pruneCompositions(?int $hours = null, ?string $connection = null): int
    {
        return self::manager()->pruneCompositions($hours, $connection);
    }

    public static function afterCommit(QueueJob $job, string $queue = 'default', int $delay = 0,
        ?string $connection = null, ?string $transactionConnection = null): void
    {
        self::manager()->afterCommit($job, $queue, $delay, $connection, $transactionConnection);
    }
}
