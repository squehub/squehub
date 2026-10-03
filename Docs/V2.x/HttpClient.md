# Outgoing HTTP Client

The [correlation context](Correlation.md) can flow to same-origin outbound HTTP calls; a cross-origin redirect is returned without making another request. Optional [Observability](Observability.md) records bounded operation metadata and timing, not request bodies or authorization headers. Studio reads only the resulting safe [development profiles](Profiler.md).

[Doctor](Health.md) checks cURL availability and production TLS verification policy without sending an outbound request.

SqueHub's outgoing HTTP Client calls external HTTP(S) services. It is separate from incoming `App\Http\Request` and `Response`, and it does not require Database, Redis, Queue, Session, Mail, or Storage. `App\Plugins\Http` resolves the current Application's `App\HttpClient\HttpClient`; `httpClient()` and canonical `App\HttpClient\Http::client()` reach the same service.

```php
use App\Plugins\Http;

$response = Http::get('https://api.example.com/users', ['page' => 2]);

$response = Http::withToken($token)
    ->acceptJson()
    ->timeout(10)
    ->post('https://api.example.com/orders', ['amount' => 5000]);

if ($response->successful()) {
    $data = $response->json();
}
```

The gateway supports `get`, `post`, `put`, `patch`, `delete`, `head`, `options`, and `request($method, $url, $data)`. Array bodies are JSON by default. Builder methods return a clone, so changes to one `PendingRequest` do not alter an earlier or unrelated builder. `withQuery()` appends RFC 3986 encoded query pairs; list values repeat the key. Existing query strings are retained.

## Bodies, headers, and authentication

```php
Http::asForm()->post($url, ['username' => $name, 'password' => $password]);
Http::withBody($xml, 'application/xml')->post($url);
Http::withHeaders(['X-API-Version' => '2026-01'])->get($url);
Http::withBasicAuth($username, $password)->get($url);

Http::multipart()
    ->field('name', 'document')
    ->file('document', $path, 'document.pdf', 'application/pdf')
    ->post($url);
```

`fileBytes($field, $bytes, $filename, $mime)` sends bounded in-memory bytes. `fileStream($field, $stream, $filename, $mime)` copies at most the configured request limit to a temporary file before cURL sends it, consumes the caller stream from its current cursor, and never closes the caller stream. It disables redirects and rejects retries because the original stream cannot be replayed safely. `file()` lets cURL read a local file path without copying all bytes into PHP memory. Multipart filenames are logical names and cannot contain path separators. Buffered fields, byte files, and raw bodies have a default 10 MiB limit; local file paths are streamed by cURL. The transport generates multipart boundaries and `Content-Length`. Request header names and values reject control characters, including CR/LF; applications cannot override `Host`, `Content-Length`, or `Transfer-Encoding` through `withHeaders()`.

`withToken()` sets Bearer authorization. `withBasicAuth()` sets Basic authorization. Credentials, headers, body, and query values are never included in automatic diagnostics or safe framework exception messages. Put API secrets in application configuration; `Config/HttpClient.php` holds only generic transport defaults.

## Responses and failures

`HttpResponse` exposes `status()`, `body()` (unchanged bytes), `headers()` (lowercase names and lists preserving repeated values), `header($name)` (first value), `successful()` (2xx), `failed()` (everything else), `clientError()`, `serverError()`, and `json()`. Valid JSON `null` returns PHP `null`; an empty or malformed body raises `HttpDecodeException`. No automatic charset conversion occurs.

HTTP 404, 422, 429, and 500 are completed exchanges and return `HttpResponse`. Call `$response->throw()` to raise `HttpStatusException` for 4xx/5xx. Network failure raises `HttpConnectionException`; deadline expiry raises `HttpTimeoutException`. Configuration errors and oversized buffered responses raise safe `HttpClientException` subclasses. Safe exceptions do not include the request URL, query, credentials, or server body.

## Transport, TLS, redirects, and retries

The first-party transport requires PHP cURL. It creates a new handle for each attempt, verifies TLS certificates and hostnames by default, accepts a custom `caBundle($path)`, and supports explicit `withoutVerifying()` for controlled development only. There is no PHP streams fallback. The defaults are five seconds to connect, 15 seconds overall, five redirects, and 10 MiB for an in-memory response. `connectTimeout()` and `timeout()` accept positive seconds. `maxResponseBytes()` changes the buffered response limit.

Redirects are handled by the framework and followed only when the origin (scheme, host, and port) stays the same. A cross-origin `Location` returns the 3xx response without sending a second request, even when automatic redirects are enabled. This prevents application-defined credential headers such as `X-API-Key`, as well as bodies replayed by 307/308, from reaching another origin. To continue intentionally, validate `Location` against the integration's destination allowlist and create a separate request with only the credentials appropriate for that destination. Only HTTP(S) redirect targets are accepted while following is enabled. For same-origin redirects, a 303 becomes GET; a POST followed by 301/302 becomes GET; 307/308 preserve method and body. `followRedirects(0)` returns the redirect response; exceeding a positive maximum for followed same-origin redirects raises a safe exception. No cookie jar is maintained.

`retry(attempts: 3, delay: 500)` remains an explicit fixed-delay request, with delay in milliseconds. It retries connection failures, timeouts, and statuses 429, 502, 503, and 504, including for unsafe methods as its established explicit contract. For a bounded reusable policy, import `App\Plugins\RetryPolicy` and use `Http::withRetryPolicy($policy)`. That form retries GET/HEAD only by default; pass `allowUnsafe: true` deliberately for POST/PATCH or other mutations after establishing the remote endpoint's idempotency behavior. A valid `Retry-After` hint on a retryable status is honored within the policy's delay and elapsed bounds. See [Shared retry policy](Retries.md) for timing, examples, and Queue/Webhook boundaries.

`sink($path)` writes a complete 2xx response to a temporary file in the destination directory, then renames it. The destination directory must exist and an existing destination is rejected. Error responses and failed transfers remove the temporary file. `sinkStream($stream)` writes into a caller-owned writable stream, advances its cursor, and never closes it. It disables redirects and rejects retry configuration because a partial earlier attempt could contaminate later output. File sinks and stream sinks return an empty buffered `body()`. The caller is responsible for any partial bytes written to a stream after a transport failure.

`maxResponseBytes()` limits buffered responses, not streamed sink output. Applications downloading from untrusted endpoints must provide their own destination, size, and disk-capacity policy before choosing a sink.

## Fakes and diagnostics

```php
Http::fake([
    'GET https://api.example.com/users*' => Http::response(['users' => []]),
    'POST https://api.example.com/orders' => [
        Http::response('busy', 503),
        Http::response(['ok' => true], 200),
    ],
]);

$sent = Http::captured();
Http::resetFake();
```

Fake patterns accept an exact URL, `*` wildcard, or a method followed by a URL pattern. A fake can return a response, a `HttpConnectionException`/`HttpTimeoutException`, or a sequence of those outcomes. An unmatched fake fails clearly and never reaches the network. Fakes and captured requests belong to one Application. Captured requests intentionally include sensitive values for test assertions; do not print or persist them in production.

`diagnostics()->snapshot()['http_client']` contains only `requests`, `attempts`, `successful`, `failed`, `retried`, and `time_ms`. A logical call increments `requests`; transport/fake exchanges (including redirects and retries) increment `attempts`. `time_ms` includes deliberate retry waits. Counts reset at each incoming Kernel request. No URL, host, method, header, body, token, response bytes, or file name is retained.

Only HTTP(S) schemes are permitted. This is not a complete SSRF defense: applications accepting user-controlled URLs must enforce their own destination allowlist, while internal/private services remain intentionally usable. The separate [OIDC login client](OAuth.md) applies its own trusted-host policy to discovery and token exchange. The [Webhook subsystem](Webhooks.md) adds named destinations, exact-byte signing, and its own bounded retry rules on top of this transport. Security-sensitive integrations can call a pending request's `withPeerVerification()` method to require TLS peer checks even when the general client default is relaxed; Webhook delivery does so unconditionally for HTTPS. The general HTTP Client has no provider SDK, proxy/PAC support, cookie jar, or HTTP/2 requirement.
