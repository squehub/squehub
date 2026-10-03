<?php

declare(strict_types=1);

namespace App\HttpClient;

use App\Diagnostics\Diagnostics;
use App\Reliability\RetryPolicy;
use Throwable;

/** Application-owned outbound client, including isolated fake definitions and captures. */
final class HttpClient
{
    /** @var array<string,mixed>|null */
    private ?array $fakes = null;
    /** @var list<OutgoingRequest> */
    private array $captured = [];

    /** @param array<string,mixed> $settings */
    public function __construct(private array $settings = [], ?HttpTransport $transport = null,
        private ?Diagnostics $diagnostics = null)
    {
        $this->transport = $transport ?? new CurlTransport();
    }

    private HttpTransport $transport;

    public function pending(): PendingRequest { return new PendingRequest($this, $this->settings); }

    /** @internal PendingRequest reads the Application's current safe ID only. */
    public function correlationId(): ?string
    {
        return $this->diagnostics?->correlation()->current();
    }
    public function request(string $method, string $url, array|string|null $data = null): HttpResponse
    { return $this->pending()->request($method, $url, $data); }
    public function get(string $url, array $query = []): HttpResponse
    { return $this->pending()->get($url, $query); }
    public function post(string $url, array|string|null $data = null): HttpResponse
    { return $this->pending()->post($url, $data); }

    /** Exact URL, wildcard URL, or "METHOD URL" patterns; unmatched fakes never reach the network. */
    public function fake(array $routes): void
    {
        foreach ($routes as $pattern => $outcome) {
            if (!is_string($pattern) || $pattern === '' || !self::validFakeOutcome($outcome)) {
                throw new HttpConfigurationException('HTTP fake definition is invalid.');
            }
        }
        $this->fakes = $routes;
        $this->captured = [];
    }

    public function resetFake(): void { $this->fakes = null; $this->captured = []; }
    /** Captures intentionally contain request secrets and should remain in test code only. */
    public function captured(): array { return $this->captured; }

    public function send(OutgoingRequest $request, int $attempts, int $delayMs,
        int $maxRedirects, ?RetryPolicy $policy = null,
        ?bool $allowUnsafeRetry = null): HttpResponse
    {
        // The historical numeric send() arguments explicitly requested
        // retries. A supplied policy does not by itself authorize replaying
        // an unsafe mutation, even when this lower-level method is called.
        $allowUnsafeRetry ??= $policy === null;
        $policy ??= new RetryPolicy($attempts, $delayMs);
        $replayable = $allowUnsafeRetry || in_array($request->method, ['GET', 'HEAD'], true);
        $started = hrtime(true);
        $status = null;
        $failed = false;
        $result = 'success';
        $this->diagnostics?->httpClient('requests');
        try {
            // RetryPolicy controls timing, while this client retains its
            // transient-status and connection-error classification. A policy
            // alone cannot authorize replaying an unsafe mutation.
            for ($attempt = 1; $attempt <= $policy->maxAttempts(); ++$attempt) {
                try {
                    $response = $this->exchange($request, $maxRedirects);
                    if ($replayable && in_array($response->status(), [429, 502, 503, 504], true)) {
                        $wait = $policy->nextDelayMs($attempt,
                            (int) ((hrtime(true) - $started) / 1_000_000),
                            RetryPolicy::retryAfterMs($response->header('retry-after')));
                        if ($wait !== null) {
                            $this->diagnostics?->httpClient('retried');
                            self::wait($wait);
                            continue;
                        }
                    }
                    $status = $response->status();
                    $failed = !$response->successful();
                    if ($failed) $result = 'http_status';
                    $this->diagnostics?->httpClient($failed ? 'failed' : 'successful');
                    return $response;
                } catch (HttpConnectionException $exception) {
                    $wait = $replayable ? $policy->nextDelayMs($attempt,
                        (int) ((hrtime(true) - $started) / 1_000_000)) : null;
                    if ($wait === null) throw $exception;
                    $this->diagnostics?->httpClient('retried');
                    self::wait($wait);
                }
            }
            throw new HttpClientException('External HTTP request failed.');
        } catch (Throwable $exception) {
            $failed = true;
            $result = $exception instanceof HttpConnectionException ? 'connection_error' : 'client_error';
            $this->diagnostics?->httpClient('failed');
            throw $exception;
        } finally {
            $this->diagnostics?->httpClientTime((hrtime(true) - $started) / 1_000_000,
                $request->method, $status, $failed, $result);
        }
    }

    private function exchange(OutgoingRequest $request, int $maxRedirects): HttpResponse
    {
        for ($hop = 0; ; ++$hop) {
            $this->diagnostics?->httpClient('attempts');
            if ($this->fakes !== null) $this->captured[] = $request;
            $response = $this->fakeResponse($request) ?? $this->transport->send($request);
            $location = $response->header('location');
            if ($location === null || !in_array($response->status(), [301, 302, 303, 307, 308], true)
                || $maxRedirects === 0) return $response;
            $target = self::redirectUrl($request->url, $location);
            PendingRequest::validateUrl($target);
            if (self::origin($target) !== self::origin($request->url)) {
                // Arbitrary application headers and replayed bodies can carry
                // credentials. Let the caller choose a new destination request.
                return $response;
            }
            if ($hop >= $maxRedirects) throw new HttpClientException('External HTTP redirect limit exceeded.');
            $headers = $request->headers;
            $method = $request->method;
            $body = $request->body;
            $parts = $request->parts;
            if ($response->status() === 303 || (in_array($response->status(), [301, 302], true)
                && $method === 'POST')) {
                $method = 'GET'; $body = null; $parts = [];
                unset($headers['content-type']);
            }
            $request = new OutgoingRequest($method, $target, $headers, $body, $parts,
                $request->connectTimeout, $request->timeout, $request->verifyPeer,
                $request->caBundle, $request->maxBodyBytes, $request->maxRequestBytes,
                $request->sinkPath, $request->sinkStream);
        }
    }

    private function fakeResponse(OutgoingRequest $request): ?HttpResponse
    {
        if ($this->fakes === null) return null;
        foreach ($this->fakes as $pattern => &$outcome) {
            $space = strpos($pattern, ' ');
            $method = $space === false ? null : substr($pattern, 0, $space);
            $urlPattern = $space === false ? $pattern : substr($pattern, $space + 1);
            if ($method !== null && strtoupper($method) !== $request->method) continue;
            $regex = '~\A' . str_replace('\\*', '.*', preg_quote($urlPattern, '~')) . '\z~D';
            if (!preg_match($regex, $request->url)) continue;
            if (is_array($outcome)) {
                if ($outcome === []) throw new HttpConfigurationException('HTTP fake sequence is exhausted.');
                $value = array_shift($outcome);
            } else {
                $value = $outcome;
            }
            if ($value instanceof HttpClientException) throw $value;
            return $value;
        }
        throw new HttpConfigurationException('No HTTP fake matched the outgoing request.');
    }

    private static function validFakeOutcome(mixed $outcome): bool
    {
        if ($outcome instanceof HttpResponse || $outcome instanceof HttpClientException) return true;
        if (!is_array($outcome) || !array_is_list($outcome) || $outcome === []) return false;
        foreach ($outcome as $entry) if (!self::validFakeOutcome($entry) || is_array($entry)) return false;
        return true;
    }

    private static function wait(int $milliseconds): void
    {
        if ($milliseconds > 0) usleep($milliseconds * 1000);
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme']);
        return $scheme . '://' . strtolower($parts['host']) . ':'
            . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /** Resolve common Location forms without allowing another URL scheme. */
    private static function redirectUrl(string $base, string $location): string
    {
        if (preg_match('~\A[A-Za-z][A-Za-z0-9+.-]*:~', $location)) return $location;
        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) return $parts['scheme'] . ':' . $location;
        if (str_starts_with($location, '/')) return $origin . $location;
        if (str_starts_with($location, '?')) return $origin . ($parts['path'] ?? '/') . $location;
        $directory = substr($parts['path'] ?? '/', 0, (strrpos($parts['path'] ?? '/', '/') ?: 0) + 1);
        $path = $directory . $location;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') array_pop($segments);
            elseif ($segment !== '.') $segments[] = $segment;
        }
        return $origin . implode('/', $segments);
    }
}
