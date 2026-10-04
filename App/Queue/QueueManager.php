<?php

declare(strict_types=1);

namespace App\Queue;

use App\Database\Connection;
use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\Diagnostics\Diagnostics;
use App\Observability\CorrelationContext;
use App\Queue\Composition\CompositionDriver;
use App\Queue\Composition\CompositionHandle;
use App\Queue\Composition\CompositionStatus;
use App\Queue\Drivers\DatabaseQueueDriver;
use App\Queue\Drivers\RedisQueueDriver;
use App\Queue\Drivers\SyncQueueDriver;
use App\Redis\InfrastructureSelection;
use App\Redis\RedisManager;
use Closure;
use Throwable;

/** Selects lazy, Application-owned queue connections and validates public dispatch. */
final class QueueManager
{
    /** @var array<string, QueueDriver> */
    private array $drivers = [];
    private ModelClock $clock;
    /** @var array<string,InfrastructureSelection> */
    private array $infrastructure = [];
    /** @var array<string,CompositionStatus> Sync compositions live only in this Application. */
    private array $syncCompositions = [];

    /** @param array<string, mixed> $settings
     *  @param ?Closure(?string):Connection $databaseConnection
     */
    public function __construct(
        private array $settings,
        private ?Closure $databaseConnection = null,
        ?ModelClock $clock = null,
        private ?Diagnostics $diagnostics = null,
        private ?RedisManager $redisManager = null
    ) {
        $this->clock = $clock ?? new SystemModelClock();
        if (!is_string($settings['default'] ?? null) || !is_array($settings['connections'] ?? null)) {
            throw new QueueException('Queue default and connections must be configured.');
        }
        self::name($settings['default'], 'connection');
    }

    public function defaultName(): string { return $this->settings['default']; }

    /** @internal Shared Application context for workers constructed manually. */
    public function correlationContext(): ?CorrelationContext
    {
        return $this->diagnostics?->correlation();
    }

    /** Each named connection has its own fixed, endpoint-free selection. */
    public function infrastructure(?string $name = null): ?InfrastructureSelection
    {
        return $this->infrastructure[$name ?? $this->defaultName()] ?? null;
    }

    public function driver(?string $name = null): QueueDriver
    {
        $name ??= $this->defaultName();
        self::name($name, 'connection');
        if (isset($this->drivers[$name])) return $this->drivers[$name];
        $config = $this->settings['connections'][$name] ?? null;
        if (!is_array($config)) throw new QueueException('Queue connection is not configured.');
        $driver = $config['driver'] ?? null;
        if ($driver !== 'auto' && is_string($driver)) {
            $redisName = $config['redis_connection'] ?? null;
            $this->infrastructure[$name] ??= new InfrastructureSelection($driver, 'database',
                $this->redisManager, is_string($redisName) ? $redisName : null);
            $this->infrastructure[$name]->resolve();
        }
        if ($driver === 'auto') {
            $redisName = $config['redis_connection'] ?? null;
            if ($redisName !== null && !is_string($redisName)) {
                throw new QueueException('Queue Redis connection is invalid.');
            }
            $this->infrastructure[$name] ??= new InfrastructureSelection('auto', 'database',
                $this->redisManager, $redisName);
            $selected = $this->infrastructure[$name]->resolve();
            return $this->drivers[$name] = $this->driver($selected);
        }
        if ($driver === 'sync') return $this->drivers[$name] = new SyncQueueDriver();
        if ($driver === 'redis') {
            if ($this->redisManager === null) {
                throw new QueueException('Redis service is unavailable for the Queue connection.');
            }
            $redisName = $config['redis_connection'] ?? null;
            if ($redisName !== null && (!is_string($redisName) || $redisName === '')) {
                throw new QueueException('Queue Redis connection is invalid.');
            }
            $timeout = $config['retry_after'] ?? 60;
            if (!is_int($timeout) || $timeout < 1) {
                throw new QueueException('Queue reservation timeout is invalid.');
            }
            $namespace = $config['namespace'] ?? hash('sha256', (string) getcwd());
            if (!is_string($namespace)) throw new QueueException('Redis Queue namespace is invalid.');
            return $this->drivers[$name] = new RedisQueueDriver(
                $this->redisManager->connection($redisName), $namespace, $timeout);
        }
        if ($driver !== 'database') throw new QueueException('Queue driver is unsupported.');
        if ($this->databaseConnection === null) {
            throw new QueueException('Database service is unavailable for the queue connection.');
        }
        $databaseName = $config['database_connection'] ?? null;
        if ($databaseName !== null && (!is_string($databaseName) || $databaseName === '')) {
            throw new QueueException('Queue database connection is invalid.');
        }
        $timeout = $config['retry_after'] ?? 60;
        if (!is_int($timeout) || $timeout < 1) throw new QueueException('Queue reservation timeout is invalid.');
        return $this->drivers[$name] = new DatabaseQueueDriver(
            ($this->databaseConnection)($databaseName), $this->clock,
            $config['table'] ?? 'queue_jobs', $config['failed_table'] ?? 'queue_failed_jobs', $timeout
        );
    }

    /** Redis workers share a marker; database workers retain the local marker. */
    public function restartSignal(?string $connection = null): ?RedisRestartSignal
    {
        $driver = $this->driver($connection);
        return $driver instanceof RedisQueueDriver ? $driver->restartSignal() : null;
    }

    public function dispatch(QueueJob $job, string $queue = 'default', int $delay = 0,
        ?string $connection = null): void
    {
        $this->dispatchCaptured($job, $queue, $delay, $connection, $this->captureCorrelation());
    }

    /** One captured ID is written into supported drivers' outer envelope. */
    private function dispatchCaptured(QueueJob $job, string $queue, int $delay,
        ?string $connection, string $correlationId): void
    {
        self::name($queue, 'queue');
        if ($delay < 0 || $delay > 31536000) throw new QueueException('Queue delay is invalid.');
        $started = hrtime(true);
        $failed = false;
        try {
            $driver = $this->driver($connection);
            if ($driver instanceof CorrelatedQueueDriver) {
                $driver->dispatchCorrelated($job, $queue, $delay, $correlationId);
            } else {
                // Sync and third-party drivers retain their existing public
                // contract. Sync execution inherits this scoped context.
                $context = $this->diagnostics?->correlation();
                if ($context !== null) {
                    $context->with($correlationId,
                        static fn () => $driver->dispatch($job, $queue, $delay));
                } else {
                    $driver->dispatch($job, $queue, $delay);
                }
            }
            $this->diagnostics?->queue('dispatched');
        } catch (Throwable $exception) {
            $failed = true;
            $this->diagnostics?->queue('errors');
            throw $exception;
        } finally {
            $this->diagnostics?->queueTime((hrtime(true) - $started) / 1_000_000,
                $queue, $connection ?? $this->defaultName(), $failed);
        }
    }

    /** @param non-empty-list<QueueJob> $jobs */
    public function chain(array $jobs, string $queue = 'default', ?string $connection = null): CompositionHandle
    {
        return $this->compose('chain', $jobs, $queue, $connection);
    }

    /** @param non-empty-list<QueueJob> $jobs */
    public function batch(array $jobs, string $queue = 'default', ?string $connection = null): CompositionHandle
    {
        return $this->compose('batch', $jobs, $queue, $connection);
    }

    /**
     * Validate the whole definition before any backend write. Composition IDs
     * identify an operation; the driver still assigns its normal job IDs.
     *
     * @param array<mixed> $jobs
     */
    private function compose(string $kind, array $jobs, string $queue,
        ?string $connection): CompositionHandle
    {
        self::name($queue, 'queue');
        $settings = $this->settings['composition'] ?? [];
        if (!is_array($settings)) throw new QueueException('Queue composition configuration is invalid.');
        $maxJobs = $settings['max_jobs'] ?? 100;
        $maxBytes = $settings['max_payload_bytes'] ?? 1048576;
        $retention = $settings['retention_hours'] ?? 168;
        if (!is_int($maxJobs) || $maxJobs < 1 || $maxJobs > 100
            || !is_int($maxBytes) || $maxBytes < 1 || $maxBytes > 1048576
            || !is_int($retention) || $retention < 1 || $retention > 87600) {
            throw new QueueException('Queue composition limits are invalid.');
        }
        if (!array_is_list($jobs) || count($jobs) < 1 || count($jobs) > $maxJobs) {
            throw new QueueException('Queue composition job count is invalid.');
        }
        $correlationId = $this->captureCorrelation();
        $encoded = [];
        $bytes = 0;
        foreach ($jobs as $job) {
            if (!$job instanceof QueueJob) throw new QueueException('Queue composition requires QueueJob instances.');
            $payload = QueueCodec::encode($job, $correlationId);
            $bytes += strlen($payload);
            if ($bytes > $maxBytes) throw new QueueException('Queue composition payload exceeds its limit.');
            $encoded[] = $payload;
        }
        $connection ??= $this->defaultName();
        self::name($connection, 'connection');
        $configured = $this->settings['connections'][$connection] ?? null;
        if (!is_array($configured) || ($configured['driver'] ?? null) === 'auto') {
            // A producer and worker must address the same durable state. An
            // auto-selected backend may differ after an Application restarts.
            throw new QueueException('Queue composition requires a fixed configured connection.');
        }
        $started = hrtime(true);
        $dispatchFailed = false;
        try {
            $driver = $this->driver($connection);
            $id = bin2hex(random_bytes(16));
            if ($driver instanceof SyncQueueDriver) {
                $succeeded = 0;
                $failedJobs = 0;
                $cancelled = 0;
                foreach ($encoded as $position => $payload) {
                    try {
                        $job = QueueCodec::decode($payload);
                        $context = $this->diagnostics?->correlation();
                        if ($context !== null) {
                            $context->with($correlationId,
                                static fn () => $driver->dispatch($job, $queue, 0));
                        } else {
                            $driver->dispatch($job, $queue, 0);
                        }
                        ++$succeeded;
                    } catch (Throwable) {
                        ++$failedJobs;
                        $this->diagnostics?->queue('failed');
                        if ($kind === 'chain') {
                            $cancelled = count($encoded) - $position - 1;
                            break;
                        }
                    }
                }
                $state = $failedJobs > 0 ? ($kind === 'chain' ? 'failed' : 'completed_with_failures') : 'completed';
                $this->syncCompositions[$id] = new CompositionStatus($id, $kind, $state,
                    count($encoded), $succeeded, $failedJobs, $cancelled);
            } elseif ($driver instanceof CompositionDriver) {
                $driver->createComposition($id, $kind, $encoded, $queue, $retention);
            } else {
                throw new QueueException('Queue connection cannot store compositions.');
            }
            // One accepted public composition operation, irrespective of job
            // count. Driver-internal chain advancement is not another call.
            $this->diagnostics?->queue('dispatched');
            return new CompositionHandle($id, $connection, $this);
        } catch (Throwable $exception) {
            $dispatchFailed = true;
            $this->diagnostics?->queue('errors');
            throw $exception;
        } finally {
            $this->diagnostics?->queueTime((hrtime(true) - $started) / 1_000_000,
                $queue, $connection, $dispatchFailed);
        }
    }

    public function compositionStatus(string $id, ?string $connection = null): ?CompositionStatus
    {
        self::compositionId($id);
        $driver = $this->compositionConnection($connection);
        if ($driver instanceof SyncQueueDriver) return $this->syncCompositions[$id] ?? null;
        return $driver->compositionStatus($id);
    }

    public function cancelComposition(string $id, ?string $connection = null): bool
    {
        self::compositionId($id);
        $driver = $this->compositionConnection($connection);
        if ($driver instanceof SyncQueueDriver) return false;
        return $driver->cancelComposition($id);
    }

    public function pruneCompositions(?int $hours = null, ?string $connection = null): int
    {
        $hours ??= $this->settings['composition']['retention_hours'] ?? 168;
        if (!is_int($hours)) throw new QueueException('Queue composition retention is invalid.');
        if ($hours < 1 || $hours > 87600) throw new QueueException('Queue composition retention is invalid.');
        $driver = $this->compositionConnection($connection);
        if ($driver instanceof SyncQueueDriver) return 0;
        return $driver->pruneCompositions($hours);
    }

    private function compositionConnection(?string $connection): CompositionDriver|SyncQueueDriver
    {
        $name = $connection ?? $this->defaultName();
        self::name($name, 'connection');
        $config = $this->settings['connections'][$name] ?? null;
        if (!is_array($config) || ($config['driver'] ?? null) === 'auto') {
            throw new QueueException('Queue composition requires a fixed configured connection.');
        }
        $driver = $this->driver($name);
        if (!$driver instanceof CompositionDriver && !$driver instanceof SyncQueueDriver) {
            throw new QueueException('Queue connection cannot store compositions.');
        }
        return $driver;
    }

    private static function compositionId(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
            throw new QueueException('Queue composition ID is invalid.');
        }
    }

    /**
     * Defer dispatch until the outermost transaction on the chosen database
     * connection commits. Queue persistence is a separate post-commit action:
     * a failure here cannot undo the already committed business transaction.
     */
    public function afterCommit(QueueJob $job, string $queue = 'default', int $delay = 0,
        ?string $connection = null, ?string $transactionConnection = null,
        ?Closure $onDispatched = null): void
    {
        self::name($queue, 'queue');
        if ($delay < 0 || $delay > 31536000) throw new QueueException('Queue delay is invalid.');
        // Capture at registration: a transaction may commit after the request
        // that submitted this work has ended or another operation has begun.
        $correlationId = $this->captureCorrelation();
        QueueCodec::encode($job, $correlationId);
        // Resolve auto before registering a post-commit callback. A later
        // Redis outage cannot move deferred work to another Queue backend.
        if (($connection ?? $this->defaultName()) === 'auto') $this->driver($connection);
        if ($this->databaseConnection === null) {
            $this->dispatchCaptured($job, $queue, $delay, $connection, $correlationId);
            $onDispatched?->__invoke();
            return;
        }
        $database = ($this->databaseConnection)($transactionConnection);
        $database->afterCommit(function () use ($job, $queue, $delay, $connection,
            $correlationId, $onDispatched): void {
            try {
                $this->dispatchCaptured($job, $queue, $delay, $connection, $correlationId);
                $onDispatched?->__invoke();
            } catch (Throwable) {
                // Application job and driver exceptions may contain payload
                // values. The callback failure crosses a committed boundary.
                throw new QueueException('Queue dispatch failed after database commit.');
            }
        });
    }

    private function captureCorrelation(): string
    {
        return $this->diagnostics?->correlation()->current() ?? CorrelationContext::generate();
    }

    public static function name(string $name, string $kind = 'queue'): string
    {
        if (strlen($name) > 128 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new QueueException("{$kind} name is invalid.");
        }
        return $name;
    }
}
