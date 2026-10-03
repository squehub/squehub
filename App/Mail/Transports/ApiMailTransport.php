<?php

declare(strict_types=1);

namespace App\Mail\Transports;

use App\HttpClient\HttpClient;
use App\HttpClient\HttpClientException;
use App\HttpClient\HttpResponse;
use App\Mail\MailAddress;
use App\Mail\MailConfigurationException;
use App\Mail\MailMessage;
use App\Mail\MailProviderException;
use App\Mail\MailTransport;
use JsonException;

/**
 * Shared HTTP boundary for the two named API transports. The endpoint is fixed
 * by each adapter: a configured token cannot be forwarded to a caller-chosen
 * host or redirected to a second origin. Queue owns durable retries.
 */
abstract class ApiMailTransport implements MailTransport
{
    private const MAX_REQUEST_BYTES = 10 * 1024 * 1024;
    private const MAX_RESPONSE_BYTES = 64 * 1024;

    /** @param array<string,mixed> $settings */
    public function __construct(private array $settings, private ?HttpClient $http)
    {
    }

    /** Never expose API credentials when a named transport is inspected. */
    public function __debugInfo(): array { return ['driver' => $this->driver()]; }

    final public function send(MailMessage $message): void
    {
        $message->validate();
        if ($this->http === null) {
            throw new MailConfigurationException('HTTP Client service is required for API Mail transports.');
        }
        $token = $this->token();
        $timeout = $this->timeout();
        if ($message->recipients() === []) {
            // Both provider send endpoints require To even when SqueHub's SMTP
            // and Array transports can deliver a BCC-only message.
            throw new MailConfigurationException('This Mail provider requires a To recipient.');
        }
        self::assertBoundedDraft($message);
        try {
            $body = json_encode($this->payload($message), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MailConfigurationException('Mail provider payload cannot be encoded.');
        }
        if (strlen($body) > self::MAX_REQUEST_BYTES) {
            throw new MailConfigurationException('Mail provider payload exceeds the configured size limit.');
        }

        try {
            $response = $this->http->pending()
                ->acceptJson()
                ->withHeaders($this->authenticationHeaders($token))
                ->withBody($body, 'application/json')
                ->withPeerVerification()
                ->followRedirects(0)
                ->connectTimeout((float) min(5, $timeout))
                ->timeout((float) $timeout)
                ->maxRequestBytes(self::MAX_REQUEST_BYTES)
                ->maxResponseBytes(self::MAX_RESPONSE_BYTES)
                ->post($this->endpoint());
        } catch (HttpClientException) {
            // HTTP Client exceptions are already sanitized, but omitting the
            // cause keeps provider credentials and message data out of traces.
            throw new MailProviderException('network');
        }
        if (!$response->successful()) {
            throw new MailProviderException(match (true) {
                $response->status() === 401 => 'authentication',
                $response->status() === 429 => 'rate_limit',
                $response->status() >= 500 => 'server',
                in_array($response->status(), [400, 413, 415, 422], true) => 'validation',
                default => 'rejected',
            });
        }
        try {
            $data = $response->json();
        } catch (HttpClientException) {
            throw new MailProviderException('malformed');
        }
        $this->assertAccepted($response, $data);
    }

    abstract protected function driver(): string;
    abstract protected function endpoint(): string;
    /** @return array<string,string> */
    abstract protected function authenticationHeaders(#[\SensitiveParameter] string $token): array;
    /** @return array<string,mixed> */
    abstract protected function payload(MailMessage $message): array;
    abstract protected function assertAccepted(HttpResponse $response, mixed $data): void;

    /** @return list<string> */
    final protected static function addresses(array $addresses): array
    {
        return array_map(static function (MailAddress $address): string {
            $name = $address->name();
            if ($name === null || $name === '') return $address->address();
            // Quoting keeps commas and punctuation inside the display name
            // when Postmark later joins addresses with commas.
            $quoted = str_replace(['\\', '"'], ['\\\\', '\\"'], $name);
            return '"' . $quoted . '" <' . $address->address() . '>';
        }, $addresses);
    }

    final protected static function acceptedId(mixed $id): bool
    {
        return is_string($id) && strlen($id) <= 128
            && preg_match('/\A[A-Za-z0-9_-]+\z/D', $id) === 1;
    }

    /**
     * Reject oversized input before Base64 and JSON duplicate message bytes.
     * Escaping and structural overhead are still checked on the encoded body.
     */
    private static function assertBoundedDraft(MailMessage $message): void
    {
        $used = 0;
        foreach ([$message->subjectLine(), $message->textBody(), $message->htmlBody()] as $value) {
            if (is_string($value)) self::reserve($used, strlen($value));
        }
        $sender = $message->sender();
        if ($sender !== null) {
            self::reserve($used, strlen($sender->address()) + strlen($sender->name() ?? ''));
        }
        foreach ([$message->recipients(), $message->carbonCopies(), $message->blindCopies(),
            $message->replyAddresses()] as $addresses) {
            foreach ($addresses as $address) {
                self::reserve($used, strlen($address->address()) + strlen($address->name() ?? ''));
            }
        }
        foreach ($message->attachments() as $attachment) {
            $length = strlen($attachment->contents());
            if ($length > intdiv(self::MAX_REQUEST_BYTES, 4) * 3) {
                throw new MailConfigurationException('Mail provider payload exceeds the configured size limit.');
            }
            self::reserve($used, intdiv($length + 2, 3) * 4);
            self::reserve($used, strlen($attachment->filename()) + strlen($attachment->mime()));
        }
    }

    private static function reserve(int &$used, int $bytes): void
    {
        if ($bytes > self::MAX_REQUEST_BYTES - $used) {
            throw new MailConfigurationException('Mail provider payload exceeds the configured size limit.');
        }
        $used += $bytes;
    }

    private function token(): string
    {
        $token = $this->settings['api_key'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 512
            || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new MailConfigurationException('Mail provider API token is missing or invalid.');
        }
        return $token;
    }

    private function timeout(): int
    {
        $timeout = $this->settings['timeout'] ?? 10;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 120) {
            throw new MailConfigurationException('Mail provider timeout is invalid.');
        }
        return $timeout;
    }
}
