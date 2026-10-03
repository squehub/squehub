<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Exception\DatabaseException;
use App\Database\Exception\QueryException;
use App\Database\Lifecycle\ModelObserverRegistry;
use App\Database\Schema\Schema;
use App\Diagnostics\Diagnostics;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use WeakReference;

/** A configured connection whose PDO handle is opened on first use. */
final class Connection
{
    private ?PDO $pdo = null;
    private int $transactionDepth = 0;
    /** @var array<int, list<callable():void>> Callbacks belong to their savepoint until it commits. */
    private array $afterCommit = [];
    private ModelObserverRegistry $modelObservers;
    /** @var ?WeakReference<DatabaseManager> */
    private ?WeakReference $owner;

    /** @param array<string, mixed> $configuration */
    public function __construct(
        private string $name,
        private array $configuration,
        private ConnectionFactory $factory,
        private ?Diagnostics $diagnostics = null,
        ?ModelObserverRegistry $modelObservers = null,
        ?DatabaseManager $owner = null
    ) {
        $this->modelObservers = $modelObservers ?? new ModelObserverRegistry();
        $this->owner = $owner === null ? null : WeakReference::create($owner);
    }

    public function modelObservers(): ModelObserverRegistry
    {
        return $this->modelObservers;
    }

    public function owner(): ?DatabaseManager
    {
        $owner = $this->owner?->get();
        return $owner instanceof DatabaseManager ? $owner : null;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function driver(): string
    {
        return (string) ($this->configuration['driver'] ?? '');
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->factory->create($this->name, $this->configuration);
    }

    public function disconnect(): void
    {
        $this->assertTransactionState();
        if ($this->pdo?->inTransaction()) {
            throw new DatabaseException('Cannot disconnect during a transaction.');
        }
        $this->pdo = null;
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    public function schema(): Schema
    {
        return new Schema($this);
    }

    /**
     * Prepare and execute trusted SQL structure with bound values. Diagnostics
     * count attempted statements without retaining SQL text or bindings.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function raw(string $sql, array $bindings = []): PDOStatement
    {
        $started = null;
        $succeeded = false;
        try {
            $pdo = $this->pdo();
            $started = hrtime(true);
            $statement = $pdo->prepare($sql);
            if ($statement === false) {
                throw new QueryException('Unable to prepare database query.');
            }
            $statement->execute($bindings);
            $succeeded = true;
            return $statement;
        } catch (PDOException $exception) {
            throw new QueryException('Database query failed.', 0, $exception);
        } finally {
            // Count attempted statements, including failed prepares and executes.
            // SQL and bindings never enter the diagnostics collector.
            if ($started !== null) $this->recordQuery((hrtime(true) - $started) / 1_000_000,
                self::sqlOperation($sql), !$succeeded);
        }
    }

    /** @internal QueryBuilder uses this for its direct, typed PDO execution. */
    public function recordQuery(float $milliseconds, string $operation = 'other',
        bool $failed = false): void
    {
        $this->diagnostics?->query($this->name, $milliseconds, $operation,
            $this->driver(), $failed);
    }

    /** @internal Classify the first SQL verb without retaining statement text. */
    public static function sqlOperation(string $sql): string
    {
        if (preg_match('/\A\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|REPLACE|WITH)\b/i',
            $sql, $matches) !== 1) return 'other';
        return strtolower($matches[1]);
    }

    public function begin(?TransactionIsolation $isolation = null): void
    {
        $this->assertTransactionState();
        if ($this->transactionDepth === 0) {
            if ($isolation !== null && $this->driver() !== 'mysql') {
                throw new DatabaseException('Explicit transaction isolation is supported only for MySQL.');
            }
            $pdo = $this->pdo();
            if ($pdo->inTransaction()) {
                throw new DatabaseException('A transaction was opened outside this connection.');
            }
            if ($isolation !== null) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . $isolation->value);
            }
            try {
                $pdo->beginTransaction();
            } catch (Throwable $failure) {
                // SET TRANSACTION may have selected the next transaction's isolation.
                // Discard this handle if BEGIN did not consume that setting.
                if ($isolation !== null) $this->pdo = null;
                throw $failure;
            }
        } else {
            if ($isolation !== null) {
                throw new DatabaseException('Transaction isolation cannot change inside a nested transaction.');
            }
            $this->pdo()->exec('SAVEPOINT squehub_tx_' . ($this->transactionDepth + 1));
        }
        $this->afterCommit[++$this->transactionDepth] = [];
    }

    public function commit(): void
    {
        $this->assertTransactionState();
        if ($this->transactionDepth === 0) {
            throw new DatabaseException('No active transaction to commit.');
        }
        $depth = $this->transactionDepth;
        if ($depth > 1) {
            $this->pdo()->exec('RELEASE SAVEPOINT squehub_tx_' . $depth);
            $this->afterCommit[$depth - 1] = array_merge(
                $this->afterCommit[$depth - 1], $this->afterCommit[$depth]
            );
            unset($this->afterCommit[$depth]);
            --$this->transactionDepth;
            return;
        }
        $this->pdo()->commit();
        $callbacks = $this->afterCommit[1];
        $this->afterCommit = [];
        $this->transactionDepth = 0;
        $firstFailure = null;
        // The business commit is final. Continue dispatching later callbacks
        // even when an earlier post-commit action fails.
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $failure) {
                $firstFailure ??= $failure;
            }
        }
        if ($firstFailure !== null) throw $firstFailure;
    }

    public function rollback(): void
    {
        $this->assertTransactionState();
        if ($this->transactionDepth === 0) {
            throw new DatabaseException('No active transaction to roll back.');
        }
        $depth = $this->transactionDepth;
        if ($depth > 1) {
            $this->pdo()->exec('ROLLBACK TO SAVEPOINT squehub_tx_' . $depth);
            $this->pdo()->exec('RELEASE SAVEPOINT squehub_tx_' . $depth);
            unset($this->afterCommit[$depth]);
            --$this->transactionDepth;
            return;
        }
        $this->pdo()->rollBack();
        $this->afterCommit = [];
        $this->transactionDepth = 0;
    }

    public function inTransaction(): bool
    {
        if ($this->transactionDepth > 0 && $this->pdo !== null && !$this->pdo->inTransaction()) {
            $this->resetTransactionState();
        }
        return $this->transactionDepth > 0;
    }

    /** Register work for the outermost commit; rollback of its savepoint discards it. */
    public function afterCommit(callable $callback): void
    {
        $this->assertTransactionState();
        if ($this->transactionDepth === 0) {
            if ($this->pdo !== null && $this->pdo->inTransaction()) {
                throw new DatabaseException('Cannot register after-commit work for an unmanaged PDO transaction.');
            }
            $callback();
            return;
        }
        $this->afterCommit[$this->transactionDepth][] = $callback;
    }

    public function transaction(callable $callback, int $attempts = 1,
        ?TransactionIsolation $isolation = null): mixed
    {
        if ($attempts < 1 || $attempts > TransactionRetryPolicy::MAX_ATTEMPTS) {
            throw new DatabaseException('Transaction attempts must be between 1 and '
                . TransactionRetryPolicy::MAX_ATTEMPTS . '.');
        }
        $this->assertTransactionState();
        $parentDepth = $this->transactionDepth;
        if ($parentDepth > 0 && $attempts > 1) {
            throw new DatabaseException('Only an outermost transaction can be retried.');
        }
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            try {
                $this->begin($isolation);
            } catch (Throwable $failure) {
                if ($parentDepth === 0 && $attempt < $attempts
                    && TransactionRetryPolicy::retryable($failure, $this->driver())) {
                    TransactionRetryPolicy::pause($attempt);
                    continue;
                }
                throw $failure;
            }

            try {
                $result = $callback($this);
            } catch (Throwable $failure) {
                $rolledBack = $this->rollbackAfterFailure($parentDepth);
                if ($rolledBack && $parentDepth === 0 && $attempt < $attempts
                    && TransactionRetryPolicy::retryable($failure, $this->driver())) {
                    TransactionRetryPolicy::pause($attempt);
                    continue;
                }
                throw $failure;
            }

            try {
                $this->commit();
                return $result;
            } catch (Throwable $failure) {
                // A post-commit callback may have failed after SQL committed.
                // Never retry here because repeating the callback could duplicate writes.
                $this->rollbackAfterFailure($parentDepth);
                throw $failure;
            }
        }
        throw new DatabaseException('Transaction attempts were exhausted.');
    }

    /** Preserve the original failure even when rollback itself fails. */
    private function rollbackAfterFailure(int $parentDepth): bool
    {
        if ($this->transactionDepth <= $parentDepth) {
            return false;
        }
        if ($this->pdo === null || !$this->pdo->inTransaction()) {
            // The driver or caller ended the transaction. Its outcome is
            // unknown here, so replaying the callback could duplicate writes.
            $this->resetTransactionState();
            return false;
        }
        try {
            $this->rollback();
            return true;
        } catch (Throwable) {
            // The transaction outcome is uncertain. Drop the framework handle
            // and all callbacks; the original callback/commit failure wins.
            $this->resetTransactionState();
            $this->pdo = null;
            return false;
        }
    }

    private function assertTransactionState(): void
    {
        if ($this->transactionDepth > 0 && $this->pdo !== null && !$this->pdo->inTransaction()) {
            $this->resetTransactionState();
            throw new DatabaseException('Managed transaction ended outside this connection.');
        }
    }

    private function resetTransactionState(): void
    {
        $this->transactionDepth = 0;
        $this->afterCommit = [];
    }
}
