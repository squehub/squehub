<?php

declare(strict_types=1);

namespace App\Logging\Drivers;

use App\Logging\LogRecord;
use App\Logging\LogSink;

/** Deterministic in-memory destination for tests and controlled applications. */
final class ArrayLogger implements LogSink
{
    /** @var list<LogRecord> */
    private array $records = [];

    public function write(LogRecord $record): void
    {
        $this->records[] = $record;
    }

    /** @return list<LogRecord> */
    public function records(): array
    {
        return $this->records;
    }
}
