<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Diagnostics\Diagnostics;
use App\HttpClient\HttpClient;
use App\Queue\QueueManager;
use App\Webhooks\Deliveries\ArrayDeliveryStore;
use App\Webhooks\Deliveries\DatabaseDeliveryStore;
use App\Webhooks\Deliveries\DeliveryStore;
use App\Webhooks\Queue\DeliverWebhook;
use App\Webhooks\Receipts\ArrayReceiptStore;
use App\Webhooks\Receipts\DatabaseReceiptStore;
use App\Webhooks\Receipts\ReceiptStore;

/**
 * Application-owned coordinator for configured SqueHub webhook peers.
 * Construction does not open a database, resolve a peer, or send a request.
 */
final class WebhookManager
{
    private ?ReceiptStore $receipts = null;
    private ?DeliveryStore $deliveries = null;
    private ?WebhookSender $sender = null;

    public function __construct(private Repository $config, private HttpClient $http,
        private ?QueueManager $queue, private DatabaseManager $database,
        private ?Diagnostics $diagnostics = null)
    {
    }

    public function endpoint(string $name): WebhookEndpoint
    {
        $this->endpointConfig($name);
        return new WebhookEndpoint($this, $name);
    }

    public function source(string $name): WebhookSource
    {
        $name = self::name($name);
        $sources = $this->config->get('webhooks.sources', []);
        $settings = is_array($sources) ? ($sources[$name] ?? null) : null;
        if (!is_array($settings)) {
            throw new WebhookException('Webhook source is not configured.');
        }
        return new WebhookSource($name, $settings, fn (): ReceiptStore => $this->receiptStore(),
            $this->database->clock(), $this->diagnostics,
            $this->integerOption('timestamp_tolerance', 300, 1, 3600),
            $this->integerOption('max_body_bytes', WebhookEvent::MAX_BODY_BYTES,
                1, WebhookEvent::MAX_BODY_BYTES),
            $this->integerOption('receipt_lease_seconds', 300, 1, 31536000));
    }

    /** @param array<string|int,mixed> $data */
    public function event(string $type, array $data): WebhookEvent
    {
        $event = WebhookEvent::create($type, $data, $this->database->clock()->now());
        $this->diagnostics?->webhook('outgoing_events');
        return $event;
    }

    /** @param array<string|int,mixed> $data */
    public function send(string $endpointName, string|WebhookEvent $event,
        array $data = []): WebhookDeliveryResult
    {
        $endpoint = $this->endpointConfig($endpointName);
        $event = $this->normalizeEvent($event, $data);
        $result = $this->deliver($endpoint, $event,
            WebhookSignature::generateDeliveryId(), false);
        if ($result === null) throw new WebhookException('Webhook delivery already completed.');
        return $result;
    }

    /** @param array<string|int,mixed> $data */
    public function queue(string $endpointName, string|WebhookEvent $event, array $data = [],
        string $queue = 'default', int $delay = 0,
        ?string $connection = null): WebhookDeliveryTicket
    {
        $this->endpointConfig($endpointName);
        $queueManager = $this->queueManager();
        $event = $this->normalizeEvent($event, $data);
        $deliveryId = WebhookSignature::generateDeliveryId();
        $job = DeliverWebhook::capture($endpointName, $event, $deliveryId, $this);
        $queueManager->dispatch($job, $queue, $delay, $connection);
        return new WebhookDeliveryTicket($event->id(), $deliveryId);
    }

    /**
     * Use Queue's existing outermost-commit callback. A post-commit Queue
     * persistence failure cannot roll back the committed business record.
     *
     * @param array<string|int,mixed> $data
     */
    public function afterCommit(string $endpointName, string|WebhookEvent $event,
        array $data = [], string $queue = 'default', int $delay = 0,
        ?string $connection = null, ?string $transactionConnection = null): WebhookDeliveryTicket
    {
        $this->endpointConfig($endpointName);
        $queueManager = $this->queueManager();
        $event = $this->normalizeEvent($event, $data);
        $deliveryId = WebhookSignature::generateDeliveryId();
        $job = DeliverWebhook::capture($endpointName, $event, $deliveryId, $this);
        $queueManager->afterCommit($job, $queue, $delay, $connection,
            $transactionConnection);
        return new WebhookDeliveryTicket($event->id(), $deliveryId);
    }

    /** Fixed worker entry point; Queue payload never supplies a URL or secret. */
    public function deliverQueued(string $endpointName, string $body,
        string $eventId, string $deliveryId, bool $mayRetry = false): void
    {
        $event = WebhookEvent::fromJson($body);
        if ($event->id() !== $eventId) {
            throw new WebhookException('Queued webhook identity is invalid.');
        }
        $endpoint = $this->endpointConfig($endpointName);
        $result = $this->deliver($endpoint, $event, $deliveryId, $mayRetry);
        if ($result !== null && $result->retryable()) {
            // Queue owns worker backoff and failed-job retention. A terminal
            // peer rejection is acknowledged; retrying it would only add load.
            throw new WebhookException('Webhook delivery encountered a transient failure.');
        }
    }

    /** Explicit maintenance; no background pruning or destructive CLI command. */
    public function pruneReceipts(int $olderThan): int
    {
        return $this->receiptStore()->prune($olderThan);
    }

    /** Explicit retention maintenance for safe outbound delivery metadata. */
    public function pruneDeliveries(int $olderThan): int
    {
        return $this->deliveryStore()->prune($olderThan);
    }

    /**
     * Prune only terminal records at the configured age. Pending or retrying
     * deliveries and active receipt claims remain available for recovery.
     *
     * @return array{receipts:int,deliveries:int}
     */
    public function prune(): array
    {
        $now = $this->database->clock()->now()->getTimestamp();
        return [
            'receipts' => $this->pruneReceipts($now - 86400 *
                $this->integerOption('receipt_retention_days', 30, 1, 3650)),
            'deliveries' => $this->pruneDeliveries($now - 86400 *
                $this->integerOption('delivery_retention_days', 30, 1, 3650)),
        ];
    }

    private function deliver(WebhookEndpointConfig $endpoint, WebhookEvent $event,
        string $deliveryId, bool $queued): ?WebhookDeliveryResult
    {
        $store = $this->deliveryStore();
        // A terminal ledger row lets a redelivered Queue job acknowledge
        // without sending again. A crash before record() can still replay;
        // this reduces duplicates but cannot promise exactly-once delivery.
        if (!$store->begin($event->id(), $deliveryId, $endpoint->name(),
            $this->database->clock()->now()->getTimestamp())) {
            return null;
        }
        $result = $this->sender()->send($endpoint, $event, $deliveryId);
        $store->record($deliveryId, $result, $this->database->clock()->now()->getTimestamp(),
            $queued && $result->retryable());
        return $result;
    }

    private function sender(): WebhookSender
    {
        return $this->sender ??= new WebhookSender($this->http,
            $this->database->clock(), $this->diagnostics);
    }

    private function queueManager(): QueueManager
    {
        if ($this->queue === null) {
            throw new WebhookException('Queue service is unavailable for webhook dispatch.');
        }
        return $this->queue;
    }

    /** @param array<string|int,mixed> $data */
    private function normalizeEvent(string|WebhookEvent $event, array $data): WebhookEvent
    {
        if ($event instanceof WebhookEvent) {
            if ($data !== []) throw new WebhookException('Webhook event data was supplied twice.');
            return $event;
        }
        return $this->event($event, $data);
    }

    private function endpointConfig(string $name): WebhookEndpointConfig
    {
        $name = self::name($name);
        $endpoints = $this->config->get('webhooks.endpoints', []);
        $settings = is_array($endpoints) ? ($endpoints[$name] ?? null) : null;
        if (!is_array($settings)) {
            throw new WebhookException('Webhook endpoint is not configured.');
        }
        return new WebhookEndpointConfig($name, $settings);
    }

    private function receiptStore(): ReceiptStore
    {
        if ($this->receipts !== null) return $this->receipts;
        $store = $this->config->get('webhooks.receipt_store', 'database');
        if ($store === 'array') return $this->receipts = new ArrayReceiptStore();
        if ($store !== 'database') {
            throw new WebhookException('Webhook receipt store is invalid.');
        }
        $connection = $this->config->get('webhooks.receipt_connection');
        if ($connection !== null && (!is_string($connection) || $connection === '')) {
            throw new WebhookException('Webhook receipt connection is invalid.');
        }
        return $this->receipts = new DatabaseReceiptStore(
            $this->database->connection($connection));
    }

    private function deliveryStore(): DeliveryStore
    {
        if ($this->deliveries !== null) return $this->deliveries;
        $store = $this->config->get('webhooks.delivery_store', 'database');
        if ($store === 'array') return $this->deliveries = new ArrayDeliveryStore();
        if ($store !== 'database') {
            throw new WebhookException('Webhook delivery store is invalid.');
        }
        $connection = $this->config->get('webhooks.delivery_connection');
        if ($connection !== null && (!is_string($connection) || $connection === '')) {
            throw new WebhookException('Webhook delivery connection is invalid.');
        }
        return $this->deliveries = new DatabaseDeliveryStore(
            $this->database->connection($connection));
    }

    private function integerOption(string $name, int $default, int $min, int $max): int
    {
        $value = $this->config->get('webhooks.' . $name, $default);
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new WebhookException('Webhook configuration is invalid.');
        }
        return $value;
    }

    private static function name(string $name): string
    {
        if (strlen($name) > 64
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new WebhookException('Webhook peer name is invalid.');
        }
        return $name;
    }
}
