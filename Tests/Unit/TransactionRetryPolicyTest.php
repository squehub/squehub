<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Database\Exception\QueryException;
use App\Database\TransactionRetryPolicy;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransactionRetryPolicyTest extends TestCase
{
    public function testOnlyDriverReportedConflictsAreRetryable(): void
    {
        self::assertTrue(TransactionRetryPolicy::retryable(self::failure('40001', 1213), 'mysql'));
        self::assertTrue(TransactionRetryPolicy::retryable(self::failure('HY000', 1205), 'mysql'));
        self::assertTrue(TransactionRetryPolicy::retryable(self::failure('40001', 0), 'mysql'));
        self::assertTrue(TransactionRetryPolicy::retryable(self::failure('HY000', 5), 'sqlite'));
        self::assertTrue(TransactionRetryPolicy::retryable(self::failure('HY000', 517), 'sqlite'));
        self::assertTrue(TransactionRetryPolicy::retryable(self::failure('HY000', 6), 'sqlite'));
        self::assertFalse(TransactionRetryPolicy::retryable(self::failure('23000', 1062), 'mysql'));
        self::assertFalse(TransactionRetryPolicy::retryable(self::failure('HY000', 999), 'sqlite'));
        self::assertFalse(TransactionRetryPolicy::retryable(self::failure('HY000', 5), 'mysql'));
    }

    public function testOnlyDirectPdoOrFrameworkQueryFailureCanTriggerRetry(): void
    {
        $pdo = self::failure('HY000', 5);
        self::assertTrue(TransactionRetryPolicy::retryable(new QueryException('Database query failed.', 0, $pdo), 'sqlite'));
        self::assertFalse(TransactionRetryPolicy::retryable(new RuntimeException('Application failure', 0, $pdo), 'sqlite'));
        self::assertFalse(TransactionRetryPolicy::retryable(new QueryException('Application failure'), 'sqlite'));
    }

    private static function failure(string $state, int $number): PDOException
    {
        $failure = new PDOException('private driver diagnostic');
        $failure->errorInfo = [$state, $number, 'private driver diagnostic'];
        return $failure;
    }
}
