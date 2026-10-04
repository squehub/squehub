<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use PDO;
use PDOException;

/** Keeps the v1 migrations table and adds batch numbers without losing history. */
final class MigrationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function ensure(): void
    {
        // MySQL CREATE/ALTER TABLE can commit an unrelated caller transaction,
        // even when CREATE TABLE IF NOT EXISTS finds the table already present.
        if ($this->pdo->inTransaction()) {
            throw new MigrationException('Migration tracking cannot be prepared during an active transaction.');
        }
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    batch INTEGER NOT NULL DEFAULT 0
                )');
                $columns = $this->pdo->query('PRAGMA table_info(migrations)')->fetchAll(PDO::FETCH_COLUMN, 1);
            } elseif ($driver === 'mysql') {
                $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    batch INT NOT NULL DEFAULT 0
                )');
                $columns = $this->pdo->query('SHOW COLUMNS FROM migrations')->fetchAll(PDO::FETCH_COLUMN, 0);
            } else {
                throw new MigrationException('Migration tracking supports MySQL and SQLite connections.');
            }

            if (!in_array('batch', $columns, true)) {
                $this->pdo->exec('ALTER TABLE migrations ADD COLUMN batch INTEGER NOT NULL DEFAULT 0');
            }

            // Each pre-v2 row represented its own rollback step. Preserve that order.
            $this->pdo->exec('UPDATE migrations SET batch = id WHERE batch = 0');
        } catch (PDOException $exception) {
            throw new MigrationException('Unable to prepare the migrations table.', 0, $exception);
        }
    }

    /** @return list<array{id: int, migration: string, batch: int}> */
    public function rows(): array
    {
        try {
            $rows = $this->pdo->query('SELECT id, migration, batch FROM migrations ORDER BY id ASC')
                ->fetchAll(PDO::FETCH_ASSOC);
            return array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'migration' => (string) $row['migration'],
                'batch' => (int) $row['batch'],
            ], $rows);
        } catch (PDOException $exception) {
            throw new MigrationException('Unable to read migration history.', 0, $exception);
        }
    }

    public function nextBatch(): int
    {
        try {
            return (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn() + 1;
        } catch (PDOException $exception) {
            throw new MigrationException('Unable to read the latest migration batch.', 0, $exception);
        }
    }

    public function record(string $name, int $batch): void
    {
        try {
            $statement = $this->pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)');
            $statement->execute(['migration' => $name, 'batch' => $batch]);
            if ($statement->rowCount() !== 1) {
                throw new MigrationException("Unable to record migration '{$name}'.");
            }
        } catch (PDOException $exception) {
            throw new MigrationException("Unable to record migration '{$name}'.", 0, $exception);
        }
    }

    public function remove(string $name): void
    {
        try {
            $statement = $this->pdo->prepare('DELETE FROM migrations WHERE migration = :migration');
            $statement->execute(['migration' => $name]);
            if ($statement->rowCount() !== 1) {
                throw new MigrationException("Migration record '{$name}' is missing.");
            }
        } catch (PDOException $exception) {
            throw new MigrationException("Unable to remove migration record '{$name}'.", 0, $exception);
        }
    }
}
