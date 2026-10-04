<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Exception\QueryException;
use PDOException;
use Throwable;

/** Classifies driver-reported transaction conflicts without reading SQL text. */
final class TransactionRetryPolicy
{
    public const MAX_ATTEMPTS = 5;

    public static function retryable(Throwable $failure, string $driver): bool
    {
        $pdo = $failure instanceof PDOException ? $failure
            : ($failure instanceof QueryException && $failure->getPrevious() instanceof PDOException
                ? $failure->getPrevious() : null);
        if ($pdo === null) {
            return false;
        }

        $info = $pdo->errorInfo ?? [];
        $state = is_string($info[0] ?? null) ? $info[0] : (string) $pdo->getCode();
        $number = $info[1] ?? null;
        $code = is_int($number) || (is_string($number) && preg_match('/\A[0-9]+\z/D', $number) === 1)
            ? (int) $number : null;

        if ($driver === 'mysql') {
            return $state === '40001' || in_array($code, [1205, 1213], true);
        }
        if ($driver === 'sqlite') {
            // SQLite may report an extended result code; its low byte is BUSY or LOCKED.
            return $code !== null && in_array($code & 0xff, [5, 6], true);
        }
        return false;
    }

    /** Bounded, deterministic backoff before the next outer attempt. */
    public static function pause(int $attempt): void
    {
        usleep(min(200_000, 10_000 * (1 << min($attempt - 1, 4))));
    }
}
