<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Exception\ConnectionException;
use PDO;
use PDOException;

/** Creates PDO only when a connection is first used. */
final class ConnectionFactory
{
    /** @param array<string, mixed> $configuration */
    public function create(string $name, array $configuration): PDO
    {
        $driver = $configuration['driver'] ?? null;
        if ($driver === 'sqlite') {
            $database = $configuration['database'] ?? null;
            if (!is_string($database) || $database === '') {
                throw new ConnectionException("SQLite connection '{$name}' requires a database path.");
            }
            $dsn = 'sqlite:' . $database;
            $username = null;
            $password = null;
        } elseif ($driver === 'mysql') {
            $host = $this->dsnPart($configuration['host'] ?? null, 'host', $name);
            $database = $this->dsnPart($configuration['database'] ?? null, 'database', $name);
            $charset = $this->dsnPart($configuration['charset'] ?? 'utf8mb4', 'charset', $name);
            $port = $configuration['port'] ?? 3306;
            if (filter_var($port, FILTER_VALIDATE_INT) === false || (int) $port < 1 || (int) $port > 65535) {
                throw new ConnectionException("MySQL connection '{$name}' has an invalid port.");
            }
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            $username = $configuration['username'] ?? $configuration['user'] ?? '';
            $password = $configuration['password'] ?? '';
            if (!is_string($username) || !is_string($password)) {
                throw new ConnectionException("MySQL connection '{$name}' has invalid credentials.");
            }
        } else {
            throw new ConnectionException("Database driver for connection '{$name}' is unsupported.");
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if ($driver === 'mysql') {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
            // Doctor's read-only connectivity probe must remain bounded when
            // a configured MySQL server is unreachable. This also protects
            // ordinary first-use connections from indefinite network waits.
            $options[PDO::ATTR_TIMEOUT] = 5;
        }

        try {
            $pdo = new PDO($dsn, $username, $password, $options);
            if ($driver === 'sqlite') {
                // SQLite defaults vary by build; enforce foreign keys before transactions.
                $pdo->exec('PRAGMA foreign_keys = ON');
                if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
                    throw new ConnectionException("SQLite connection '{$name}' cannot enforce foreign keys.");
                }
            }
            return $pdo;
        } catch (PDOException $exception) {
            // PDO diagnostics can contain DSNs or credentials; keep them out of public messages.
            throw new ConnectionException("Unable to connect to database '{$name}'.", 0, $exception);
        }
    }

    private function dsnPart(mixed $value, string $part, string $name): string
    {
        if (!is_string($value) || $value === '' || str_contains($value, ';') || str_contains($value, "\0")) {
            throw new ConnectionException("MySQL connection '{$name}' has an invalid {$part}.");
        }
        return $value;
    }
}
