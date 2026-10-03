<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Logging\Log as LoggingGateway;
use App\Logging\Logger;

/** PSR-3 convenience backed by the current configured Logger and redaction. */
final class Log
{
    public static function logger(): Logger { return LoggingGateway::logger(); }
    public static function log(mixed $level, mixed $message, array $context = []): void
    {
        self::logger()->log($level, $message, $context);
    }
    public static function debug(mixed $message, array $context = []): void { self::log('debug', $message, $context); }
    public static function info(mixed $message, array $context = []): void { self::log('info', $message, $context); }
    public static function notice(mixed $message, array $context = []): void { self::log('notice', $message, $context); }
    public static function warning(mixed $message, array $context = []): void { self::log('warning', $message, $context); }
    public static function error(mixed $message, array $context = []): void { self::log('error', $message, $context); }
    public static function critical(mixed $message, array $context = []): void { self::log('critical', $message, $context); }
    public static function alert(mixed $message, array $context = []): void { self::log('alert', $message, $context); }
    public static function emergency(mixed $message, array $context = []): void { self::log('emergency', $message, $context); }
}
