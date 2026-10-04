# Cross-origin resource sharing (CORS)

CORS lets browser JavaScript use a response across origins when an application explicitly permits it. SqueHub's CORS policy is disabled by default and scoped by path. Same-origin pages, mobile clients, CLI clients, and server-to-server calls do not need CORS configuration to reach normal routes. CORS does not authenticate a caller or grant a [CSRF](Csrf.md) exemption.

For critical browser API routes, register explicit [verification cases](ApiVerification.md) that send Origin and preflight headers through the real Kernel. Contract declaration alone cannot prove an origin is allowed, denied, or decorated correctly after a managed error.

## Enable a policy

Configure `cors` in `Config/Api.php` for the API paths that should be readable by a selected browser origin:

```php
return [
    'enabled' => true,
    'paths' => ['/api'],
    'cors' => [
        'enabled' => true,
        'paths' => ['/api'],
        'allowed_origins' => ['https://app.example.com'],
        'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'allowed_headers' => ['Content-Type', 'X-CSRF-Token'],
        'exposed_headers' => ['X-Request-ID'],
        'allow_credentials' => true,
        'max_age' => 600,
    ],
];
```

The outer `enabled` and `paths` select the [managed API error contract](ApiResponses.md). The nested `cors` options independently control CORS. Both default to disabled. A literal path scope matches itself and descendants at a slash boundary: `/api` includes `/api/users` but not `/apiary`. An empty or unrelated scope does not alter other routes. The default `/api` scope leaves built-in Health endpoints unchanged, and the setup-required response occurs before normal Application CORS handling.

When an Application has `APP_BASE_PATH=/app`, a public preflight to `/app/api/users` is probed as application path `/api/users`; keep the CORS path scope `/api`. The `Origin` header still names only the scheme, host, and optional port (for example `https://example.com`), never `/app`. A mount neither grants a new origin nor changes credential or CSRF policy. Requests outside the configured mount do not enter the application's CORS route scope.

An origin consists of scheme, host, and optional port. Scheme and host are normalized to lowercase, and an explicit default port (`80` for HTTP or `443` for HTTPS) is equivalent to omitting it. The policy compares the complete normalized origin, so `http://app.example.com`, `https://app.example.com:8443`, and `https://app.example.com.attacker.test` do not match the example origin. Development origins such as `http://localhost:5173` must be listed explicitly; local mode does not authorize all localhost ports or `127.0.0.1` automatically. A malformed request Origin is denied; invalid configuration fails Application boot.

The literal `null` origin is denied unless explicitly configured. It can be sent from sandboxed documents and local/file contexts, so allow it only for a deliberate use case. An explicit `'*'` origin can be used without credentials; it does not include `null`. `allow_credentials => true` with `'*'` is invalid configuration and is rejected, never silently downgraded.

## Methods and request headers

`allowed_methods` controls which methods the browser may use across origins. CORS also checks that the target route accepts the requested method during preflight; listing a method cannot create a route. Method comparison is case insensitive after normalization. Browser request headers are checked against `allowed_headers` case insensitively. A header absent from the list is not automatically permitted because a route reads it.

The example explicitly permits `Content-Type` and `X-CSRF-Token` for a session-based JSON client. If `Config/Csrf.php` uses another CSRF header name, list that configured name instead. If [header-based API versioning](ApiVersioning.md) is enabled, also list its configured version header. A cross-origin client using a [personal access token](ApiTokens.md) must have `Authorization` explicitly allowed; token authentication does not alter CORS policy or permit arbitrary origins.

`exposed_headers` controls which non-safelisted response headers browser JavaScript may read. `X-Request-ID` is useful when a client reports a managed API error, but it is exposed only when configured. `Cookie` and `Set-Cookie` are rejected in `exposed_headers`. Do not list authorization data, debug details, or rate-limit data without a deliberate disclosure decision.

Policy options are validated during Application boot. Paths must be literal internal prefixes; configured Origins, methods, and header names must have valid bounded syntax. `max_age` accepts an integer from 0 through 86,400 seconds. Invalid settings fail clearly rather than producing a permissive fallback.

## Browser preflight

A genuine preflight is an `OPTIONS` request with both `Origin` and `Access-Control-Request-Method`; it may also include `Access-Control-Request-Headers`. For a permitted origin, method, request headers, path, and matching route, SqueHub answers with an empty HTTP 204 response and the applicable `Access-Control-Allow-*` headers. The controller, route middleware, business logic, and database handler are not invoked. `Access-Control-Max-Age` follows the configured `max_age`.

A denied genuine preflight receives a safe 403 `cors_preflight_denied` error through the existing API error contract. It does not reveal the allowed-origin list or grant an `Access-Control-Allow-Origin` header to a denied origin. An `OPTIONS` request with only `Origin`, or without either required preflight header, continues to an explicitly registered application OPTIONS route or normal routing behavior. If it has an allowed Origin and `OPTIONS` is an allowed actual method, it can receive ordinary CORS response headers.

For reflected specific origins, `Vary` includes `Origin`. Preflight responses also vary on `Access-Control-Request-Method` and `Access-Control-Request-Headers` because those values affect the result. SqueHub merges these names with existing `Vary` values without duplicates.

## Actual responses and credentials

An allowed Origin using an allowed method receives the applicable CORS headers on the normal response, including a successful [resource](ApiResources.md), a validation or authentication failure, routing 404/405, rate-limit 429, and a managed API 500. A denied Origin or method does not receive `Access-Control-Allow-Origin`; the underlying request continues through the usual application path. A request with no Origin header also continues normally and receives no CORS permission header. Scoped responses carry `Vary: Origin` even for absent or denied origins so a cache cannot reuse an allowed-origin response for another request. Within the configured scope, the policy controls `Access-Control-*` headers and removes downstream copies before making its own grant decision.

With `allow_credentials => true`, SqueHub reflects a specifically allowed origin and emits `Access-Control-Allow-Credentials: true`. Browser clients must opt into credential sending, and their cookies must satisfy the browser's SameSite and secure-cookie rules. CORS controls browser access to a cross-origin response; it does not make a session cookie a CSRF defense. Unsafe session-backed requests still need the configured synchronizer token, even when the Origin is allowed. Authentication and authorization continue to run through their existing guards and policies.

The policy does not add fields to successful JSON resources or replace [managed API error bodies](ApiResponses.md). It only adds applicable HTTP headers. It does not add frontend tooling, token authentication, or a permissive global CORS preset.
