<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Queue\Queue as QueueGateway;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\Composition\CompositionHandle;
use App\Queue\Composition\CompositionStatus;

/** Application-facing dispatch gateway for the current Queue service. */
final class Queue
{
    public static function manager(): QueueManager { return QueueGateway::manager(); }

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

    public static function dispatch(QueueJob $job, string $queue = 'default', int $delay = 0,
        ?string $connection = null): void
    {
        self::manager()->dispatch($job, $queue, $delay, $connection);
    }

    public static function afterCommit(QueueJob $job, string $queue = 'default', int $delay = 0,
        ?string $connection = null, ?string $transactionConnection = null): void
    {
        self::manager()->afterCommit($job, $queue, $delay, $connection, $transactionConnection);
    }
}
