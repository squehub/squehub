<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Database\ModelClock;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\Webhooks\Receipts\ReceiptStore;
use Closure;
use Throwable;

/**
 * Verifies SqueHub's own signed webhook profile for one configured source.
 * Signature verification is separate from the atomic receipt claim and from
 * application work; callers choose whether to use handle() for deduplication.
 */
final class WebhookSource
{
    private string $secret;
    /** @var list<array{secret:string,expires_at:int}> */
    private array $previousSecrets = [];

    /** @param array<string,mixed> $settings */
    /** @param Closure():ReceiptStore $receiptResolver */
    public function __construct(private string $name, #[\SensitiveParameter] array $settings,
        private Closure $receiptResolver, private ModelClock $clock,
        private ?Diagnostics $diagnostics, private int $toleranceSeconds,
        private int $maxBodyBytes, private int $leaseSeconds)
    {
        if (strlen($name) > 64
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new WebhookException('Webhook source name is invalid.');
        }
        $secret = $settings['secret'] ?? null;
        self::validateSecret($secret);
        $this->secret = $secret;
        $previous = $settings['previous_secrets'] ?? [];
        if (!is_array($previous) || !array_is_list($previous) || count($previous) > 2) {
            throw new WebhookException('Webhook source rotation configuration is invalid.');
        }
        $now = $this->clock->now()->getTimestamp();
        foreach ($previous as $entry) {
            if (!is_array($entry) || count($entry) !== 2
                || !array_key_exists('secret', $entry)
                || !array_key_exists('expires_at', $entry)
                || !is_int($entry['expires_at']) || $entry['expires_at'] < 1
                || $entry['expires_at'] > $now + 30 * 86400) {
                throw new WebhookException('Webhook source rotation configuration is invalid.');
            }
            self::validateSecret($entry['secret']);
            $this->previousSecrets[] = $entry;
        }
    }

    /**
     * Authenticate the exact captured bytes before decoding JSON. One generic
     * error covers malformed metadata, stale timestamps, bad MACs and bodies.
     */
    public function verify(Request $request): VerifiedWebhook
    {
        if ($request->method() !== 'POST' || $request->contentType() !== 'application/json'
            || $request->headerRepeated('Content-Type')
            || $request->headerConflict('Content-Type')) {
            $this->reject();
        }
        $body = $request->rawBody();
        if ($body === '' || strlen($body) > $this->maxBodyBytes) $this->reject();

        $headers = [];
        foreach (['SqueHub-Webhook-Id', 'SqueHub-Webhook-Delivery-Id',
            'SqueHub-Webhook-Timestamp', 'SqueHub-Webhook-Signature'] as $name) {
            $value = $request->header($name);
            if ($request->headerRepeated($name) || $request->headerConflict($name)
                || !is_string($value) || $value === '' || strlen($value) > 128) {
                $this->reject();
            }
            $headers[$name] = $value;
        }
        $timestampText = $headers['SqueHub-Webhook-Timestamp'];
        if (preg_match('/\A[1-9][0-9]{0,11}\z/D', $timestampText) !== 1
            || (string) (int) $timestampText !== $timestampText) {
            $this->reject();
        }
        $timestamp = (int) $timestampText;
        $now = $this->clock->now()->getTimestamp();
        if ($timestamp < $now - $this->toleranceSeconds
            || $timestamp > $now + $this->toleranceSeconds) {
            $this->reject();
        }

        $secrets = [$this->secret];
        foreach ($this->previousSecrets as $previous) {
            if ($now < $previous['expires_at']) $secrets[] = $previous['secret'];
        }
        if (!WebhookSignature::verify($headers['SqueHub-Webhook-Id'],
            $headers['SqueHub-Webhook-Delivery-Id'], $timestamp, $body,
            $headers['SqueHub-Webhook-Signature'], $secrets)) {
            $this->reject();
        }
        try {
            $event = WebhookEvent::fromJson($body);
        } catch (WebhookException) {
            $this->reject();
        }
        if ($event->id() !== $headers['SqueHub-Webhook-Id']) $this->reject();
        $this->diagnostics?->webhook('incoming_verified');
        return new VerifiedWebhook($this->name, $event,
            $headers['SqueHub-Webhook-Delivery-Id']);
    }

    /**
     * Atomically claim an event ID before application work. A successful
     * callback closes the receipt; a failure makes it reclaimable so a remote
     * retry can run it again. The callback must make side effects idempotent:
     * a worker crash between business commit and receipt completion can replay.
     */
    public function handle(Request $request, callable $callback): bool
    {
        $verified = $this->verify($request);
        // Verification never opens a receipt connection. Only a valid signed
        // delivery is allowed to reach persistence and application work.
        $receipts = ($this->receiptResolver)();
        $now = $this->clock->now()->getTimestamp();
        $claim = $receipts->claim($this->name, $verified->id(),
            $now, $this->leaseSeconds);
        if ($claim === null) {
            $this->diagnostics?->webhook('incoming_duplicates');
            return false;
        }
        try {
            $callback($verified);
        } catch (Throwable $failure) {
            $receipts->failed($this->name, $verified->id(), $claim,
                $this->clock->now()->getTimestamp());
            // The original exception is chained for local debugging. The
            // public 500 and structured log surface only safe wrapper data.
            throw new WebhookException('Webhook processing failed.', 0, $failure);
        }
        $receipts->processed($this->name, $verified->id(), $claim,
            $this->clock->now()->getTimestamp());
        return true;
    }

    /** Configuration errors are not turned into authenticity failures. */
    private static function validateSecret(mixed $secret): void
    {
        if (!is_string($secret) || strlen($secret) < 32 || strlen($secret) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $secret) === 1) {
            throw new WebhookException('Webhook source secret is invalid.');
        }
    }

    /** @return never */
    private function reject(): never
    {
        $this->diagnostics?->webhook('incoming_rejected');
        throw new InvalidWebhookException();
    }
}
