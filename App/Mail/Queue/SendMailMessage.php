<?php

declare(strict_types=1);

namespace App\Mail\Queue;

use App\Container\Container;
use App\Mail\Mail;
use App\Mail\MailConfigurationException;
use App\Mail\Mailer;
use App\Queue\QueueJob;
use App\Queue\QueueContextAware;

/** Fixed framework job: Queue owns retries; Mailer owns validation and transport. */
final class SendMailMessage implements QueueJob, QueueContextAware
{
    private ?Container $workerContainer = null;
    /** @param array<string, mixed> $message */
    public function __construct(private array $message, private ?string $via = null,
        private ?Mailer $runtimeMailer = null, private int $version = 1)
    {
    }

    public function handle(): void
    {
        if ($this->version !== 1) {
            throw new MailConfigurationException('Queued Mail job version is unsupported.');
        }
        $mailer = $this->workerContainer?->make(Mailer::class)
            ?? $this->runtimeMailer ?? Mail::manager();
        $mailer->send(MailMessagePayload::decode($this->message), $this->via);
    }

    public function setQueueContainer(Container $container): void { $this->workerContainer = $container; }

    public function toQueuePayload(): array
    {
        return ['version' => $this->version, 'message' => $this->message, 'via' => $this->via];
    }

    public static function fromQueuePayload(array $payload): static
    {
        if (!is_int($payload['version'] ?? null) || !is_array($payload['message'] ?? null)
            || !(is_string($payload['via'] ?? null) || ($payload['via'] ?? null) === null)) {
            throw new MailConfigurationException('Queued Mail job payload is invalid.');
        }
        return new static($payload['message'], $payload['via'] ?? null, null, $payload['version']);
    }
}
