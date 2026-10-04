<?php

declare(strict_types=1);

namespace SqueHub\Tests\Fixtures;

use App\Queue\QueueJob;

/** A process-boundary job whose test-only file shows each actual execution. */
final class RedisQueueLiveJob implements QueueJob
{
    public function __construct(private string $path) {}
    public function handle(): void
    {
        file_put_contents($this->path, "handled\n", FILE_APPEND | LOCK_EX);
    }
    public function toQueuePayload(): array { return ['path' => $this->path]; }
    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) $payload['path']);
    }
}
