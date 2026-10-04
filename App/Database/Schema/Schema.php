<?php

declare(strict_types=1);

namespace App\Database\Schema;

use App\Database\Connection;
use App\Database\Identifier;
use App\Database\Exception\InvalidIdentifierException;
use PDO;
use Throwable;

/** Inspects and changes one selected connection without owning another PDO pool. */
final class Schema
{
    public function __construct(private Connection $connection)
    {
    }

    /** The callback only collects definitions; compilation validates all of them before execution. */
    public function create(string $table, callable $definition): void
    {
        try {
            $tableDefinition = new Table($table);
            $definition($tableDefinition);
            $statements = $this->compiler()->compileCreate($tableDefinition);
        } catch (InvalidIdentifierException $exception) {
            // Unsafe input must not be reflected into public errors.
            throw new SchemaException('Unable to define table: an identifier is invalid.', 0, $exception);
        } catch (SchemaException $exception) {
            throw new SchemaException('Unable to define table: ' . $exception->getMessage(), 0, $exception);
        }

        try {
            $execute = function () use ($statements): void {
                foreach ($statements as $statement) {
                    $this->connection->raw($statement);
                }
            };
            // SQLite DDL and its separate indexes share a transaction when no caller owns one.
            if ($this->connection->driver() === 'sqlite' && !$this->connection->pdo()->inTransaction()) {
                $this->connection->transaction($execute);
            } else {
                $execute();
            }
        } catch (Throwable $exception) {
            throw new SchemaException(
                "Unable to create table '{$table}' on connection '{$this->connection->name()}'.",
                0,
                $exception
            );
        }
    }

    public function drop(string $table): void
    {
        $this->executeDrop($table, false);
    }

    public function dropIfExists(string $table): void
    {
        $this->executeDrop($table, true);
    }

    public function hasTable(string $table): bool
    {
        try {
            Table::name($table);
        } catch (InvalidIdentifierException $exception) {
            throw new SchemaException('Unable to inspect table: an identifier is invalid.', 0, $exception);
        } catch (SchemaException $exception) {
            throw new SchemaException('Unable to inspect table: ' . $exception->getMessage(), 0, $exception);
        }
        $driver = $this->connection->driver();
        $query = match ($driver) {
            'sqlite' => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
            'mysql' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? AND table_type = ? LIMIT 1',
            default => throw new SchemaException("Schema inspection is unsupported for driver '{$driver}'."),
        };
        $bindings = $driver === 'mysql' ? [$table, 'BASE TABLE'] : [$table];
        try {
            return $this->connection->raw($query, $bindings)->fetchColumn() !== false;
        } catch (Throwable $exception) {
            throw new SchemaException(
                "Unable to inspect table '{$table}' on connection '{$this->connection->name()}'.",
                0,
                $exception
            );
        }
    }

    public function hasColumn(string $table, string $column): bool
    {
        try {
            Table::name($table);
            Table::name($column);
        } catch (InvalidIdentifierException $exception) {
            throw new SchemaException('Unable to inspect table column: an identifier is invalid.', 0, $exception);
        } catch (SchemaException $exception) {
            throw new SchemaException('Unable to inspect table column: ' . $exception->getMessage(), 0, $exception);
        }
        try {
            if ($this->connection->driver() === 'sqlite') {
                // PRAGMA does not accept a value placeholder for its table identifier.
                $rows = $this->connection->raw('PRAGMA table_info(' . Sql::name($table) . ')')->fetchAll();
                foreach ($rows as $row) {
                    if (strcasecmp((string) $row['name'], $column) === 0) {
                        return true;
                    }
                }
                return false;
            }
            if ($this->connection->driver() === 'mysql') {
                return $this->connection->raw(
                    'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1',
                    [$table, $column]
                )->fetchColumn() !== false;
            }
            throw new SchemaException("Schema inspection is unsupported for driver '{$this->connection->driver()}'.");
        } catch (SchemaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SchemaException(
                "Unable to inspect column '{$column}' on table '{$table}' using connection '{$this->connection->name()}'.",
                0,
                $exception
            );
        }
    }

    /**
     * Inspect physical indexes without relying on PDO's associative-key casing.
     * The result includes backend-created indexes so callers can distinguish a
     * declared index from a primary/constraint index before changing schema.
     *
     * @return list<array{name:string, columns:list<string>, unique:bool, primary:bool}>
     */
    public function indexes(string $table): array
    {
        try {
            Table::name($table);
            if (!$this->hasTable($table)) {
                throw new SchemaException("Table '{$table}' does not exist.");
            }
            $indexes = [];
            if ($this->connection->driver() === 'sqlite') {
                $rows = $this->connection->raw('PRAGMA index_list(' . Sql::name($table) . ')')
                    ->fetchAll(PDO::FETCH_NUM);
                foreach ($rows as $row) {
                    $name = (string) $row[1];
                    // SQLite creates autoindex names from table names. Those
                    // derived names can exceed the portable 64-byte schema
                    // declaration limit, but are still safe to inspect.
                    Identifier::simple($name);
                    $columns = $this->connection->raw('PRAGMA index_info(`' . $name . '`)')
                        ->fetchAll(PDO::FETCH_NUM);
                    $indexes[] = [
                        'name' => $name,
                        'columns' => array_map(static fn (array $column): string => (string) $column[2], $columns),
                        'unique' => (int) $row[2] === 1,
                        'primary' => ($row[3] ?? null) === 'pk',
                    ];
                }
                return $indexes;
            }
            if ($this->connection->driver() !== 'mysql') {
                throw new SchemaException('Index inspection is unsupported for this database driver.');
            }
            $rows = $this->connection->raw(
                'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX '
                    . 'FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? '
                    . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                [$table]
            )->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $row) {
                $name = (string) $row[0];
                $indexes[$name] ??= [
                    'name' => $name, 'columns' => [],
                    'unique' => (int) $row[2] === 0, 'primary' => strcasecmp($name, 'PRIMARY') === 0,
                ];
                $indexes[$name]['columns'][] = (string) $row[1];
            }
            return array_values($indexes);
        } catch (SchemaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SchemaException('Unable to inspect table indexes.', 0, $exception);
        }
    }

    public function hasIndex(string $table, string $name): bool
    {
        Table::name($name);
        foreach ($this->indexes($table) as $index) {
            if (strcasecmp($index['name'], $name) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Drop one explicitly named non-primary index; SQLite autoindexes remain protected. */
    public function dropIndex(string $table, string $name): void
    {
        try {
            Table::name($table);
            Table::name($name);
            $index = null;
            foreach ($this->indexes($table) as $candidate) {
                if (strcasecmp($candidate['name'], $name) === 0) {
                    $index = $candidate;
                    break;
                }
            }
            if ($index === null || $index['primary'] || str_starts_with($name, 'sqlite_autoindex_')) {
                throw new SchemaException('Only an existing non-primary named index can be dropped.');
            }
            $sql = $this->connection->driver() === 'sqlite'
                ? 'DROP INDEX ' . Sql::name($name)
                : 'ALTER TABLE ' . Sql::name($table) . ' DROP INDEX ' . Sql::name($name);
            $this->connection->raw($sql);
        } catch (SchemaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SchemaException('Unable to drop table index.', 0, $exception);
        }
    }

    /**
     * Read foreign-key edges as database metadata. SQLite does not retain the
     * constraint name in its PRAGMA result, so its `name` value is null.
     *
     * @return list<array{name:?string, columns:list<string>, referenced_table:string, referenced_columns:list<string>, on_update:string, on_delete:string}>
     */
    public function foreignKeys(string $table): array
    {
        try {
            Table::name($table);
            if (!$this->hasTable($table)) {
                throw new SchemaException("Table '{$table}' does not exist.");
            }
            $keys = [];
            if ($this->connection->driver() === 'sqlite') {
                $rows = $this->connection->raw('PRAGMA foreign_key_list(' . Sql::name($table) . ')')
                    ->fetchAll(PDO::FETCH_NUM);
                foreach ($rows as $row) {
                    $id = (int) $row[0];
                    $keys[$id] ??= [
                        'name' => null, 'columns' => [], 'referenced_table' => (string) $row[2],
                        'referenced_columns' => [], 'on_update' => (string) $row[5],
                        'on_delete' => (string) $row[6],
                    ];
                    $keys[$id]['columns'][] = (string) $row[3];
                    $keys[$id]['referenced_columns'][] = (string) $row[4];
                }
                return array_values($keys);
            }
            if ($this->connection->driver() !== 'mysql') {
                throw new SchemaException('Foreign-key inspection is unsupported for this database driver.');
            }
            $rows = $this->connection->raw(
                'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, '
                    . 'k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE, k.ORDINAL_POSITION '
                    . 'FROM information_schema.KEY_COLUMN_USAGE k '
                    . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                    . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME '
                    . 'AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
                    . 'WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? '
                    . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL '
                    . 'ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
                [$table]
            )->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $row) {
                $name = (string) $row[0];
                $keys[$name] ??= [
                    'name' => $name, 'columns' => [], 'referenced_table' => (string) $row[2],
                    'referenced_columns' => [], 'on_update' => (string) $row[4],
                    'on_delete' => (string) $row[5],
                ];
                $keys[$name]['columns'][] = (string) $row[1];
                $keys[$name]['referenced_columns'][] = (string) $row[3];
            }
            return array_values($keys);
        } catch (SchemaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SchemaException('Unable to inspect foreign keys.', 0, $exception);
        }
    }

    /** MySQL supports named FK removal; SQLite requires an explicit table rebuild. */
    public function dropForeignKey(string $table, string $name): void
    {
        try {
            Table::name($table);
            Table::name($name);
            if ($this->connection->driver() !== 'mysql') {
                throw new SchemaException('Dropping a foreign key is unsupported by SQLite without a table rebuild.');
            }
            $found = false;
            foreach ($this->foreignKeys($table) as $key) {
                if ($key['name'] !== null && strcasecmp($key['name'], $name) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new SchemaException('The named foreign key does not exist.');
            }
            $this->connection->raw('ALTER TABLE ' . Sql::name($table)
                . ' DROP FOREIGN KEY ' . Sql::name($name));
        } catch (SchemaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SchemaException('Unable to drop foreign key.', 0, $exception);
        }
    }

    private function executeDrop(string $table, bool $ifExists): void
    {
        try {
            $statement = $this->compiler()->compileDrop($table, $ifExists);
        } catch (InvalidIdentifierException $exception) {
            throw new SchemaException('Unable to drop table: an identifier is invalid.', 0, $exception);
        } catch (SchemaException $exception) {
            throw new SchemaException('Unable to drop table: ' . $exception->getMessage(), 0, $exception);
        }
        try {
            $this->connection->raw($statement);
        } catch (Throwable $exception) {
            throw new SchemaException(
                "Unable to drop table '{$table}' on connection '{$this->connection->name()}'.",
                0,
                $exception
            );
        }
    }

    private function compiler(): SchemaCompiler
    {
        return match ($this->connection->driver()) {
            'sqlite' => new SqliteCompiler(),
            'mysql' => new MysqlCompiler(),
            default => throw new SchemaException(
                "Schema operations are unsupported for driver '{$this->connection->driver()}'."
            ),
        };
    }
}
