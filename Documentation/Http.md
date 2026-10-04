# SqueHub v2 HTTP foundation

The v2 HTTP foundation is implemented inside the core repository. Both `index.php` and `public/index.php` load `Bootstrap/Web.php`; the public v1.x documentation remains unchanged.

Each Kernel request receives a framework-generated [correlation ID](Correlation.md) for response and scoped background propagation. Optional [Observability](Observability.md) measures the HTTP pipeline without recording request bodies, cookies, or authorization headers. The separate [SqueHub Studio](Studio.md) server is development-only and does not install an application route.

## Request

`App\Plugins\Request::capture()` reads PHP's request globals and raw body once. It is an exact alias for the framework's `App\Http\Request`. The resulting object keeps its transport method, URI, query, form input, cookies, files, headers, and server data independent of later global changes; an eligible POST form also has an effective route method. Route attributes can be attached separately with `setAttribute()` and read with `attribute()`.

```php
use App\Plugins\Request;

$request = Request::capture();
$email = $request->input('email');
$page = $request->query('page', 1);
$token = $request->bearerToken();
$file = $request->file('avatar'); // The original $_FILES entry.
```

`method()` is uppercase and normally equals the captured transport method. For a real POST form with a scalar `_method` body field of `PUT`, `PATCH`, or `DELETE`, it returns that effective routing method; `transportMethod()` still returns `POST`. The override accepts URL-encoded and multipart forms, plus manually constructed form Requests without a Content-Type. It ignores query, cookie, header, JSON, non-form, non-POST, invalid, and ambiguous Content-Type values. See [Forms and Validation UX](Forms.md) for `@method` and CSRF behavior. `uri()` preserves the captured URI, `rawPath()` reports its transport path without the query, and `path()` returns the [application-relative route path](#url-base-path-and-mounted-requests). `query()` reads only query parameters. `input()`, `all()`, `only()`, and `has()` read only the request body: form fields normally, or JSON when the content type is `application/json` or ends in `+json`. Query parameters do not overwrite body fields. `has()` checks whether a key exists, so false and empty string are present. `json()` returns an array for JSON content types; malformed or non-array JSON throws a 400 `MalformedJsonException`. An empty JSON body returns an empty array. Header lookup is case-insensitive. `contentType()` removes parameters such as a charset. `ip()`, `scheme()`, `host()`, and `port()` report effective request metadata under the [explicit trusted-proxy policy](#trusted-proxies-and-request-metadata); without that policy they use direct server values.

`validate($rules, $messages)` still returns an allowlisted array. Optional `validatedAs(DataClass::class, $rules = null, $messages = [])` validates that same body/upload snapshot before mapping declared constructor parameters to a typed PHP object. Omitted rules require the class's explicit `ValidatedData::rules()` contract. It does not read query, route, cookie, or header values into that object, instantiate a client-selected class, or bypass the existing browser/API validation response. See [Typed application data](ApplicationData.md).

Validated request mapping follows the normal HTTP error and validation boundaries. Verify the selected deployment profile and database-dependent validation rules separately.

## URL base path and mounted requests

`APP_BASE_PATH` selects the URL path prefix where this Application is mounted. `Config/Http.php` reads it as `http.base_path`; the default empty value means root deployment and preserves existing `/users` routes. The canonical configured form for a subdirectory is `/app` (or a nested prefix such as `/clients/acme`), without a trailing slash. This is a URL path, not a filesystem path, absolute URL, route-group prefix, or trusted-proxy setting. SqueHub does not guess it from `SCRIPT_NAME`, `PHP_SELF`, or `X-Forwarded-Prefix`.

A standalone captured `Request` has no selected Application mount yet, so `path()` initially reflects its raw path and `basePath()` is empty. The Kernel selects the Application's mount before routing, API classification, CORS checks, and route middleware; those stages see the application-relative `path()`.

For `APP_BASE_PATH=/app`, the Kernel maps the public transport path to an application path before routing:

| Request data | Example value | Meaning |
| --- | --- | --- |
| `$request->uri()` | `/app/users/15?tab=profile` | Captured URI, including query. |
| `$request->rawPath()` | `/app/users/15` | Parsed transport path, retaining the mount. |
| `$request->basePath()` | `/app` | Selected Application's normalized URL mount. |
| `$request->path()` | `/users/15` | Application-relative path used by routes, API scopes, and CORS path policy. |
| `$request->server('REQUEST_URI')` | Raw server value | Captured server metadata is not rewritten. |

`/app` and `/app/` map to the application's `/` route. A request outside the bounded mount, such as `/users`, `/application`, or `/app2`, does not match an application route and receives the normal safe 404 behavior. Query parameters remain intact. Path matching stays case-sensitive and retains SqueHub's existing decoded-segment security checks; encoded slashes, backslashes, dot traversal, and malformed encodings do not become a second way through the mount. Request-scoped mount state does not leak between Applications or successive Kernel requests.

Route declarations remain `Route::path('/users')`. The named `route('users.show', ['id' => 15])` helper generates a public path such as `/app/users/15` under this mount, while static route metadata and exported API operation paths remain `/users/{id}`. The global `asset('/assets/app.css')` helper similarly generates `/app/assets/app.css` for an application-owned public asset. Internal root-relative redirect locations such as `/login` become `/app/login`; an already mounted `/app/login` is not prefixed again, and external destinations are not rewritten. These are explicit generation and response boundaries: the View compiler does not rewrite arbitrary literal `href`, `src`, or `action` attributes. See [Routing](Routing.md#names-and-urls), [Forms](Forms.md), [Assets](Assets.md), and [Deployment](Deployment.md#subdirectory-mounts-and-shared-hosting).

Modern named-route results are public, mount-aware **paths**, not absolute URLs. The v1 compatibility helpers `url()` and `App\Core\Verification::tokenUrl()` still read raw `$_SERVER` values; they are neither trusted-proxy-aware nor base-path-aware. Do not use them for password-reset, verification, or other security-sensitive public links behind a proxy or subdirectory mount. Compose a reviewed canonical public origin, such as `https://example.com`, with a generated public route path such as `/app/verify/abc`. No canonical public-origin setting is inferred from an incoming Host header.

## Browser frontend navigation

An application can opt into [SPA fallback](SpaRouting.md) through `Config/Frontend.php`. After normal routes, explicit generic fallbacks, and method handling, an unmatched `GET` or `HEAD` request that explicitly accepts HTML may render a backend View shell. JSON/XHR requests, API scopes, missing asset files, unsafe methods, and framework-private paths keep their ordinary error behavior. The selected shell is a normal `View::response()` with `Cache-Control: private, no-store` and `Vary: Accept`; it can include a token from the current Session without putting that HTML in a shared cache.

`APP_BASE_PATH` is removed before the SPA prefix is evaluated. With `APP_BASE_PATH=/site`, a configured SPA prefix `/app` matches public `/site/app/...`; neither route declarations nor `frontend.spa.prefix` include `/site`. Backend API routes and [Session/CSRF](FrontendAuth.md) still run through the same Kernel. A frontend build changes asset resolution, not request authentication or routing precedence.

## Trusted proxies and request metadata

Forwarded request headers are untrusted by default. `Config/TrustedProxies.php` starts with no trusted proxies and `profile => 'none'`. Merely receiving `Forwarded`, `X-Forwarded-For`, `X-Forwarded-Proto`, or `X-Forwarded-Host` does not change `ip()`, `scheme()`, `host()`, `port()`, or host-restricted route matching. Loopback and private addresses are not trusted automatically. The first trust decision uses the immediate network peer in `REMOTE_ADDR`; a forwarding header cannot claim that its sender is a trusted proxy.

For a reverse proxy you control, list its exact IPv4/IPv6 address or CIDR range and select **one** header profile:

```php
<?php

return [
    'proxies' => ['192.0.2.10', '2001:db8:1234::/48'],
    'profile' => 'x-forwarded', // Or 'forwarded' for RFC Forwarded.
    'allowed_hosts' => ['app.example.com', '*.apps.example.com'],
];
```

The `forwarded` profile reads RFC `Forwarded`; the `x-forwarded` profile reads the compatible `X-Forwarded-*` family. SqueHub does not mix the two families to complete a partially supplied chain. Set `profile` to `none` to use direct metadata even if a peer is listed. The address list supports exact IPv4/IPv6 values and CIDRs. Invalid entries fail configuration, and universal `0.0.0.0/0` and `::/0` trust ranges are rejected. Select only known proxy addresses; a broad private-network range can include clients you did not intend to trust.

When the immediate peer is trusted, `ip()` walks the forwarded address chain from the nearest hop toward the client and returns the first untrusted, valid IP. It does not blindly take the leftmost value. An untrusted intermediate hop ends the walk, so any earlier address supplied by that hop cannot become authoritative. Obfuscated, malformed, or ambiguous forwarding data does not become a client IP. Malformed data in the selected trusted profile falls back to direct server metadata; it does not switch to the other header family.

| Immediate `REMOTE_ADDR` | Selected `X-Forwarded-For` chain | Configured trusted peers | Effective `ip()` |
| --- | --- | --- | --- |
| `203.0.113.50` | `198.51.100.99` | `192.0.2.10` | `203.0.113.50`: the immediate peer is not trusted. |
| `192.0.2.10` | `203.0.113.50` | `192.0.2.10` | `203.0.113.50`: one known proxy. |
| `198.51.100.20` | `203.0.113.50, 192.0.2.10` | Both proxy addresses | `203.0.113.50`: two known proxies. |
| `198.51.100.20` | `203.0.113.50, 192.0.2.10` | Only `198.51.100.20` | `192.0.2.10`: trust stops at the untrusted middle hop. |

For `Forwarded`, `proto` (`http` or `https`) and `host` come from the element at the last reached **trusted boundary**. With two known proxies, that can be an earlier element than the rightmost one; an untrusted middle hop prevents reading beyond its boundary. For `x-forwarded`, protocol and host must each be a single unambiguous value supplied through the trusted edge. A comma-separated protocol or host list is not guessed. `X-Forwarded-Port` is not consumed. `port()` uses an explicit effective Host port when present. A direct request can use its captured `SERVER_PORT`; a proxied request with no explicit public Host port uses the effective scheme default (80 or 443), never an internal listener port. Route host matching ignores ports. Forwarded host values receive the same strict host validation as direct Host values. A directly supplied `X-Forwarded-Proto: https` never turns an untrusted direct HTTP request into HTTPS.

A known proxy using the RFC profile may send `Forwarded: for="[2001:db8::50]";proto=https;host="app.example.com:8443"`. The quoted IPv6 address resolves to the plain address `2001:db8::50`; the Host still passes strict authority validation before route matching. `for=unknown` and obfuscated identifiers are not client IPs. The parser bounds header size and hop count and rejects malformed quoting, controls, repeated metadata, and invalid addresses. RFC `by=` is not used to establish proxy trust; only the actual `REMOTE_ADDR` can do that.

`Request::server('REMOTE_ADDR')`, `server('HTTP_HOST')`, and other `server()` values preserve the captured raw server metadata. `ip()`, `scheme()`, `host()`, and `port()` are the effective values for this Request; resolution is Application-scoped and cannot leak between Applications or later requests. An empty `allowed_hosts` list adds no hostname restriction. An exact entry accepts that host; `*.example.com` accepts a subdomain of `example.com`, not the apex or `evil-example.com`. A disallowed effective host is a generic HTTP 400, including within the safe API error boundary. This allowed-host rule is independent of whether the request passed through a trusted proxy. See [Deployment](Deployment.md) for direct and proxied examples.

`bearerToken()` parses a single strict `Authorization: Bearer` credential. It returns `null` for an absent, empty, malformed, oversized, or repeated Authorization header, including repeated equal values; it does not inspect cookies, query strings, or request bodies. A named [token guard](ApiTokens.md) uses this value through the existing Auth manager and responds with one generic 401 Bearer challenge for missing or invalid credentials.

`$request->validate($rules, $messages = [])` checks body/JSON input and uploaded files, returning only passing fields or throwing `ValidationException`. The Kernel renders JSON validation failures as structured 422; browser forms with a safe previous page receive a 303 redirect with errors and old input, while requests without a safe destination receive escaped HTML 422. Enabled [API response scopes](ApiResponses.md) use 422 `validation_failed` with field messages and a trusted request ID, without flashing or redirecting. Query, route, cookie, header, and server values are not merged. See [Validation](Validation.md) and [Forms](Forms.md). `files()` returns the original captured upload entries; validation uses `UploadedFile` wrappers internally.

Modern controller actions can receive the Request and named route parameters. `$request->route('id')` reads matched parameters without mixing them into body or query input. See [routing](Routing.md).

`$request->apiVersion()` returns the normalized version metadata of a successfully matched versioned route, or `null` for an unversioned route. The version belongs to this Request; it is not inferred from a query field or shared across requests. See [API versioning](ApiVersioning.md).

## Responses

`App\Plugins\Response` holds content, status, and headers until `send()` is called. It is an exact alias for the framework's `App\Http\Response`. `withHeader()` and `withContent()` return a copy. The sender sets the status and headers once when PHP headers are still available, then writes the body. If prior output has already sent headers, it skips both status and header changes while retaining normal body behavior. `send(true)` suppresses the body for HEAD, and 204/205/304 responses have no body.

```php
use App\Plugins\JsonResponse;
use App\Plugins\RedirectResponse;
use App\Plugins\Response;

return new Response('Hello', 200, ['Content-Type' => 'text/plain']);
return new JsonResponse(['success' => true], 201);
return new RedirectResponse('/dashboard'); // 302 by default.
```

`JsonResponse` uses strict JSON encoding and raises `ResponseEncodingException` if encoding fails. `RedirectResponse` sets `Location` without sending headers or exiting. The global `response()` helper also supports `response('Hello')` and `response()->json(['success' => true], 201)`. The container registers `ResponseFactory` for injection. Header names and values are validated before sending. [HTTP Responses](Responses.md) covers typed cookies, binary bytes, local downloads, deferred streams, and bounded single-file ranges.

`View::response($view, $data, $status, $headers)` returns a normal `Response` with complete, already rendered View HTML. It defaults to status 200 and `Content-Type: text/html; charset=UTF-8`; the caller may provide status and headers. It renders through the non-echoing `View::renderResult()` path and travels through normal middleware and sending. See [Returnable View Responses](ViewResponses.md). `View::fragment($view, $name, $data)` still returns a [Fragment render result](Fragments.md), not an HTTP Response. A controller can place its `html()` in `new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8'])`, or choose a `JsonResponse` carrying its HTML and finalized asset stacks. The controller explicitly selects the Fragment and transport; SqueHub does not infer it from AJAX headers.

`redirect('/dashboard')` returns a `RedirectResponse` for a strict application-root path and applies this Application's URL mount. `redirectBack($request, '/fallback')` uses the browser navigation service's safe previous-path policy and an explicit Request. The zero-argument `redirect()` form still exposes the v1 immediate header/exit object for compatibility; do not use its raw-Referer `back()` in new code. A named `route()` is already a public mounted path, so return `response()->redirect(route('dashboard'))` for that case. The global [Helpers guide](Helpers.md#responses-and-redirects) explains the two redirect forms and their boundaries. `response()->binary()`, `download()`, `file()`, and `stream()` return through the same response pipeline. `Response::withCookie()` attaches validated typed cookies to any response type. File and stream bodies are emitted lazily; an ordinary `View::response()` still captures its rendered HTML eagerly. See [HTTP Responses](Responses.md) for syntax, range opt-in, HEAD, and security boundaries.

Native session cookies are managed by PHP's session driver, not by `Response` cookie methods. `Bootstrap/Web.php` closes an opened session before sending the response body. Legacy bootstrap starts the Application-owned store eagerly for route and template compatibility. See [sessions](Sessions.md).

The Kernel runs [CSRF protection](Csrf.md) as global middleware before route dispatch in the normal web Application. Unsafe requests require a session-bound token even for JSON or `/api` paths unless explicitly excluded. A missing token on an unknown unsafe route returns 403 before route matching; safe methods retain normal 404/405 routing behavior.

When [CORS](Cors.md) is enabled for a path, a valid browser preflight can finish before global middleware, route middleware, and controllers. Other requests still use the normal Kernel flow. Applicable CORS headers are attached after response or exception rendering, so allowed-origin API errors remain readable by browser JavaScript; CSRF and Auth requirements remain in force for actual requests.

An application may enable the [browser security policy](BrowserSecurityPolicy.md) in `Config/Security.php`. The Kernel adds validated CSP and frame restrictions to HTML responses, and applicable Referrer-Policy, `nosniff`, and HTTPS-only HSTS to normal and error responses. The policy is off by default because current Views can contain inline style or script. HSTS uses the effective scheme after explicit trusted-proxy processing, not an untrusted forwarding header. The policy does not rewrite typed application cookies or stream/file bodies.

## Web lifecycle and legacy routes

```text
index.php / public/index.php
    -> Bootstrap/Web.php
    -> Request::capture()
    -> Application bootstrap and service providers
    -> config.php and legacy Bootstrap.php
    -> Kernel::handle(Request)
    -> Request correlation and optional API scope classification
    -> Optional CORS preflight response
    -> Global CSRF middleware for normal dispatch
    -> RouteDispatcher -> RouteMatcher -> optional route API version check
    -> Optional session auth, token auth, or ability route middleware
    -> MiddlewarePipeline -> ControllerDispatcher
    -> ResponseNormalizer or ExceptionHandler
    -> Version Vary and CORS response headers where applicable
    -> SessionStore::close()
    -> Response::send()
```

The database provider registers the Application-owned manager without opening PDO. A route that does not use the database does not need a database connection. Database work opens its configured connection on first use; see [database](Database.md). Root `Database.php` remains a direct legacy include and is not part of normal web startup.

`HttpServiceProvider` registers the Kernel, response normalizer, response factory, and exception handler. `RoutingServiceProvider` registers the Application-owned registry and primary RouteDispatcher. Existing `$router->add()` registrations are mirrored into that registry, so old and new route files share one web matcher. The Kernel accepts a `Dispatcher` and does not depend on the global `$router`. `LegacyRouterDispatcher` remains available for direct legacy compatibility. The new controller dispatcher captures output from handlers and views.

Return values normalize as follows:

| Handler result | HTTP response |
| --- | --- |
| `Response` | Used as returned; legacy echoed output is discarded |
| Array | JSON response; legacy echoed output is discarded |
| String, number, or `Stringable` | Captured output followed by the returned value |
| `null` | Captured output, or empty body |

An unmatched URI receives a 404 from the new matcher; a matching URI with an unsupported method receives 405 and an `Allow` header. The existing `$router->setNotFoundHandler(...)` callback is still honored. It may echo a view with `View::render('errors.404')`, return a string, or return a Response; the status remains 404. A matched route returning `null` receives status 200, including when it rendered a view by echoing. Mirrored legacy middleware retains its old call contract; new middleware uses the nested pipeline. Direct `header()` and `exit` behavior cannot be fully normalized yet.

Register other browser error pages in a web route file with `$router->setErrorHandler(500, fn () => View::render('errors.500'))`, or use `Route::error(403, fn () => View::render('errors.403'))`. Handlers take no arguments, run only for HTML requests, and retain the original 4xx/5xx status and framework headers such as `Allow`. The latest handler registered for a status wins. JSON errors keep their normal structured response. Normal validation redirect/JSON behavior remains separate.

```php
use App\Plugins\View;
use App\Plugins\Route;

$router->setNotFoundHandler(function () {
    return View::render('errors.404');
});

$router->setErrorHandler(500, function () {
    return View::render('errors.500');
});

Route::error(403, fn () => View::render('errors.403'));
```

These view names resolve from `Project/Views/Errors/404.squehub.php`, `500.squehub.php`, and `403.squehub.php`. A handler can also return a string or Response. If a handler fails, the normal status page is used and the failure is reported to the configured logger.

Without a callback, HTML errors can use `Project/Views/Default/Error/<status>.php` in both debug and production. In debug mode, generic errors other than 404 pass already-redacted diagnostic values as `$squehubErrorDebug` to the PHP fallback template; templates must escape values they display. The supplied 500 page displays these values, including a trace when available. Production templates and 404 pages receive no diagnostics. A missing or broken template falls back to the built-in page. ExceptionHandler-generated 404 responses use `Cache-Control: no-store`; missing routes never show a stack trace. The debug bar appears on HTML pages only when the Application's effective `app.debug` setting is true; JSON responses do not contain it. A stale legacy `DEBUG_MODE` constant does not enable the bar. With `php squehub start`, changing `APP_DEBUG` in `.env` takes effect when the browser makes its next request.

## Exceptions

The Kernel catches exceptions from dispatch and normalization and asks `ExceptionHandler` for a Response. The new matcher throws 404 or 405 HTTP exceptions, including an `Allow` header for 405. A failure during legacy bootstrap setup is also converted to an HTTP response. If Application configuration fails before boot completes, the entry point emits a generic 500 response.

Enable `Config/Api.php` with `['enabled' => true, 'paths' => ['/api']]` to select the [API error contract](ApiResponses.md) before routing. Matching uses exact slash boundaries and ignores query strings. Within that scope, managed errors receive fixed safe messages, stable codes, optional public details, and a trusted request ID. Debug mode never adds exception internals to this envelope. Explicit `ApiError` exceptions select it even outside API scope. Explicit custom JsonResponse error bodies and application redirects are preserved.

Outside API scope, when `app.debug` is false, errors show only a generic HTTP reason and status. When true, the handler adds the exception class, message, file, line, and trace, escapes HTML, and redacts known configured/environment secrets and the captured Authorization token. It does not dump request headers or environment arrays. Requests with JSON content type or JSON in `Accept` receive JSON errors; others receive HTML. The setup-required response runs before API classification, and enabled Health endpoints retain their minimal contracts.

The legacy Whoops/debug bootstrap remains a separate compatibility path. The v2 database path raises exceptions instead of terminating the process. Other direct legacy `header()` or `exit` paths may still bypass normal response handling and require future migration.

The Kernel starts a fresh [diagnostics](Diagnostics.md) context for each request and finishes it with the final response status, including errors and redirects. It also clears the [authentication](Authentication.md) guard's resolved identity cache before dispatch so a long-lived Application cannot reuse one request's user in the next. `$request->requestId()` provides the locally generated opaque ID, renewed for every Kernel invocation. Diagnostics shares that current ID. The `X-Request-ID` header is authoritative when enabled and always present on API responses even if the Diagnostics response header is disabled. Incoming IDs are not trusted. The [logger](Logging.md) receives safe correlation fields and reports uncaught HTTP failures at the exception boundary.

The legacy Whoops compatibility page omits GET, POST, file, cookie, and server payload tables. Its old top-level masker could not reliably protect nested values or arbitrary cookie names. Normal v2 HTTP errors use `ExceptionHandler`.

`authorize()->require(...)` throws `AuthorizationException` for a legitimate deny. API scope returns 403 `forbidden` with fixed safe text. Outside that scope, the Kernel returns 403 JSON with a `message` field or escaped HTML; it does not redirect. A custom policy message outside API scope is application-authored and must not contain secrets. `AuthorizationConfigurationException` and unexpected policy failures remain safe 500 errors in API scope, including debug mode. Normal denials do not generate log records. See [authorization](Authorization.md).

Named [rate-limit middleware](RateLimiting.md) returns 429 `rate_limited` in API scope, or the existing JSON (`{"message":"Too many requests."}`)/HTML outside it. The denial carries integer `Retry-After` and `X-RateLimit-*` headers; the Kernel still attaches `X-Request-ID`. A broken store or missing named policy is an infrastructure/configuration error and becomes a safe API 500, never an implicit permit.

## Application-facing Plugins import

Application code may import `App\Plugins\Request`, `App\Plugins\Response`, `App\Plugins\JsonResponse`, `App\Plugins\RedirectResponse`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
