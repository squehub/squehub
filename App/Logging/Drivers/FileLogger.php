<?php

declare(strict_types=1);

namespace App\Logging\Drivers;

use App\Logging\LoggingException;
use App\Logging\LogRecord;
use App\Logging\LogSink;
use JsonException;

/** Appends one JSON record under a short exclusive file lock. */
final class FileLogger implements LogSink
{
    public function __construct(private string $path)
    {
    }

    public function write(LogRecord $record): void
    {
        try {
            $line = json_encode($record->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        } catch (JsonException $exception) {
            throw new LoggingException('Unable to encode log record.', 0, $exception);
        }

        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new LoggingException('Unable to create log directory.');
        }
        $handle = @fopen($this->path, 'ab');
        if ($handle === false) throw new LoggingException('Unable to open log file.');
        try {
            if (!flock($handle, LOCK_EX)) throw new LoggingException('Unable to lock log file.');
            try {
                $length = strlen($line);
                for ($written = 0; $written < $length; $written += $count) {
                    $count = fwrite($handle, substr($line, $written));
                    if ($count === false || $count === 0) throw new LoggingException('Unable to write log record.');
                }
                if (!fflush($handle)) throw new LoggingException('Unable to flush log record.');
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
