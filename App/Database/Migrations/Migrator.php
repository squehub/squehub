<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Clis\FileLookup;
use App\Database\DatabaseManager;
use App\Database\Schema\Schema;
use PDO;
use PDOException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/** Executes existing PDO-based migration classes in deterministic file order. */
final class Migrator
{
    public function __construct(
        private DatabaseManager $databases,
        private string $basePath,
        private ?string $connection = null
    ) {
    }

    /** @return list<string> */
    public function files(): array
    {
        return $this->discoverFiles(true);
    }

    /** @return list<string> */
    private function discoverFiles(bool $requireDirectory): array
    {
        $directories = [];
        foreach (['Database', 'database'] as $root) {
            foreach (['Migrations', 'migrations'] as $child) {
                $directory = realpath($this->basePath . DIRECTORY_SEPARATOR . $root . DIRECTORY_SEPARATOR . $child);
                if ($directory !== false && is_dir($directory) && !in_array($directory, $directories, true)) {
                    $directories[] = $directory;
                }
            }
        }
        if ($requireDirectory && $directories === []) {
            throw new MigrationException('Database/Migrations directory not found.');
        }

        $files = [];
        foreach ($directories as $directory) {
            $entries = scandir($directory);
            if ($entries === false) {
                throw new MigrationException('Database/Migrations directory cannot be read.');
            }
            foreach ($entries as $file) {
                if (is_file($directory . DIRECTORY_SEPARATOR . $file) && str_ends_with($file, '.php')) {
                    $files[] = $file;
                }
            }
        }
        sort($files, SORT_STRING);

        // Filename casing compatibility must not make two physical files one migration.
        $classes = [];
        foreach ($files as $index => $file) {
            foreach (array_slice($files, 0, $index) as $earlier) {
                if (FileLookup::sameFirstLetter($file, $earlier)) {
                    throw new MigrationException("Duplicate migration identity: '{$earlier}' and '{$file}'.");
                }
            }
            $class = strtolower($this->className($file));
            if (isset($classes[$class])) {
                throw new MigrationException("Duplicate migration class '{$this->className($file)}' in '{$classes[$class]}' and '{$file}'.");
            }
            $classes[$class] = $file;
        }
        return $files;
    }

    /** @return list<string> */
    public function pending(): array
    {
        return $this->pendingFiles($this->files());
    }

    /** @param list<string> $files @return list<string> */
    private function pendingFiles(array $files): array
    {
        $repository = $this->repository();
        $repository->ensure();
        $completed = array_column($this->history($repository), 'migration');

        return array_values(array_filter($files, static function (string $file) use ($completed): bool {
            foreach ($completed as $previous) {
                if (FileLookup::sameFirstLetter($file, $previous)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** @return list<array{migration: string, state: string, batch: ?int}> */
    public function status(): array
    {
        $files = $this->discoverFiles(false);
        $repository = $this->repository();
        $repository->ensure();
        $history = $this->history($repository);
        $status = [];

        foreach ($files as $file) {
            $batch = null;
            foreach ($history as $row) {
                if (FileLookup::sameFirstLetter($file, $row['migration'])) {
                    $batch = $row['batch'];
                    break;
                }
            }
            $status[] = ['migration' => $file, 'state' => $batch === null ? 'pending' : 'applied', 'batch' => $batch];
        }
        foreach ($history as $row) {
            $found = false;
            foreach ($files as $file) {
                if (FileLookup::sameFirstLetter($file, $row['migration'])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $status[] = ['migration' => $row['migration'], 'state' => 'missing', 'batch' => $row['batch']];
            }
        }
        return $status;
    }

    /** @return list<string> Applied migration file names. */
    public function run(): array
    {
        $files = $this->files();
        $this->assertNoTransaction();
        return $this->withRunLock(function () use ($files): array {
            // Read history only after acquiring the deployment lock. A previous
            // runner may have completed while this runner waited to start.
            $pending = $this->pendingFiles($files);
            if ($pending === []) {
                return [];
            }
            $repository = $this->repository();
            $batch = $repository->nextBatch();
            foreach ($pending as $name) {
                $migration = $this->load($name, 'up');
                $this->perform($name, 'up', $migration, static function () use ($repository, $name, $batch): void {
                    $repository->record($name, $batch);
                });
            }
            return $pending;
        });
    }

    /** @return list<string> Rolled back migration file names. */
    public function rollback(): array
    {
        $this->assertNoTransaction();
        return $this->withRunLock(function (): array {
            $repository = $this->repository();
            $repository->ensure();
            $history = $this->history($repository);
            if ($history === []) {
                return [];
            }
            $latestBatch = max(array_column($history, 'batch'));
            $latest = array_reverse(array_values(array_filter(
                $history,
                static fn (array $row): bool => $row['batch'] === $latestBatch
            )));
            return $this->down($latest, $repository);
        });
    }

    /** @return list<string> Rolled back migration file names. */
    public function reset(): array
    {
        $this->assertNoTransaction();
        return $this->withRunLock(function (): array {
            $repository = $this->repository();
            $repository->ensure();
            return $this->down(array_reverse($this->history($repository)), $repository);
        });
    }

    /** @param callable():list<string> $work @return list<string> */
    private function withRunLock(callable $work): array
    {
        $pdo = $this->pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return $this->withSqliteRunLock($pdo, $work);
        }
        if ($driver === 'mysql') {
            return $this->withMysqlRunLock($pdo, $work);
        }
        throw new MigrationException('Migration locking supports MySQL and SQLite connections.');
    }

    /** @param callable():list<string> $work @return list<string> */
    private function withSqliteRunLock(PDO $pdo, callable $work): array
    {
        try {
            $databases = $pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $failure) {
            throw new MigrationException('Unable to identify the SQLite migration database.', 0, $failure);
        }
        $path = '';
        foreach ($databases as $database) {
            if (($database['name'] ?? null) === 'main') {
                $path = (string) ($database['file'] ?? '');
                break;
            }
        }
        if ($path === '') {
            // An in-memory database has no shared deployment file to guard.
            return $work();
        }
        $canonical = realpath($path);
        if ($canonical === false) {
            throw new MigrationException('Unable to identify the SQLite migration database.');
        }
        $handle = @fopen($canonical . '.squehub-migrations.lock', 'c+b');
        if ($handle === false) {
            throw new MigrationException('Unable to open the SQLite migration deployment lock.');
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new MigrationException('Another migration runner is active or SQLite deployment locking is unavailable.');
        }
        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param callable():list<string> $work @return list<string> */
    private function withMysqlRunLock(PDO $pdo, callable $work): array
    {
        try {
            $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if (!is_string($database) || $database === '') {
                throw new MigrationException('Unable to identify the MySQL migration database.');
            }
            $name = 'squehub_migrations_' . substr(hash('sha256', $database), 0, 32);
            $used = $pdo->prepare('SELECT IS_USED_LOCK(?)');
            $used->execute([$name]);
            if ($used->fetchColumn() !== null) {
                throw new MigrationException('Another migration runner is active for this MySQL database.');
            }
            $acquire = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $acquire->execute([$name]);
            if ((int) $acquire->fetchColumn() !== 1) {
                throw new MigrationException('Another migration runner is active for this MySQL database.');
            }
        } catch (PDOException $failure) {
            throw new MigrationException('Unable to acquire the MySQL migration deployment lock.', 0, $failure);
        }

        $original = null;
        try {
            return $work();
        } catch (Throwable $failure) {
            $original = $failure;
            throw $failure;
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$name]);
                if ((int) $release->fetchColumn() !== 1) {
                    throw new MigrationException('Unable to release the MySQL migration deployment lock.');
                }
            } catch (Throwable $failure) {
                if ($original === null) {
                    throw new MigrationException('Unable to release the MySQL migration deployment lock.', 0, $failure);
                }
            }
        }
    }

    /** @param list<array{id: int, migration: string, batch: int}> $rows
     *  @return list<string>
     */
    private function down(array $rows, MigrationRepository $repository): array
    {
        $loaded = [];
        foreach ($rows as $row) {
            $loaded[] = [$row['migration'], $this->load($row['migration'], 'down')];
        }
        $rolledBack = [];
        foreach ($loaded as [$name, $migration]) {
            $this->perform($name, 'down', $migration, static function () use ($repository, $name): void {
                $repository->remove($name);
            });
            $rolledBack[] = $name;
        }
        return $rolledBack;
    }

    /** @param callable():void $updateHistory */
    private function perform(string $name, string $method, object $migration, callable $updateHistory): void
    {
        $connection = $this->databases->connection($this->connection);
        $pdo = $connection->pdo();
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $phase = $method;
        $savepoint = null;
        $guardIntact = true;
        $guardReleased = false;

        $this->assertMigrationTransactionIdle($pdo, $name, $connection->name());
        if ($sqlite) {
            try {
                $savepoint = 'squehub_migration_' . bin2hex(random_bytes(12));
                if (!$pdo->beginTransaction()) {
                    throw new MigrationException('Unable to begin the SQLite migration transaction.');
                }
                if ($pdo->exec('SAVEPOINT ' . $savepoint) === false) {
                    throw new MigrationException('Unable to protect the SQLite migration transaction.');
                }
            } catch (Throwable $exception) {
                $rolledBack = false;
                try {
                    if ($pdo->inTransaction()) {
                        $rolledBack = $this->rollbackMigrationTransaction($pdo);
                    }
                } catch (Throwable $rollbackException) {
                    // Preserve the first failure and report an uncertain state.
                }
                throw $this->failure($name, $connection->name(), 'begin', $exception, !$rolledBack, $rolledBack);
            }
        }

        try {
            $parameters = (new ReflectionMethod($migration, $method))->getParameters();
            $secondType = ($parameters[1] ?? null)?->getType();
            // Reflection retains the spelling of a class_alias in the method
            // signature. Resolve it before deciding whether to pass Schema.
            if ($secondType instanceof ReflectionNamedType
                && !$secondType->isBuiltin()
                && class_exists($secondType->getName())
                && (new ReflectionClass($secondType->getName()))->getName() === Schema::class) {
                $migration->{$method}($pdo, $connection->schema());
            } else {
                // Existing up(PDO) and down(PDO) signatures remain unchanged.
                $migration->{$method}($pdo);
            }
            if ($sqlite) {
                if (!$pdo->inTransaction()) {
                    $guardIntact = false;
                    throw new MigrationException('Migration ended the managed SQLite transaction.');
                }
                try {
                    // An opaque savepoint distinguishes the original transaction
                    // from a replacement begun by legacy PDO migration code.
                    if ($pdo->exec('RELEASE SAVEPOINT ' . $savepoint) === false) {
                        throw new MigrationException('SQLite migration transaction guard was lost.');
                    }
                    $guardReleased = true;
                } catch (Throwable $guardException) {
                    $guardIntact = false;
                    throw new MigrationException('Migration replaced the managed SQLite transaction.', 0, $guardException);
                }
            }
            if (!$sqlite && $pdo->inTransaction()) {
                throw new MigrationException('Migration left an active transaction.');
            }

            $phase = $method === 'up' ? 'record' : 'remove';
            $updateHistory();

            if ($sqlite) {
                $phase = 'commit';
                if (!$pdo->commit()) {
                    throw new MigrationException('Unable to commit the SQLite migration transaction.');
                }
            }
        } catch (Throwable $exception) {
            $rolledBack = false;
            if ($sqlite) {
                if (!$guardReleased) {
                    if (!$pdo->inTransaction()) {
                        $guardIntact = false;
                    } elseif ($guardIntact) {
                        try {
                            // Check the same guard when the callback itself threw.
                            $guardIntact = $pdo->exec('RELEASE SAVEPOINT ' . $savepoint) !== false;
                        } catch (Throwable $guardException) {
                            $guardIntact = false;
                        }
                    }
                }
                try {
                    if ($pdo->inTransaction()) {
                        $rolledBack = $this->rollbackMigrationTransaction($pdo);
                    }
                } catch (Throwable $rollbackException) {
                    // The original migration or history error remains the cause.
                }
            } else {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable $rollbackException) {
                    // MySQL DDL may already have committed independently.
                }
            }
            throw $this->failure(
                $name,
                $connection->name(),
                $phase,
                $exception,
                !$sqlite || !$rolledBack || !$guardIntact,
                $rolledBack
            );
        }
    }

    private function assertMigrationTransactionIdle(PDO $pdo, string $name, string $connection): void
    {
        if ($pdo->inTransaction()) {
            throw new MigrationException("Migration '{$name}' on connection '{$connection}' cannot run inside an active transaction.");
        }
    }

    private function rollbackMigrationTransaction(PDO $pdo): bool
    {
        // A successful driver rollback must also leave no live transaction;
        // otherwise the migration outcome remains uncertain to the caller.
        return $pdo->rollBack() && !$pdo->inTransaction();
    }

    private function failure(
        string $name,
        string $connection,
        string $phase,
        Throwable $cause,
        bool $uncertain,
        bool $rolledBack
    ): MigrationException {
        $message = "Migration '{$name}' on connection '{$connection}' failed during {$phase}().";
        if ($uncertain) {
            $message .= ' Database may already have changed; inspect schema and migration history before retrying.';
        } elseif ($rolledBack) {
            $message .= ' This migration\'s SQLite schema and history changes were rolled back.';
        }
        // The previous exception carries details without exposing SQL, credentials or data in CLI output.
        return new MigrationException($message, 0, $cause, $phase, $uncertain);
    }

    private function assertNoTransaction(): void
    {
        $connection = $this->databases->connection($this->connection);
        if ($connection->pdo()->inTransaction()) {
            throw new MigrationException("Migrations on connection '{$connection->name()}' cannot run inside an active transaction.");
        }
    }

    private function load(string $name, string $method): object
    {
        $path = FileLookup::migration($this->basePath, $name);
        if ($path === null) {
            throw new MigrationException("Migration file '{$name}' not found.");
        }
        $class = $this->className($name);

        try {
            require_once $path;
        } catch (Throwable $exception) {
            throw new MigrationException("Unable to load migration '{$name}'.", 0, $exception);
        }
        $resolved = null;
        foreach ([$class, 'Database\\Migrations\\' . $class] as $candidate) {
            if (class_exists($candidate)) {
                $resolved = $candidate;
                break;
            }
        }
        if ($resolved === null) {
            throw new MigrationException("Migration class '{$class}' not found in '{$name}'.");
        }
        try {
            $migration = new $resolved();
        } catch (Throwable $exception) {
            throw new MigrationException("Unable to construct migration '{$name}'.", 0, $exception);
        }
        if (!is_callable([$migration, $method])) {
            throw new MigrationException("Migration class '{$resolved}' is missing {$method}().");
        }
        return $migration;
    }

    private function className(string $name): string
    {
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = preg_replace('/\A\d{4}_\d{2}_\d{2}_(?:\d{6}_)?/', '', $stem) ?? '';
        $class = implode('', array_map('ucfirst', preg_split('/[_-]+/', $stem) ?: []));
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $class) !== 1) {
            throw new MigrationException("Cannot derive a migration class from '{$name}'.");
        }
        return $class;
    }

    private function repository(): MigrationRepository
    {
        return new MigrationRepository($this->pdo());
    }

    /** @return list<array{id: int, migration: string, batch: int}> */
    private function history(MigrationRepository $repository): array
    {
        $rows = $repository->rows();
        foreach ($rows as $index => $row) {
            foreach (array_slice($rows, 0, $index) as $earlier) {
                if (FileLookup::sameFirstLetter($row['migration'], $earlier['migration'])) {
                    throw new MigrationException(
                        "Duplicate migration history identity '{$earlier['migration']}' and '{$row['migration']}'. "
                        . 'Reconcile history manually before running migrations.'
                    );
                }
            }
        }
        return $rows;
    }

    private function pdo(): PDO
    {
        return $this->databases->connection($this->connection)->pdo();
    }
}
