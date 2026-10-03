<?php

declare(strict_types=1);

namespace App\HttpClient;

use App\Reliability\RetryPolicy;
use JsonException;

/** Clone-on-change request options keep one API call from changing the next. */
final class PendingRequest
{
    /** @var array<string,string> */
    private array $headers = [];
    /** @var list<array{0:string,1:string}> */
    private array $query = [];
    /** @var list<array<string,mixed>> */
    private array $parts = [];
    private string $format = 'json';
    private ?string $rawBody = null;
    private float $connectTimeout;
    private float $timeout;
    private bool $verifyPeer;
    private ?string $caBundle = null;
    private int $maxRedirects;
    private int $maxBodyBytes;
    private int $maxRequestBytes;
    private int $retryAttempts = 1;
    private int $retryDelayMs = 0;
    private ?RetryPolicy $retryPolicy = null;
    private bool $allowUnsafeRetry = false;
    private ?string $sinkPath = null;
    private mixed $sinkStream = null;

    /** @param array<string,mixed> $settings */
    public function __construct(private HttpClient $client, array $settings)
    {
        $this->connectTimeout = self::positiveTime($settings['connect_timeout'] ?? 5);
        $this->timeout = self::positiveTime($settings['timeout'] ?? 15);
        $verifyPeer = $settings['verify_peer'] ?? true;
        $maxRedirects = $settings['max_redirects'] ?? 5;
        $maxBodyBytes = $settings['max_body_bytes'] ?? 10485760;
        $maxRequestBytes = $settings['max_request_bytes'] ?? 10485760;
        $userAgent = $settings['user_agent'] ?? 'SqueHub/2';
        if (!is_bool($verifyPeer) || !is_int($maxRedirects)
            || $maxRedirects < 0 || $maxRedirects > 20
            || !is_int($maxBodyBytes) || $maxBodyBytes < 1
            || !is_int($maxRequestBytes) || $maxRequestBytes < 1
            || !is_string($userAgent) || $userAgent === '') {
            throw new HttpConfigurationException('HTTP client configuration is invalid.');
        }
        $this->verifyPeer = $verifyPeer;
        $this->maxRedirects = $maxRedirects;
        $this->maxBodyBytes = $maxBodyBytes;
        $this->maxRequestBytes = $maxRequestBytes;
        $this->headers['user-agent'] = $userAgent;
        self::validateHeaderValue($this->headers['user-agent']);
    }

    public function withHeaders(array $headers): self
    {
        $next = clone $this;
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new HttpConfigurationException('HTTP headers must be strings.');
            }
            self::validateHeaderName($name);
            self::validateHeaderValue($value);
            if (in_array(strtolower($name), ['host', 'content-length', 'transfer-encoding'], true)) {
                throw new HttpConfigurationException('HTTP transport-managed header cannot be overridden.');
            }
            $next->headers[strtolower($name)] = $value;
        }
        return $next;
    }

    public function withToken(#[\SensitiveParameter] string $token): self
    {
        if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new HttpConfigurationException('Bearer token is invalid.');
        }
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function withBasicAuth(#[\SensitiveParameter] string $username,
        #[\SensitiveParameter] string $password): self
    {
        if (str_contains($username, ':') || preg_match('/[\x00-\x1f\x7f]/', $username . $password)) {
            throw new HttpConfigurationException('Basic authentication credentials are invalid.');
        }
        return $this->withHeaders(['Authorization' => 'Basic ' . base64_encode($username . ':' . $password)]);
    }

    public function acceptJson(): self { return $this->withHeaders(['Accept' => 'application/json']); }
    public function asJson(): self { $next = clone $this; $next->format = 'json'; return $next; }
    public function asForm(): self { $next = clone $this; $next->format = 'form'; return $next; }
    public function multipart(): self { $next = clone $this; $next->format = 'multipart'; return $next; }

    public function field(string $name, string $value): self
    {
        self::fieldName($name);
        $next = $this->multipart();
        $next->parts[] = ['kind' => 'field', 'name' => $name, 'value' => $value];
        return $next;
    }

    /** The path is read by cURL; no file bytes are copied into PHP memory. */
    public function file(string $name, string $path, ?string $filename = null,
        string $mime = 'application/octet-stream'): self
    {
        self::fieldName($name);
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpConfigurationException('Multipart file is not readable.');
        }
        $next = $this->multipart();
        $next->parts[] = ['kind' => 'file', 'name' => $name, 'path' => $path,
            'filename' => self::filename($filename ?? basename($path)), 'mime' => self::mime($mime)];
        return $next;
    }

    /** Byte uploads are intentionally bounded by maxRequestBytes. */
    public function fileBytes(string $name, #[\SensitiveParameter] string $bytes,
        string $filename, string $mime = 'application/octet-stream'): self
    {
        self::fieldName($name);
        if (strlen($bytes) > $this->maxRequestBytes) {
            throw new HttpConfigurationException('Buffered HTTP request exceeds its size limit.');
        }
        $next = $this->multipart();
        $next->parts[] = ['kind' => 'bytes', 'name' => $name, 'bytes' => $bytes,
            'filename' => self::filename($filename), 'mime' => self::mime($mime)];
        return $next;
    }

    /** The caller keeps ownership; sending consumes bytes from its current cursor. */
    public function fileStream(string $name, mixed $stream, string $filename,
        string $mime = 'application/octet-stream'): self
    {
        self::fieldName($name);
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new HttpConfigurationException('Multipart source must be a readable stream.');
        }
        $mode = stream_get_meta_data($stream)['mode'];
        if (!str_contains($mode, 'r') && !str_contains($mode, '+')) {
            throw new HttpConfigurationException('Multipart source must be a readable stream.');
        }
        $next = $this->multipart();
        $next->parts[] = ['kind' => 'stream', 'name' => $name, 'stream' => $stream,
            'filename' => self::filename($filename), 'mime' => self::mime($mime)];
        $next->maxRedirects = 0;
        return $next;
    }

    public function withBody(#[\SensitiveParameter] string $body, string $contentType): self
    {
        self::validateHeaderValue($contentType);
        $next = $this->withHeaders(['Content-Type' => $contentType]);
        $next->format = 'raw';
        $next->rawBody = $body;
        return $next;
    }

    public function withQuery(array $query): self
    {
        $next = clone $this;
        foreach ($query as $name => $value) {
            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $item) {
                if (!is_scalar($item) && $item !== null) {
                    throw new HttpConfigurationException('Query value is invalid.');
                }
                $next->query[] = [(string) $name, is_bool($item) ? ($item ? '1' : '0') : (string) $item];
            }
        }
        return $next;
    }

    public function timeout(float $seconds): self
    { $next = clone $this; $next->timeout = self::positiveTime($seconds); return $next; }
    public function connectTimeout(float $seconds): self
    { $next = clone $this; $next->connectTimeout = self::positiveTime($seconds); return $next; }
    public function withoutVerifying(): self
    { $next = clone $this; $next->verifyPeer = false; return $next; }
    /** Security-sensitive callers can require TLS verification despite client defaults. */
    public function withPeerVerification(): self
    { $next = clone $this; $next->verifyPeer = true; return $next; }
    public function caBundle(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) throw new HttpConfigurationException('CA bundle is not readable.');
        $next = clone $this; $next->caBundle = $path; return $next;
    }
    public function followRedirects(int $maximum = 5): self
    {
        if ($maximum < 0 || $maximum > 20) throw new HttpConfigurationException('Redirect limit is invalid.');
        $next = clone $this; $next->maxRedirects = $maximum; return $next;
    }
    public function retry(int $attempts = 3, int $delay = 500): self
    {
        if ($attempts < 1 || $attempts > 10 || $delay < 0 || $delay > 30000) {
            throw new HttpConfigurationException('HTTP retry policy is invalid.');
        }
        // Calling retry() is the existing explicit authorization to replay an
        // unsafe request. The new policy API defaults to safe methods only.
        $next = clone $this;
        $next->retryAttempts = $attempts;
        $next->retryDelayMs = $delay;
        $next->retryPolicy = new RetryPolicy($attempts, $delay);
        $next->allowUnsafeRetry = true;
        return $next;
    }

    public function withRetryPolicy(RetryPolicy $policy, bool $allowUnsafe = false): self
    {
        $next = clone $this;
        $next->retryPolicy = $policy;
        $next->allowUnsafeRetry = $allowUnsafe;
        return $next;
    }
    public function maxResponseBytes(int $bytes): self
    {
        if ($bytes < 1) throw new HttpConfigurationException('Response size limit is invalid.');
        $next = clone $this; $next->maxBodyBytes = $bytes; return $next;
    }
    public function maxRequestBytes(int $bytes): self
    {
        if ($bytes < 1) throw new HttpConfigurationException('Request size limit is invalid.');
        $next = clone $this; $next->maxRequestBytes = $bytes; return $next;
    }
    public function sink(string $path): self
    {
        if ($path === '' || !is_dir(dirname($path))) throw new HttpConfigurationException('Download directory is invalid.');
        $next = clone $this; $next->sinkPath = $path; $next->sinkStream = null; return $next;
    }
    /** The caller owns the writable stream; failed transfers may leave partial bytes. */
    public function sinkStream(mixed $stream): self
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new HttpConfigurationException('Download sink must be a writable stream.');
        }
        $mode = stream_get_meta_data($stream)['mode'];
        if (!strpbrk($mode, 'waxc+')) {
            throw new HttpConfigurationException('Download sink must be a writable stream.');
        }
        $next = clone $this; $next->sinkStream = $stream; $next->sinkPath = null;
        $next->maxRedirects = 0;
        return $next;
    }

    public function get(string $url, array $query = []): HttpResponse
    { return $this->withQuery($query)->request('GET', $url); }
    public function head(string $url, array $query = []): HttpResponse
    { return $this->withQuery($query)->request('HEAD', $url); }
    public function options(string $url, array $query = []): HttpResponse
    { return $this->withQuery($query)->request('OPTIONS', $url); }
    public function post(string $url, array|string|null $data = null): HttpResponse
    { return $this->request('POST', $url, $data); }
    public function put(string $url, array|string|null $data = null): HttpResponse
    { return $this->request('PUT', $url, $data); }
    public function patch(string $url, array|string|null $data = null): HttpResponse
    { return $this->request('PATCH', $url, $data); }
    public function delete(string $url, array|string|null $data = null): HttpResponse
    { return $this->request('DELETE', $url, $data); }

    public function request(string $method, string $url, array|string|null $data = null): HttpResponse
    {
        $method = strtoupper($method);
        if (preg_match('/\A[A-Z][A-Z0-9-]*\z/D', $method) !== 1) {
            throw new HttpConfigurationException('HTTP method is invalid.');
        }
        self::validateUrl($url);
        if ($this->rawBody !== null && $data !== null) {
            throw new HttpConfigurationException('Raw body and request data cannot be combined.');
        }
        $url = $this->queryUrl($url);
        $headers = $this->headers;
        // Explicit application headers win. Framework propagation sends only
        // the safe correlation ID, never trace baggage or request headers.
        if (!array_key_exists('x-correlation-id', $headers)
            && ($correlationId = $this->client->correlationId()) !== null) {
            $headers['x-correlation-id'] = $correlationId;
        }
        $body = $this->rawBody;
        $parts = $this->parts;
        if ($data !== null) {
            if ($this->format === 'multipart') {
                if (!is_array($data)) throw new HttpConfigurationException('Multipart fields must be an array.');
                foreach ($data as $name => $value) {
                    if (!is_string($name) || !is_scalar($value)) {
                        throw new HttpConfigurationException('Multipart field is invalid.');
                    }
                    self::fieldName($name);
                    $parts[] = ['kind' => 'field', 'name' => $name, 'value' => (string) $value];
                }
            } elseif ($this->format === 'form') {
                if (!is_array($data)) throw new HttpConfigurationException('Form data must be an array.');
                $body = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
                $headers['content-type'] = 'application/x-www-form-urlencoded';
            } elseif (is_array($data)) {
                try { $body = json_encode($data, JSON_THROW_ON_ERROR); }
                catch (JsonException $exception) {
                    throw new HttpConfigurationException('JSON request body is invalid.', 0, $exception);
                }
                $headers['content-type'] = 'application/json';
            } else {
                $body = $data;
            }
        }
        if ($this->format === 'multipart' && $parts !== [] && isset($headers['content-type'])) {
            throw new HttpConfigurationException('Multipart Content-Type is generated by the transport.');
        }
        if ($body !== null && strlen($body) > $this->maxRequestBytes) {
            throw new HttpConfigurationException('Buffered HTTP request exceeds its size limit.');
        }
        $buffered = strlen($body ?? '');
        foreach ($parts as $part) {
            if ($part['kind'] === 'field') $buffered += strlen($part['value']);
            elseif ($part['kind'] === 'bytes') $buffered += strlen($part['bytes']);
        }
        if ($buffered > $this->maxRequestBytes) {
            throw new HttpConfigurationException('Buffered HTTP request exceeds its size limit.');
        }
        $possibleAttempts = $this->retryPolicy?->maxAttempts() ?? $this->retryAttempts;
        if ($this->sinkStream !== null && ($possibleAttempts > 1 || $this->maxRedirects > 0)) {
            throw new HttpConfigurationException('Caller-owned stream sinks cannot follow redirects or retry.');
        }
        foreach ($parts as $part) {
            if ($part['kind'] === 'stream' && ($possibleAttempts > 1 || $this->maxRedirects > 0)) {
                throw new HttpConfigurationException('Caller-owned multipart streams cannot follow redirects or retry.');
            }
        }
        return $this->client->send(new OutgoingRequest($method, $url, $headers, $body, $parts,
            $this->connectTimeout, $this->timeout, $this->verifyPeer, $this->caBundle,
            $this->maxBodyBytes, $this->maxRequestBytes, $this->sinkPath, $this->sinkStream),
            $this->retryAttempts, $this->retryDelayMs, $this->maxRedirects,
            $this->retryPolicy, $this->allowUnsafeRetry);
    }

    public static function validateUrl(string $url): void
    {
        // Normal calls may target private services, but must never become a
        // local-file fetch or smuggle URL credentials into safe exceptions.
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new HttpConfigurationException('HTTP URL must be an HTTP(S) address without embedded credentials.');
        }
    }

    public static function validateHeaderName(string $name): void
    {
        if (preg_match("/\A[A-Za-z0-9!#$%&'*+.^_`|~-]+\z/D", $name) !== 1) {
            throw new HttpConfigurationException('HTTP header name is invalid.');
        }
    }
    public static function validateHeaderValue(string $value): void
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new HttpConfigurationException('HTTP header value is invalid.');
        }
    }
    private static function positiveTime(mixed $value): float
    {
        if ((!is_float($value) && !is_int($value)) || $value <= 0 || $value > 300) {
            throw new HttpConfigurationException('HTTP timeout is invalid.');
        }
        return (float) $value;
    }
    private static function fieldName(string $name): void
    {
        if ($name === '' || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new HttpConfigurationException('Multipart field name is invalid.');
        }
    }
    private static function filename(string $filename): string
    {
        if ($filename === '' || preg_match('~[/\\\\\x00-\x1f\x7f]~', $filename)) {
            throw new HttpConfigurationException('Multipart filename is invalid.');
        }
        return $filename;
    }
    private static function mime(string $mime): string
    {
        self::validateHeaderValue($mime);
        if ($mime === '') throw new HttpConfigurationException('Multipart MIME type is invalid.');
        return $mime;
    }
    private function queryUrl(string $url): string
    {
        if ($this->query === []) return $url;
        $fragment = '';
        if (($at = strpos($url, '#')) !== false) {
            $fragment = substr($url, $at);
            $url = substr($url, 0, $at);
        }
        $pairs = [];
        foreach ($this->query as [$key, $value]) {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . implode('&', $pairs) . $fragment;
    }
}
