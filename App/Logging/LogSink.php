<?php

declare(strict_types=1);

namespace App\Logging;

/** One record destination; filtering and redaction happen before this boundary. */
interface LogSink
{
    public function write(LogRecord $record): void;
}
