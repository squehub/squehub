<?php

declare(strict_types=1);

namespace App\Logging;

use DateTimeImmutable;

/** Immutable normalized record shared by file and in-memory sinks. */
final readonly class LogRecord
{
    /** @param array<string|int, mixed> $context */
    public function __construct(
        public DateTimeImmutable $timestamp,
        public string $level,
        public string $message,
        public array $context
    ) {
    }

    /** @return array{timestamp:string,level:string,message:string,context:array} */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp->format(DATE_ATOM),
            'level' => strtoupper($this->level),
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
