<?php

declare(strict_types=1);

namespace SqueHub\Tests\Fixtures;

use App\Queue\QueueJob;
use RuntimeException;

/**
 * A persisted CLI worker fixture shared by the enqueue and worker processes.
 * The marker forces one safe failure before the test retries the same job.
 */
final class CliRetryJob implements QueueJob
{
    public function __construct(private string $root)
    {
    }

    public function handle(): void
    {
        if (is_file($this->root . '/fail.marker')) {
            throw new RuntimeException('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK');
        }

        file_put_contents($this->root . '/done.marker', 'done');
    }

    public function toQueuePayload(): array
    {
        return ['root' => $this->root];
    }

    public static function fromQueuePayload(array $payload): static
    {
        return new static($payload['root']);
    }
}
