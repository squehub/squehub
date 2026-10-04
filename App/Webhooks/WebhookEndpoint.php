<?php

declare(strict_types=1);

namespace App\Webhooks;

/** Named application destination. A shared event may be sent to several peers. */
final class WebhookEndpoint
{
    public function __construct(private WebhookManager $manager, private string $name)
    {
    }

    /**
     * Send immediately. Passing a WebhookEvent preserves its logical event ID
     * and exact body when delivering it to more than one endpoint.
     *
     * @param array<string|int,mixed> $data
     */
    public function send(string|WebhookEvent $event, array $data = []): WebhookDeliveryResult
    {
        return $this->manager->send($this->name, $event, $data);
    }

    /**
     * Persist Queue work now. The sync Queue executes the delivery inline;
     * database and Redis Queue workers execute it later.
     *
     * @param array<string|int,mixed> $data
     */
    public function queue(string|WebhookEvent $event, array $data = [], string $queue = 'default',
        int $delay = 0, ?string $connection = null): WebhookDeliveryTicket
    {
        return $this->manager->queue($this->name, $event, $data, $queue, $delay, $connection);
    }

    /**
     * Defer Queue dispatch until the outermost business transaction commits.
     * A rollback cancels the callback; returned IDs are not proof of dispatch.
     *
     * @param array<string|int,mixed> $data
     */
    public function afterCommit(string|WebhookEvent $event, array $data = [],
        string $queue = 'default', int $delay = 0, ?string $connection = null,
        ?string $transactionConnection = null): WebhookDeliveryTicket
    {
        return $this->manager->afterCommit($this->name, $event, $data, $queue, $delay,
            $connection, $transactionConnection);
    }
}
