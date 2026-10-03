<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\Connection;
use App\Database\DatabaseManager;
use App\Database\Exception\DatabaseException;
use App\Database\Exception\QueryException;
use App\Database\TransactionIsolation;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransactionConcurrencyTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        $this->manager = self::manager();
    }

    public function testNestedSavepointsAndAfterCommitCallbacksKeepOuterOrder(): void
    {
        $connection = $this->manager->connection();
        $connection->raw('CREATE TABLE records (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $delivered = [];
        $connection->transaction(function (Connection $connection) use (&$delivered): void {
            $connection->raw('INSERT INTO records (id, label) VALUES (1, ?)', ['outer']);
            $connection->afterCommit(static function () use (&$delivered): void { $delivered[] = 'outer'; });
            $connection->transaction(function (Connection $connection) use (&$delivered): void {
                $connection->raw('INSERT INTO records (id, label) VALUES (2, ?)', ['inner']);
                $connection->afterCommit(static function () use (&$delivered): void { $delivered[] = 'inner'; });
            });
            try {
                $connection->transaction(function (Connection $connection) use (&$delivered): void {
                    $connection->raw('INSERT INTO records (id, label) VALUES (3, ?)', ['discard']);
                    $connection->afterCommit(static function () use (&$delivered): void { $delivered[] = 'discard'; });
                    throw new RuntimeException('rollback this savepoint');
                });
                self::fail('Nested failure did not escape.');
            } catch (RuntimeException $failure) {
                self::assertSame('rollback this savepoint', $failure->getMessage());
            }
            self::assertSame([], $delivered);
        });
        self::assertSame(['outer', 'inner'], $delivered);
        self::assertSame(2, (int) $connection->raw('SELECT COUNT(*) FROM records')->fetchColumn());
        self::assertFalse($connection->inTransaction());
    }

    public function testRecognizedSqliteBusyRetriesWholeOuterCallbackAndDiscardsFirstAttempt(): void
    {
        $connection = $this->manager->connection();
        $connection->raw('CREATE TABLE records (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $calls = 0;
        $delivered = [];
        $result = $connection->transaction(function (Connection $connection) use (&$calls, &$delivered): string {
            ++$calls;
            $connection->raw('INSERT INTO records (id, label) VALUES (1, ?)', ['one']);
            $attempt = $calls;
            $connection->afterCommit(static function () use (&$delivered, $attempt): void {
                $delivered[] = $attempt;
            });
            if ($calls === 1) throw self::busy();
            return 'committed';
        }, attempts: 2);
        self::assertSame('committed', $result);
        self::assertSame(2, $calls);
        self::assertSame([2], $delivered);
        self::assertSame(1, (int) $connection->raw('SELECT COUNT(*) FROM records')->fetchColumn());
        self::assertFalse($connection->inTransaction());
    }

    public function testActualSqliteWriteLockRetriesAfterTheOtherConnectionReleasesIt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'squehub_tx_');
        self::assertNotFalse($path);
        $first = self::fileManager($path)->connection();
        $second = self::fileManager($path)->connection();
        try {
            $first->raw('CREATE TABLE records (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
            $second->raw('PRAGMA busy_timeout = 0');
            $first->begin();
            $first->raw('INSERT INTO records (id, label) VALUES (1, ?)', ['first']);

            $calls = 0;
            $second->transaction(function (Connection $connection) use (&$calls, $first): void {
                ++$calls;
                try {
                    $connection->raw('INSERT INTO records (id, label) VALUES (2, ?)', ['second']);
                } catch (QueryException $failure) {
                    $driverFailure = $failure->getPrevious();
                    self::assertInstanceOf(PDOException::class, $driverFailure);
                    self::assertSame(5, $driverFailure->errorInfo[1] ?? null);
                    $first->commit();
                    throw $failure;
                }
            }, attempts: 2);

            self::assertSame(2, $calls);
            self::assertSame(2, (int) $second->raw('SELECT COUNT(*) FROM records')->fetchColumn());
            self::assertFalse($second->inTransaction());
        } finally {
            if ($first->inTransaction()) $first->rollback();
            $first->disconnect();
            $second->disconnect();
            unlink($path);
        }
    }

    public function testApplicationExceptionWithPdoCauseNeverRetries(): void
    {
        $connection = $this->manager->connection();
        $connection->raw('CREATE TABLE records (id INTEGER PRIMARY KEY)');
        $calls = 0;
        $failure = new RuntimeException('application failed', 0, self::busy());
        try {
            $connection->transaction(function (Connection $connection) use (&$calls, $failure): void {
                ++$calls;
                $connection->raw('INSERT INTO records (id) VALUES (1)');
                throw $failure;
            }, attempts: 3);
            self::fail('Application exception did not escape.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $calls);
        self::assertSame(0, (int) $connection->raw('SELECT COUNT(*) FROM records')->fetchColumn());
        self::assertFalse($connection->inTransaction());
    }

    public function testRetryIsBoundedAndPostCommitFailureIsNeverRetried(): void
    {
        $connection = $this->manager->connection();
        $calls = 0;
        try {
            $connection->transaction(function () use (&$calls): void {
                ++$calls;
                throw self::busy();
            }, attempts: 3);
            self::fail('Exhausted busy attempts should fail.');
        } catch (PDOException $failure) {
            self::assertSame(5, $failure->errorInfo[1]);
        }
        self::assertSame(3, $calls);
        self::assertFalse($connection->inTransaction());

        $connection->raw('CREATE TABLE records (id INTEGER PRIMARY KEY)');
        $calls = 0;
        $postCommitFailure = new QueryException('Post-commit delivery failed.', 0, self::busy());
        try {
            $connection->transaction(function (Connection $connection) use (&$calls, $postCommitFailure): void {
                ++$calls;
                $connection->raw('INSERT INTO records (id) VALUES (1)');
                $connection->afterCommit(static function () use ($postCommitFailure): void {
                    throw $postCommitFailure;
                });
            }, attempts: 3);
            self::fail('Post-commit failure did not escape.');
        } catch (QueryException $caught) {
            self::assertSame($postCommitFailure, $caught);
        }
        self::assertSame(1, $calls);
        self::assertSame(1, (int) $connection->raw('SELECT COUNT(*) FROM records')->fetchColumn());
        self::assertFalse($connection->inTransaction());
    }

    public function testDriverEndedTransactionClearsCallbacksAndPreservesOriginalFailure(): void
    {
        $connection = $this->manager->connection();
        $delivered = false;
        $failure = new RuntimeException('original failure');
        try {
            $connection->transaction(function (Connection $connection) use (&$delivered, $failure): void {
                $connection->afterCommit(static function () use (&$delivered): void { $delivered = true; });
                $connection->pdo()->rollBack(); // Simulate a driver-ended transaction.
                throw $failure;
            });
            self::fail('Original failure did not escape.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertFalse($connection->inTransaction());
        self::assertFalse($delivered);
        $connection->transaction(static function (Connection $connection): void {
            self::assertTrue($connection->inTransaction());
        });

        $connection->begin();
        $connection->pdo()->rollBack();
        try {
            $connection->transaction(static fn (): null => null, attempts: 2);
            self::fail('The lost managed transaction should be reported before retry policy checks.');
        } catch (DatabaseException $caught) {
            self::assertStringContainsString('ended outside', $caught->getMessage());
        }
        self::assertFalse($connection->inTransaction());
    }

    public function testExternallyCommittedTransactionIsNeverRetriedAfterRetryableFailure(): void
    {
        $connection = $this->manager->connection();
        $connection->raw('CREATE TABLE records (id INTEGER PRIMARY KEY)');
        $calls = 0;
        $delivered = false;
        $failure = self::busy();

        try {
            $connection->transaction(function (Connection $connection) use (&$calls, &$delivered, $failure): void {
                ++$calls;
                $connection->raw('INSERT INTO records (id) VALUES (?)', [$calls]);
                $connection->afterCommit(static function () use (&$delivered): void { $delivered = true; });
                $connection->pdo()->commit();
                throw $failure;
            }, attempts: 2);
            self::fail('The retryable failure after an external commit did not escape.');
        } catch (PDOException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(1, $calls);
        self::assertSame(1, (int) $connection->raw('SELECT COUNT(*) FROM records')->fetchColumn());
        self::assertFalse($connection->inTransaction());
        self::assertFalse($delivered);
        $connection->transaction(static function (Connection $connection): void {
            self::assertTrue($connection->inTransaction());
        });
        self::assertFalse($delivered);
    }

    public function testNestedRetriesAndExplicitSqliteIsolationFailClearly(): void
    {
        $connection = $this->manager->connection();
        try {
            $connection->transaction(static fn (): null => null, isolation: TransactionIsolation::SERIALIZABLE);
            self::fail('SQLite isolation should be rejected.');
        } catch (DatabaseException $failure) {
            self::assertStringContainsString('only for MySQL', $failure->getMessage());
            self::assertFalse($connection->isConnected());
        }
        $connection->begin();
        try {
            $connection->transaction(static fn (): null => null, attempts: 2);
            self::fail('A nested retry should be rejected.');
        } catch (DatabaseException $failure) {
            self::assertStringContainsString('outermost', $failure->getMessage());
            self::assertTrue($connection->inTransaction());
        } finally {
            $connection->rollback();
        }
    }

    public function testManagerKeepsObserverAndMorphRegistriesApplicationScoped(): void
    {
        $main = $this->manager->connection();
        $audit = $this->manager->connection('audit');
        self::assertSame($this->manager, $main->owner());
        self::assertSame($this->manager, $audit->owner());
        self::assertSame($this->manager->modelObservers(), $main->modelObservers());
        self::assertSame($main->modelObservers(), $audit->modelObservers());
        self::assertSame($this->manager->morphMap(), $this->manager->morphMap());
        $other = self::manager();
        self::assertNotSame($this->manager->modelObservers(), $other->modelObservers());
        self::assertNotSame($this->manager->morphMap(), $other->morphMap());

        $orphan = (static function (): Connection {
            $temporary = self::manager();
            return $temporary->connection();
        })();
        self::assertNull($orphan->owner());
    }

    private static function manager(): DatabaseManager
    {
        return new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'audit' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]]));
    }

    private static function fileManager(string $path): DatabaseManager
    {
        return new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => $path]],
        ]]));
    }

    private static function busy(): PDOException
    {
        $failure = new PDOException('simulated SQLite busy');
        $failure->errorInfo = ['HY000', 5, 'simulated SQLite busy'];
        return $failure;
    }
}
