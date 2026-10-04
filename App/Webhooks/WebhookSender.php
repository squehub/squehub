<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Database\ModelClock;
use App\Diagnostics\Diagnostics;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpClientException;
use App\HttpClient\HttpConnectionException;
use App\HttpClient\HttpTimeoutException;
use App\Reliability\RetryPolicy;
use SensitiveParameter;

/**
 * Sends the event's original JSON bytes to one configured endpoint. The
 * shared policy bounds timing; Webhooks still classify transient outcomes,
 * preserve the signed body, and own their delivery ledger semantics.
 */
final class WebhookSender
{
    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function __construct(private HttpClient $http, private ModelClock $clock,
        private ?Diagnostics $diagnostics = null)
    {
    }

    /** A result has only safe metadata; neither response nor request body is retained. */
    public function send(#[SensitiveParameter] WebhookEndpointConfig $endpoint,
        #[SensitiveParameter] WebhookEvent $event, string $deliveryId): WebhookDeliveryResult
    {
        $policy = new RetryPolicy($endpoint->maxAttempts(),
            $endpoint->retryDelayMs(), maxDelayMs: 1000);
        $started = hrtime(true);
        for ($attempt = 1; $attempt <= $endpoint->maxAttempts(); ++$attempt) {
            // The secret and timestamp are resolved per attempt. A Queue retry
            // after rotation therefore signs with the worker's current key.
            $headers = WebhookSignature::headers($event, $deliveryId,
                $this->clock->now()->getTimestamp(), $endpoint->secret());
            $this->diagnostics?->webhook('delivery_attempts');
            try {
                $response = $this->http->pending()
                    // Webhook HTTPS cannot inherit an application HTTP
                    // client's relaxed verification setting.
                    ->withPeerVerification()
                    ->withHeaders($headers)
                    ->withBody($event->body(), 'application/json')
                    ->maxRequestBytes(WebhookEvent::MAX_BODY_BYTES)
                    ->maxResponseBytes(4096)
                    ->followRedirects(0)
                    ->post($endpoint->url());
                $status = $response->status();
                if ($response->successful()) {
                    $this->diagnostics?->webhook('delivery_successes');
                    return new WebhookDeliveryResult($event->id(), $deliveryId,
                        $status, true, $attempt, null, false);
                }
                $retryable = in_array($status, self::RETRYABLE_STATUSES, true);
                if ($retryable) {
                    $wait = $policy->nextDelayMs($attempt,
                        (int) ((hrtime(true) - $started) / 1_000_000),
                        RetryPolicy::retryAfterMs($response->header('retry-after')));
                    if ($wait !== null) {
                        $this->retry($wait);
                        continue;
                    }
                }
                $this->diagnostics?->webhook('delivery_failures');
                return new WebhookDeliveryResult($event->id(), $deliveryId,
                    $status, false, $attempt, 'http_status', $retryable);
            } catch (HttpConnectionException $failure) {
                $category = $failure instanceof HttpTimeoutException ? 'timeout' : 'connection';
                $wait = $policy->nextDelayMs($attempt,
                    (int) ((hrtime(true) - $started) / 1_000_000));
                if ($wait !== null) {
                    $this->retry($wait);
                    continue;
                }
                $this->diagnostics?->webhook('delivery_failures');
                return new WebhookDeliveryResult($event->id(), $deliveryId,
                    null, false, $attempt, $category, true);
            } catch (HttpClientException) {
                // Configuration/transport errors are not guessed to be
                // transient. In particular, no response body enters a result.
                $this->diagnostics?->webhook('delivery_failures');
                return new WebhookDeliveryResult($event->id(), $deliveryId,
                    null, false, $attempt, 'transport', false);
            }
        }

        // EndpointConfig guarantees at least one attempt, so this branch is
        // unreachable unless a future edit weakens that validation.
        throw new WebhookException('Webhook delivery attempt policy is invalid.');
    }

    private function retry(int $delayMs): void
    {
        $this->diagnostics?->webhook('delivery_retries');
        if ($delayMs > 0) usleep($delayMs * 1000);
    }
}
