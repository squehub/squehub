<?php

declare(strict_types=1);

namespace App\Webhooks\Queue;

use App\Container\Container;
use App\Queue\QueueContextAware;
use App\Queue\QueueAttemptContextAware;
use App\Queue\QueueJob;
use App\Webhooks\WebhookEvent;
use App\Webhooks\WebhookException;
use App\Webhooks\WebhookManager;
use App\Webhooks\WebhookSignature;

/**
 * Fixed framework job for a queued webhook. Persist only a bounded event and
 * the endpoint's name; the worker resolves the current URL and signing secret.
 */
final class DeliverWebhook implements QueueJob, QueueContextAware, QueueAttemptContextAware
{
    private ?Container $workerContainer = null;
    private bool $mayRetry = false;

    private function __construct(private string $endpointName, private string $body,
        private string $eventId, private string $deliveryId,
        private ?WebhookManager $runtimeManager = null, private int $version = 1)
    {
    }

    public static function capture(string $endpointName, WebhookEvent $event,
        string $deliveryId, WebhookManager $manager): self
    {
        self::validate($endpointName, $event->body(), $event->id(), $deliveryId);
        return new self($endpointName, $event->body(), $event->id(), $deliveryId, $manager);
    }

    public function handle(): void
    {
        if ($this->version !== 1) {
            throw new WebhookException('Queued webhook job version is unsupported.');
        }
        self::validate($this->endpointName, $this->body, $this->eventId, $this->deliveryId);
        $manager = $this->workerContainer?->make(WebhookManager::class)
            ?? $this->runtimeManager;
        if ($manager === null) {
            throw new WebhookException('Queued webhook manager is unavailable.');
        }
        $manager->deliverQueued($this->endpointName, $this->body,
            $this->eventId, $this->deliveryId, $this->mayRetry);
    }

    public function setQueueContainer(Container $container): void
    {
        $this->workerContainer = $container;
    }

    public function setQueueAttemptContext(int $attempt, int $maxAttempts): void
    {
        if ($attempt < 1 || $maxAttempts < 1) {
            throw new WebhookException('Queued webhook attempt context is invalid.');
        }
        $this->mayRetry = $attempt < $maxAttempts;
    }

    public function toQueuePayload(): array
    {
        return ['version' => $this->version, 'endpoint' => $this->endpointName,
            'body' => $this->body, 'event_id' => $this->eventId,
            'delivery_id' => $this->deliveryId];
    }

    /** Queue payloads may contain application data, even without credentials. */
    public function __debugInfo(): array
    {
        return ['version' => $this->version, 'endpoint' => '[REDACTED]',
            'event_id' => $this->eventId, 'delivery_id' => $this->deliveryId,
            'body' => '[REDACTED]'];
    }

    public static function fromQueuePayload(array $payload): static
    {
        if (($payload['version'] ?? null) !== 1
            || !is_string($payload['endpoint'] ?? null)
            || !is_string($payload['body'] ?? null)
            || !is_string($payload['event_id'] ?? null)
            || !is_string($payload['delivery_id'] ?? null)) {
            throw new WebhookException('Queued webhook payload is invalid.');
        }
        self::validate($payload['endpoint'], $payload['body'], $payload['event_id'],
            $payload['delivery_id']);
        return new self($payload['endpoint'], $payload['body'], $payload['event_id'],
            $payload['delivery_id']);
    }

    /** Reject tampering before either sync delivery or worker reconstruction. */
    private static function validate(string $endpointName, string $body,
        string $eventId, string $deliveryId): void
    {
        if (strlen($endpointName) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $endpointName) !== 1
            || !WebhookEvent::validId($eventId)
            || !WebhookSignature::validDeliveryId($deliveryId)
            || strlen($body) > WebhookEvent::MAX_BODY_BYTES
            || WebhookEvent::fromJson($body)->id() !== $eventId) {
            throw new WebhookException('Queued webhook payload is invalid.');
        }
    }
}
