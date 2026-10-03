<?php

declare(strict_types=1);

namespace App\Mail;

use App\Diagnostics\Diagnostics;
use App\HttpClient\HttpClient;
use App\Mail\Transports\ArrayMailTransport;
use App\Mail\Transports\PostmarkMailTransport;
use App\Mail\Transports\ResendMailTransport;
use App\Mail\Transports\SmtpMailTransport;
use App\Mail\Queue\MailMessagePayload;
use App\Mail\Queue\SendMailMessage;
use App\Queue\QueueCodec;
use App\Queue\QueueManager;
use Throwable;

/**
 * Application-owned message preparation and named transport registry. Mail
 * does not know about Account Security, Storage, or HTTP; callers compose
 * token delivery and attachments explicitly.
 */
final class Mailer
{
    private string $default;
    private ?MailAddress $defaultFrom = null;
    /** @var array<string,array<string,mixed>> */
    private array $settings;
    /** @var array<string,MailTransport> */
    private array $resolved = [];

    /** @param array<string,mixed> $configuration */
    public function __construct(array $configuration, private ?Diagnostics $diagnostics = null,
        private ?QueueManager $queueManager = null, private ?HttpClient $httpClient = null)
    {
        $default = $configuration['default'] ?? null;
        $transports = $configuration['transports'] ?? null;
        if (!is_string($default) || !is_array($transports)) {
            throw new MailConfigurationException('Mail default transport is not configured.');
        }
        self::validateName($default);
        foreach ($transports as $name => $settings) {
            if (!is_string($name) || !is_array($settings)) {
                throw new MailConfigurationException('Invalid named mail transport.');
            }
            self::validateName($name);
            if (!in_array($settings['driver'] ?? null, ['smtp', 'array', 'resend', 'postmark'], true)) {
                throw new MailConfigurationException('Unsupported mail transport driver.');
            }
        }
        if (!isset($transports[$default])) {
            throw new MailConfigurationException('Mail default transport is undefined.');
        }
        $from = $configuration['from'] ?? [];
        if (!is_array($from)) {
            throw new MailConfigurationException('Invalid mail sender configuration.');
        }
        $address = $from['address'] ?? null;
        $name = $from['name'] ?? null;
        if ($address !== null || $name !== null) {
            if (!is_string($address) || ($name !== null && !is_string($name))) {
                throw new MailConfigurationException('Invalid mail sender configuration.');
            }
            $this->defaultFrom = new MailAddress($address, $name);
        }
        $this->default = $default;
        $this->settings = $transports;
    }

    public function send(MailMessage $message, ?string $via = null): void
    {
        // Every send invocation has exactly one sent/failure outcome. Transport
        // timing excludes validation and the application's message construction.
        $this->diagnostics?->mail('attempts');
        try {
            $prepared = $message->snapshot();
            if ($prepared->sender() === null && $this->defaultFrom !== null) {
                $prepared->from($this->defaultFrom->address(), $this->defaultFrom->name());
            }
            $prepared->validate();
            $transport = $this->transport($via);
            $started = hrtime(true);
            try {
                $transport->send($prepared);
            } finally {
                $this->diagnostics?->mailTime((hrtime(true) - $started) / 1_000_000);
            }
            $this->diagnostics?->mail('sent');
        } catch (Throwable $failure) {
            $this->diagnostics?->mail('failures');
            if ($failure instanceof MailException) throw $failure;
            // Custom transports may throw arbitrary exceptions containing mail
            // content. Keep the public/loggable failure generic.
            throw new MailException('Mail send failed.');
        }
    }

    /** Queue only explicit message fields; SMTP configuration stays with the worker. */
    public function queue(MailMessage $message, ?string $connection = null,
        string $queue = 'default', int $delay = 0, ?string $via = null,
        bool $afterCommit = false, ?string $transactionConnection = null): void
    {
        if ($this->queueManager === null) throw new MailConfigurationException('Queue service is unavailable for Mail.');
        $prepared = $message->snapshot();
        if ($prepared->sender() === null && $this->defaultFrom !== null) {
            $prepared->from($this->defaultFrom->address(), $this->defaultFrom->name());
        }
        $prepared->validate();
        if ($via !== null) {
            self::validateName($via);
            if (!isset($this->settings[$via])) {
                throw new MailConfigurationException('Unknown mail transport.');
            }
        }
        $job = new SendMailMessage(MailMessagePayload::encode($message), $via, $this);
        // Sync Queue does not run QueueCodec, so enforce the same payload bound
        // before either connection can execute or persist the delivery job.
        QueueCodec::encode($job);
        if ($afterCommit) {
            $this->queueManager->afterCommit($job, $queue, $delay, $connection, $transactionConnection);
        } else {
            $this->queueManager->dispatch($job, $queue, $delay, $connection);
        }
    }

    /** Resolve lazily; array outboxes can be inspected deliberately in tests. */
    public function transport(?string $name = null): MailTransport
    {
        $name ??= $this->default;
        self::validateName($name);
        if (!isset($this->settings[$name])) throw new MailConfigurationException('Unknown mail transport.');
        if (!isset($this->resolved[$name])) {
            $settings = $this->settings[$name];
            $this->resolved[$name] = match ($settings['driver']) {
                'array' => new ArrayMailTransport(),
                'smtp' => new SmtpMailTransport($settings),
                'resend' => new ResendMailTransport($settings, $this->httpClient),
                'postmark' => new PostmarkMailTransport($settings, $this->httpClient),
                default => throw new MailConfigurationException('Unsupported mail transport.'),
            };
        }
        return $this->resolved[$name];
    }

    /** Keep transport credentials out of ordinary debugger object displays. */
    public function __debugInfo(): array
    {
        return ['default' => $this->default, 'transports' => array_keys($this->settings)];
    }

    private static function validateName(string $name): void
    {
        if (strlen($name) > 128 || preg_match('/\A[A-Za-z0-9_][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new MailConfigurationException('Mail transport name must be a bounded identifier.');
        }
    }
}
