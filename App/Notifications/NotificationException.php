<?php

declare(strict_types=1);

namespace App\Notifications;

use RuntimeException;
use Throwable;

/**
 * Notification failures exposed to callers. Only framework-authored messages
 * may pass through dispatch unchanged; application exceptions are sanitized.
 */
class NotificationException extends RuntimeException
{
    private bool $frameworkMessage = false;

    /** @internal For fixed framework messages that contain no recipient or payload data. */
    public static function framework(string $message, ?Throwable $previous = null): self
    {
        $exception = new self($message, 0, $previous);
        $exception->frameworkMessage = true;
        return $exception;
    }

    public function hasFrameworkMessage(): bool { return $this->frameworkMessage; }
}
