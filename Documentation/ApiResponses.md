# API responses and errors

An explicit [Application Contract](ApplicationContract.md) can describe the JSON error envelope with `Contract::operation()->error(404)` or another supported status. It reuses the actual `error.code`, `error.message`, optional `error.details`, and `request_id` shape. Declaring a response does not force a route to emit it and does not automatically add every framework error status to every operation.

An [API verification case](ApiVerification.md) can deliberately trigger a declared error branch through the real Kernel and ExceptionHandler. It checks the response status, content type, and declared JSON shape without copying the error schema into the case. The report omits actual request IDs and bodies.

The opt-in API error contract integrates with the existing HTTP Kernel, Router, middleware, and ExceptionHandler. Configure API paths once, then use ordinary routes, request validation, authentication, and authorization. Successful [API resource](ApiResources.md) output keeps its existing shape.

[Generated clients](SdkGeneration.md) recognize this `error`/`request_id` envelope and expose safe error fields. HTML proxy errors, invalid JSON, and an undeclared success response are separate protocol failures. Generation does not turn an undeclared server response into a valid API error contract.

## Enable API response mode

The shipped `Config/Api.php` disables API response mode. Enable it explicitly:

```php
return [
    'enabled' => true,
    'paths' => ['/api'],
];
```

Each configured path matches itself and descendants at a slash boundary. Matching is case-sensitive and uses `Request::path()` before route matching. It does not depend on a matching route or `Accept` header.

In a mounted deployment, `Request::path()` is application-relative. With `APP_BASE_PATH=/app`, `/app/api/users` matches the configured `/api` scope, including when the mounted route is missing; `/app/apiary` does not. A request outside `/app` does not enter the application's API scope. Keep scopes and route declarations portable by leaving the deployment mount out of both.

| Request URI | Matches `/api` |
| --- | --- |
| `/api` | Yes |
| `/api/users` | Yes |
| `/api/orders/123?expand=items` | Yes |
| `/apiary` | No |
| `/API/users` | No |
| `/web?next=/api` | No |

Configure up to 64 prefixes, each at most 1,024 bytes, when needed. Use literal paths with a leading slash; query strings, fragments, wildcards, regular expressions, traversal, and ambiguous encoded spellings are not configuration syntax. A trailing slash is normalized away except for `/`. A deliberate `paths => ['/']` opts the whole application into the contract, with the Health exclusions below. An empty path list matches nothing. Invalid configuration fails safely.

Outside configured scopes, existing HTML, browser redirects, and legacy JSON negotiation continue to apply. Sending `Accept: application/json` alone does not activate this contract. API response mode does not grant authentication, authorization, or CSRF exemptions.

## Error shape

Managed errors return the existing `JsonResponse` with an `error` object and a server-generated request ID:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The submitted data is invalid.",
    "details": {
      "email": [
        "The email field is required."
      ]
    }
  },
  "request_id": "7e2f4c9038d1a6b5920ec4a7f8d31b65"
}
```

`error.code` is a stable machine-readable identifier. `error.message` is public text. `error.details` is optional and omitted when no public details are supplied. Clients should branch on the code and HTTP status rather than compare messages.

An unexpected failure has this shape in both debug and production:

```json
{
  "error": {
    "code": "internal_error",
    "message": "An unexpected error occurred."
  },
  "request_id": "7e2f4c9038d1a6b5920ec4a7f8d31b65"
}
```

The example ID is illustrative; every handled request receives a fresh value.

## Standard codes

| HTTP status | Code | Source condition |
| --- | --- | --- |
| 400 | `bad_request` | Malformed request JSON or an explicit HTTP 400 exception |
| 401 | `unauthenticated` | The `auth` middleware requires an identity, or an explicit HTTP 401 exception |
| 403 | `forbidden` | Authorization denial, guest-only route rejection, CSRF rejection, or an explicit HTTP 403 exception |
| 404 | `not_found` | No matching route, or an explicit HTTP 404 exception |
| 405 | `method_not_allowed` | Existing path does not allow the method; `Allow` is preserved |
| 409 | `conflict` | An explicit HTTP 409 exception |
| 422 | `validation_failed` | Existing validation failure, or an explicit HTTP 422 exception |
| 429 | `rate_limited` | Named rate-limit rejection, or an explicit HTTP 429 exception |
| 500 | `internal_error` | Unexpected exception, error rendering failure, or explicit HTTP 500 exception |
| 503 | `service_unavailable` | An explicit HTTP 503 exception |
| Other 4xx/5xx | `http_error` | An explicitly declared HTTP error status without a standard catalogue entry |

API versioning and CORS policy failures use this same envelope: [API versioning](ApiVersioning.md) can return 400 `api_version_required`, `unsupported_api_version`, or `api_version_conflict`; a denied genuine [CORS preflight](Cors.md) returns 403 `cors_preflight_denied`. These codes do not reveal route metadata or the configured origin list.

Framework messages are fixed public text. Exception messages, authorization policy messages, previous exceptions, SQL, traces, classes, and source locations are not copied into API output. Arbitrary `InvalidArgumentException`, database exceptions, and backend failures become generic 500 errors; they are not guessed to be bad requests or conflicts.

## Validation and security middleware

`$request->validate()` still performs one validation pass and throws the existing `ValidationException`. API failures return 422 `validation_failed`; `details` contains its field-to-message lists after safety checks and redaction. Field keys must be nonempty strings and each value must be a list of strings; a malformed validation-error map receives the safe 500 fallback. Multiple errors retain their existing order. No submitted input or validation errors are flashed into Session, and no validation redirect is issued. Browser form redirects and filtered old input remain available outside API scope. See [Validation](Validation.md) and [Forms](Forms.md).

The `auth` middleware returns 401 `unauthenticated` with `Authentication is required.` for an API guest. The `guest` middleware returns 403 `forbidden` for an authenticated identity. Named [token authentication](ApiTokens.md) uses explicit token middleware, the same safe error envelope, and a Bearer challenge on its 401 response. Session browser login/authenticated redirects retain their existing behavior outside the scope. See [Authentication](Authentication.md).

Existing authorization decisions still determine access. A denial returns 403 `forbidden` with `This action is not allowed.`, without the policy's message, class, ability implementation, identity, or resource. `RequireTokenAbility` uses the same safe 403 when an authenticated token lacks its requested ability; it does not reveal the token's ability list. Unknown application Authorization abilities and failed policies remain unexpected 500 errors. See [Authorization](Authorization.md) and [API tokens](ApiTokens.md).

Global [CSRF](Csrf.md) remains enabled for unsafe methods, including API paths. A missing or invalid session-bound token returns safe 403 `forbidden` before route middleware or controller validation. An unsafe unknown API route can therefore return 403 before routing would return 404. Normal CSRF exclusions remain a separate explicit security decision.

Named [rate limits](RateLimiting.md) still consume permits at their configured middleware position. A denial returns 429 `rate_limited` and preserves `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset`. Denied attempts do not move the fixed window's reset time. Backend failures propagate as safe 500 errors and never grant a permit.

## Deliberate application errors

Throw `App\Plugins\ApiError` when application logic intentionally exposes an error:

```php
use App\Plugins\ApiError;

throw ApiError::make(
    code: 'order_conflict',
    message: 'The order cannot be changed.',
    status: 409,
    details: ['reason' => 'order_closed'],
);
```

`ApiError` is an exact alias of the final `App\Api\ApiError` HTTP exception. It works through the same ExceptionHandler and explicitly selects this JSON contract even outside a configured API path. Throw the object; it has no `response()` method. The handler owns request correlation, configuration, encoding, and fallback behavior.

`make()` accepts a stable `code`, a nonempty safe public `message`, an HTTP error `status` from 400 through 599 (default 400), an optional `details` array, and optional response `headers`. Codes contain 1–64 characters, start with a lowercase letter, and use only lowercase letters, digits, and underscores. Standard Response header validation applies. Use headers only when they are appropriate for the error; framework JSON content type, no-store policy, and correlation remain authoritative. Within API scope, invalid declarations fail as generic 500 errors at the HTTP boundary.

Public messages and details are intentional application output. Select only information the client may receive. Never pass raw request data, Models, credentials, connection settings, or exception messages as details. The renderer filters sensitive keys and redacts known configured/request secrets, but it cannot infer every business-confidential value.

Credential inspection recognizes scalar values under sensitive request keys, including numeric credentials, all captured cookie values, and the configured CSRF field and header even when their custom names do not resemble secrets. The scan shares a 10,000-value budget and a depth limit of 32. Exceeding either limit fails closed with the generic 500 fallback instead of returning data after an incomplete privacy check. Malformed JSON remains eligible for the normal safe 400 response.

## Correlation and logging

`$request->requestId()` returns an opaque 32-character hexadecimal ID generated from secure local randomness. The Kernel renews it for every `handle()` call, including when a Request object is reused. It contains no identity, session, host, or request data, and incoming `X-Request-ID` values are not trusted or echoed.

Managed error bodies and their `X-Request-ID` header use the same ID. Scoped successful responses also receive that header, without an added JSON body field. The header is authoritative for API responses even when `diagnostics.response_header` is disabled. Existing Diagnostics uses the same current request ID; no list of historical IDs is added to its aggregate metrics.

Where Logging is installed, the existing exception boundary includes trusted correlation for managed failures. The API renderer does not add duplicate exception logs or log error details and request payloads. Routine validation, authorization denials, missing routes, and rate-limit denials retain their existing quiet logging policy. See [Logging](Logging.md) and [Diagnostics](Diagnostics.md).

## Serialization and privacy

The optional details array accepts nulls, strings, integers, finite floats, booleans, and nested arrays of those values. Arbitrary objects, Models, streams, and serialization callbacks are not accepted. Validation of the message/details tree is bounded to depth 32 and 10,000 values; circular or excessively nested data is rejected. Invalid UTF-8, `INF`, and `NAN` also fail strict encoding. An explicit empty details array encodes as `[]`; omitting details leaves the member absent.

If rendering fails, the handler returns a known-safe generic 500 envelope with the current request ID. It does not partially encode invalid values or recursively call itself. `APP_DEBUG=true` does not add exception internals to managed API JSON. Development details remain governed by the existing browser diagnostics behavior outside API scope.

## Headers and compatibility

Managed API errors use `Content-Type: application/json; charset=UTF-8` and `Cache-Control: no-store`. Existing meaningful headers such as `Allow` and rate-limit headers survive. A stale `Content-Length` is removed when an error body is created. HEAD responses emit no body, and 204/205/304 responses receive no added JSON body.

Successful resources, collections, metadata, and pagination retain their documented JSON shape. An explicitly returned `JsonResponse` with a custom 4xx/5xx body keeps that body; the framework does not parse or replace it. Explicit application redirects also retain their behavior. Existing response emission rules still apply when PHP has already sent headers.

Enabled `/health/live` and `/health/ready` endpoints retain their minimal [Health](Health.md) contracts and cache policy, even when a configured API prefix would include them. The setup-required 503 response is produced before normal Application bootstrap and API classification, so its existing HTML/JSON shape remains. Doctor and CLI setup notices are unchanged.

The API error contract remains the envelope used for managed [API version](ApiVersioning.md), [CORS](Cors.md), and [personal access token](ApiTokens.md) failures. This envelope does not define contracts for JWT, OAuth/OIDC, webhooks, OpenAPI/SDK generation, idempotency, frontend integration, typed data objects, route model binding, or telemetry.
