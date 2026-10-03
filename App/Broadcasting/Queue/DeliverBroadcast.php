<?php

declare(strict_types=1);

namespace App\Broadcasting\Queue;

use App\Broadcasting\Broadcast;
use App\Broadcasting\BroadcastException;
use App\Broadcasting\BroadcastManager;
use App\Broadcasting\BroadcastMessage;
use App\Container\Container;
use App\Queue\QueueContextAware;
use App\Queue\QueueJob;

/**
 * A fixed framework job holds only an explicit message snapshot. Queue owns
 * retries and acknowledgement; a retry may publish the same message again.
 */
final class DeliverBroadcast implements QueueJob, QueueContextAware
{
    private ?Container $workerContainer = null;

    private function __construct(private BroadcastMessage $message,
        private ?BroadcastManager $runtimeManager = null)
    {
    }

    public static function capture(BroadcastMessage $message, BroadcastManager $manager): self
    {
        return new self($message, $manager);
    }

    public function handle(): void
    {
        $manager = $this->workerContainer?->make(BroadcastManager::class)
            ?? $this->runtimeManager ?? Broadcast::manager();
        $manager->deliver($this->message);
    }

    public function setQueueContainer(Container $container): void { $this->workerContainer = $container; }

    public function toQueuePayload(): array
    {
        return ['version' => 1, 'message' => $this->message->toArray()];
    }

    public static function fromQueuePayload(array $payload): static
    {
        if (array_keys($payload) !== ['version', 'message']
            || !is_int($payload['version']) || $payload['version'] !== 1
            || !is_array($payload['message'])) {
            throw new BroadcastException('Queued broadcast payload version or shape is invalid.');
        }
        return new self(BroadcastMessage::fromArray($payload['message'], 32768));
    }
}
