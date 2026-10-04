<?php

declare(strict_types=1);

namespace App\Logging;

use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\Diagnostics\Diagnostics;
use DateTimeZone;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * Application-owned PSR-3 logger with one configured sink and threshold.
 *
 * Diagnostic metadata is framework-owned; developer context stays under
 * `data`, so a supplied request_id cannot impersonate the correlation ID.
 */
final class Logger implements LoggerInterface
{
    private const RANKS = [
        'debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3,
        'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7,
    ];

    private ModelClock $clock;

    public function __construct(
        private LogSink $sink,
        private LogContextNormalizer $normalizer,
        private string $minimumLevel = 'info',
        private bool $failFast = false,
        private ?Diagnostics $diagnostics = null,
        ?ModelClock $clock = null
    ) {
        self::rank($minimumLevel);
        $this->clock = $clock ?? new SystemModelClock();
    }

    public function emergency($message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert($message, array $context = []): void { $this->log('alert', $message, $context); }
    public function critical($message, array $context = []): void { $this->log('critical', $message, $context); }
    public function error($message, array $context = []): void { $this->log('error', $message, $context); }
    public function warning($message, array $context = []): void { $this->log('warning', $message, $context); }
    public function notice($message, array $context = []): void { $this->log('notice', $message, $context); }
    public function info($message, array $context = []): void { $this->log('info', $message, $context); }
    public function debug($message, array $context = []): void { $this->log('debug', $message, $context); }

    /** Unknown levels fail before filtering; backend failures follow fail_fast. */
    public function log($level, $message, array $context = []): void
    {
        if (!is_string($level)) throw new InvalidArgumentException('Log level must be a string.');
        if (self::rank($level) < self::rank($this->minimumLevel)) return;
        if (!is_string($message) && !$message instanceof Stringable) {
            throw new InvalidArgumentException('Log message must be a string or Stringable.');
        }
        try {
            $text = $this->normalizer->message((string) $message, $context);
            $data = $this->normalizer->normalize($context);
            $metadata = $this->diagnostics?->logContext() ?? [];
            $record = new LogRecord($this->clock->now()->setTimezone(new DateTimeZone('UTC')),
                $level, $text, [...$metadata, 'data' => $data]);
            $this->sink->write($record);
        } catch (Throwable $failure) {
            if ($this->failFast) {
                throw new LoggingException('Log operation failed.', 0, $failure);
            }
            // This independent fallback cannot recurse into SqueHub logging.
            // Neither raw context nor exception messages reach PHP's error log.
            @error_log('SqueHub logger failure: ' . $failure::class);
        }
    }

    private static function rank(string $level): int
    {
        if (!array_key_exists($level, self::RANKS)) {
            throw new InvalidArgumentException("Unknown log level '{$level}'.");
        }
        return self::RANKS[$level];
    }
}
