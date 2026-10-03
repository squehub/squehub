<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Repository;
use App\Database\Exception\ConnectionException;
use App\Database\Lifecycle\ModelObserverRegistry;
use App\Database\Relations\MorphMap;
use App\Database\Schema\Schema;
use App\Diagnostics\Diagnostics;
use PDOStatement;

/** Owns named, lazily opened connections for one Application. */
final class DatabaseManager
{
    /** @var array<string, Connection> */
    private array $connections = [];
    private ConnectionFactory $factory;
    private ModelClock $modelClock;
    private ModelObserverRegistry $modelObservers;
    private ?MorphMap $morphMap = null;

    public function __construct(
        private Repository $config,
        ?ConnectionFactory $factory = null,
        ?ModelClock $modelClock = null,
        private ?Diagnostics $diagnostics = null,
        ?ModelObserverRegistry $modelObservers = null
    )
    {
        $this->factory = $factory ?? new ConnectionFactory();
        $this->modelClock = $modelClock ?? new SystemModelClock();
        $this->modelObservers = $modelObservers ?? new ModelObserverRegistry();
    }

    public function modelObservers(): ModelObserverRegistry
    {
        return $this->modelObservers;
    }

    public function morphMap(): MorphMap
    {
        return $this->morphMap ??= new MorphMap();
    }

    public function clock(): ModelClock
    {
        return $this->modelClock;
    }

    public function defaultName(): string
    {
        $name = $this->config->get('database.default');
        if (!is_string($name) || $name === '') {
            throw new ConnectionException('A default database connection is not configured.');
        }
        return $name;
    }

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->defaultName();
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/D', $name) !== 1) {
            throw new ConnectionException('Database connection name is invalid.');
        }
        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }
        $configuration = $this->config->get('database.connections.' . $name);
        if (!is_array($configuration)) {
            throw new ConnectionException("Database connection '{$name}' is not configured.");
        }
        return $this->connections[$name] = new Connection($name, $configuration, $this->factory,
            $this->diagnostics, $this->modelObservers, $this);
    }

    public function table(string $table, ?string $connection = null): QueryBuilder
    {
        return $this->connection($connection)->table($table);
    }

    public function schema(?string $connection = null): Schema
    {
        return $this->connection($connection)->schema();
    }

    /** Delegate to one named Connection, preserving its rollback/rethrow contract. */
    public function transaction(callable $callback, ?string $connection = null, int $attempts = 1,
        ?TransactionIsolation $isolation = null): mixed
    {
        return $this->connection($connection)->transaction($callback, $attempts, $isolation);
    }

    public function begin(?string $connection = null, ?TransactionIsolation $isolation = null): void
    {
        $this->connection($connection)->begin($isolation);
    }

    public function commit(?string $connection = null): void
    {
        $this->connection($connection)->commit();
    }

    public function rollback(?string $connection = null): void
    {
        $this->connection($connection)->rollback();
    }

    /** @param array<int|string, mixed> $bindings */
    public function raw(string $sql, array $bindings = [], ?string $connection = null): PDOStatement
    {
        return $this->connection($connection)->raw($sql, $bindings);
    }

    public function disconnect(?string $connection = null): void
    {
        if ($connection !== null) {
            $this->connection($connection)->disconnect();
            return;
        }
        foreach ($this->connections as $instance) {
            $instance->disconnect();
        }
    }
}
