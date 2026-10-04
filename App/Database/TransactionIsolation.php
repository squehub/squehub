<?php

declare(strict_types=1);

namespace App\Database;

/** Explicit isolation levels accepted by MySQL transactions. */
enum TransactionIsolation: string
{
    case READ_UNCOMMITTED = 'READ UNCOMMITTED';
    case READ_COMMITTED = 'READ COMMITTED';
    case REPEATABLE_READ = 'REPEATABLE READ';
    case SERIALIZABLE = 'SERIALIZABLE';
}
