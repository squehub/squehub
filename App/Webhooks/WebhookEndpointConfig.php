<?php

declare(strict_types=1);

namespace App\Webhooks;

use SensitiveParameter;
use ValueError;

/**
 * One explicitly configured outbound destination and its current signing key.
 * A named endpoint is configuration, never a URL selected from request input.
 */
final class WebhookEndpointConfig
{
    private string $url;
    private string $secret;
    private int $maxAttempts;
    private int $retryDelayMs;

    /** @param array<string, mixed> $settings */
    public function __construct(private string $name, #[SensitiveParameter] array $settings)
    {
        if (strlen($name) > 128 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new WebhookException('Webhook endpoint name is invalid.');
        }

        $url = $settings['url'] ?? null;
        $secret = $settings['secret'] ?? null;
        $allowLocalHttp = $settings['allow_local_http'] ?? false;
        $maxAttempts = $settings['max_attempts'] ?? 1;
        $retryDelayMs = $settings['retry_delay_ms'] ?? 0;
        if (!is_string($url) || !is_string($secret) || strlen($secret) < 32
            || strlen($secret) > 512 || preg_match('/[\x00-\x1f\x7f]/', $secret) === 1
            || !is_bool($allowLocalHttp) || !is_int($maxAttempts)
            || $maxAttempts < 1 || $maxAttempts > 3 || !is_int($retryDelayMs)
            || $retryDelayMs < 0 || $retryDelayMs > 1000) {
            throw new WebhookException('Webhook endpoint configuration is invalid.');
        }
        try {
            $parts = parse_url($url);
        } catch (ValueError) {
            $parts = false;
        }
        if (!is_array($parts) || !is_string($parts['scheme'] ?? null)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new WebhookException('Webhook endpoint URL is invalid.');
        }
        $scheme = strtolower($parts['scheme']);
        // A terminal DNS root dot must not bypass the local-name policy.
        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $allowedLocal = $scheme === 'http' && $allowLocalHttp && $loopback;
        if ($scheme !== 'https' && !$allowedLocal) {
            throw new WebhookException('Webhook endpoint requires HTTPS.');
        }
        // Literal IPs and obvious local DNS names are never production
        // destinations. This is a configuration guard, not DNS-rebinding
        // protection; egress controls remain necessary for hostile DNS.
        if (!$allowedLocal && (filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || !str_contains($host, '.')
            || preg_match('/\A[0-9.]+\z/D', $host) === 1
            || preg_match('/\A0x[0-9a-f]+\z/iD', $host) === 1)) {
            throw new WebhookException('Webhook endpoint URL is invalid.');
        }

        $this->url = $url;
        $this->secret = $secret;
        $this->maxAttempts = $maxAttempts;
        $this->retryDelayMs = $retryDelayMs;
    }

    public function name(): string { return $this->name; }
    public function url(): string { return $this->url; }
    public function secret(): string { return $this->secret; }
    public function maxAttempts(): int { return $this->maxAttempts; }
    public function retryDelayMs(): int { return $this->retryDelayMs; }

    /** Diagnostic dumps must not reveal the URL or signing credential. */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'url' => '[REDACTED]',
            'secret' => '[REDACTED]', 'max_attempts' => $this->maxAttempts,
            'retry_delay_ms' => $this->retryDelayMs];
    }
}
