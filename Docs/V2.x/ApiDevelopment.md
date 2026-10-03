# Build JSON endpoints with the current HTTP foundation

SqueHub v2 can receive JSON, validate it, query Models, return explicitly selected resource fields, and render consistent API errors without manually reading `php://input`. This guide builds on [API resources](ApiResources.md) and [API responses and errors](ApiResponses.md). Explicit [API version metadata](ApiVersioning.md), opt-in [CORS](Cors.md), named [personal access token guards](ApiTokens.md), and the separate [SqueHub Webhook profile](Webhooks.md) are available for applications that need them. [Application contracts](ApplicationContract.md) describe chosen routes and export OpenAPI 3.2.1 without changing dispatch; the [API verification gate](ApiVerification.md) checks selected behavior through the real HTTP Kernel; and [client SDK generation](SdkGeneration.md) emits standalone TypeScript, JavaScript, and PHP callers for that declared subset. The bounded [`make:feature` blueprint](FeatureBlueprints.md) can generate an initial GET index route and resource; it does not infer CRUD operations or an API contract.

## Enable the error contract

Set `Config/Api.php` to opt in for selected paths:

```php
return ['enabled' => true, 'paths' => ['/api']];
```

This matches `/api` and its descendants before route matching, including missing routes. It does not match `/apiary` or inspect the query string. With `APP_BASE_PATH=/app`, the public request `/app/api/users` has application path `/api/users`, so the same configuration applies. Do not add `/app` to `Config/Api.php` scopes or to `Route::path()` declarations. The default is disabled. Managed errors use `error.code`, `error.message`, optional `error.details`, and a server-generated `request_id`; successful resource bodies keep their existing shape. See [API responses](ApiResponses.md) for the catalogue, request IDs, custom `ApiError` exceptions, and privacy guarantees.

## Request and response

```php
namespace Project\Controllers;

use App\Plugins\Request;
use App\Plugins\JsonResponse;
use Project\Api\Resources\UserResource;
use Project\Models\User;

final class UserApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
        ]);

        $user = User::create($data);

        return UserResource::make($user)->response(201);
    }
}
```

```php
use App\Plugins\Route;
use Project\Controllers\UserApiController;

Route::path('/api/users')->post([UserApiController::class, 'store']);
```

Send `Content-Type: application/json` with an object body. `Request::input()` and `validate()` use JSON body fields; query parameters remain separate behind `query()`. In API scope, invalid JSON produces 400 `bad_request`, validation produces 422 `validation_failed` with field messages in `error.details`, and missing routes produce 404 `not_found`. Unexpected failures return a generic 500 `internal_error` even in debug mode. Outside API scope, existing content-type/`Accept` negotiation and browser behavior apply. `response()->json()` uses strict encoding; unsupported values raise a response encoding error. See [HTTP](Http.md) and [Validation](Validation.md).

The example assumes a `users` table, a modern User Model with `name` and `email` in `$fillable`, and the `UserResource` shown in [API resources](ApiResources.md), which selects only `id` and `name`. The `unique` validation rule queries raw table rows, including soft-deleted rows. Keep a database unique constraint as the final write guard. Do not serialize sensitive Model fields; configure `$hidden` where Models are serialized elsewhere. A validation rule is not authorization.

Resources live by convention under `Project/Api/Resources/` and extend `App\Plugins\ApiResource`. Return `->response()` from a controller; directly returned resources have no special dispatcher support. A resource is bare by default, `withMeta()` adds a `data`/`meta` envelope, and a Page collection always has that envelope. `response()->json()` remains available for an explicit array payload.

### Optional typed request data

For a larger application, `Request::validatedAs(CreateUserData::class)` can use the class's explicit `App\Plugins\ValidatedData::rules()`, or the controller can pass rules as its second argument. The existing Validator runs **before** strict constructor mapping. This is an additive input option; the array `$request->validate([...])` example above remains first-class.

```php
$data = $request->validatedAs(CreateUserData::class);
$user = User::create([
    'name' => $data->name,
    'email' => $data->email,
]);
return UserResource::make($user)->response(201);
```

The application still owns authorization, Model fillable/guarded policy, and database constraints. An `ApiResource` explicitly selects response fields; SqueHub neither serializes every typed input property nor infers an Application Contract or OpenAPI schema from its constructor. A Resource may also read a typed object directly and return a deliberate array of public fields. Validation/input-conversion failures keep the configured API 422 envelope; constructor invariant failures remain generic server errors without publishing input or exception messages. See [Typed application data](ApplicationData.md#json-input-and-an-explicit-api-resource) for a complete class, nested/enum example, and privacy boundary.

**Phase 23D API Resource integration is implemented, tested, passed, and complete** on the supported Windows and user-run native Linux qualification paths. See [release readiness](ReleaseReadiness.md).

## CSRF and identity

The normal web Kernel protects `POST`, `PUT`, `PATCH`, and `DELETE` globally, including `/api/*`. A browser using the SqueHub session can send `X-CSRF-Token` from its session-bound token. External clients without that session need a deliberately configured CSRF exclusion **and** another appropriate authentication/integrity mechanism; an exclusion alone does not verify a webhook. A SqueHub-profile incoming delivery can use the explicit [Webhook verifier](Webhooks.md) on its dedicated path. There is no automatic path-based API exception. See [CSRF](Csrf.md).

Auth supports session and named personal access token guards over the same model identity provider. A token-only route explicitly attaches `RequireToken::guard('api')`; token ability checks use `RequireTokenAbility`, while application permissions still use Authorization. The separate [OIDC client](OAuth.md) supports external browser sign-in but supplies no JWT API guard or OAuth authorization server. Inbound proxy trust is opt-in through `Config/TrustedProxies.php`; it does not automatically choose an IP identity for Auth or Rate Limiting. Rate limits remain explicit route or service policy. Middleware runs in the order attached with `->through([...])`, after global CSRF. See [HTTP request metadata](Http.md#trusted-proxies-and-request-metadata), [API tokens](ApiTokens.md), [Authentication](Authentication.md), [Authorization](Authorization.md), and [Rate limiting](RateLimiting.md).

For a browser frontend on another Origin, enable [CORS](Cors.md) only for intended paths and origins. Explicitly allow its requested `Content-Type`, configured CSRF header or `Authorization` header as appropriate, and [API version header](ApiVersioning.md) if used. Credentialed CORS permits browser access to responses; the session cookie still follows browser cookie rules and unsafe session-backed requests still need a CSRF token. A dedicated Bearer-only unsafe route needs an explicit CSRF path exclusion and must reject session fallback.

A same-origin [frontend profile](FrontendProfiles.md) can call these normal API routes with Session cookies and `X-CSRF-Token` from a dynamically rendered [View shell](FrontendAuth.md). It does not need CORS or a separate frontend Auth system. An opt-in [SPA fallback](SpaRouting.md) only handles otherwise unmatched HTML navigation; it never turns missing `/api/*` endpoints into a successful shell or changes the JSON error contract. For a Bearer-only route, `RequireToken::guard('api')` remains decisive even when a browser Session and SPA shell are also present. Keep explicit CSRF exceptions limited to paths whose token-only contract has been reviewed.

## Retryable authenticated mutations

An API mutation can explicitly attach `App\Plugins\IdempotentRequests::authenticated()` as its **last** route middleware. Place `RequireToken`, token ability checks, application authorization, and any rate limit before it. A client supplies one bounded `Idempotency-Key` header. A matching completed 2xx response replays its status, body, and `Content-Type`; a conflicting reuse or concurrent in-progress request returns 409. Authentication, authorization, and CSRF still run for every retry. File, SQLite/MySQL database, and Redis backends have different coordination scopes; none makes third-party side effects exactly once. See [HTTP idempotency](Idempotency.md) for route code, configuration, response privacy, retention, and guarantees.

## Pagination and boundaries

```php
$page = User::query()->sort('id')->page(1, 20);

return UserResource::collection($page)->response();
```

`page()` returns a Page containing a `ModelCollection` of Models and consistent offset metadata; it is not cursor pagination. The resource maps those items to explicit public fields and reuses Page metadata without further SQL. See [Pagination](Pagination.md) and [API resources](ApiResources.md). Keep response data bounded and avoid exposing raw exceptions or tokens. An application can use ordinary `/api/v1/users` route prefixes with [version metadata](ApiVersioning.md); SqueHub does not force a URL scheme or duplicate endpoints.

## Binary and downloadable API responses

An API action can return an explicit byte, local-file, or streaming response instead of a JSON Resource. For a report that has already been selected and authorized by application code:

```php
return response()->download(
    $reportPath,
    filename: 'report.pdf',
    contentType: 'application/pdf',
    request: $request,
);
```

Passing the current `Request` opts the local-file response into bounded single-byte-range handling; omitting it sends the full file. `response()->binary($bytes, 'image/png')` serves bounded in-memory bytes, while `response()->stream($producer, headers: ['Content-Type' => 'text/csv'])` emits producer chunks lazily. They remain custom HTTP responses: SqueHub does not JSON-wrap them in `ApiResource`, and CORS remains governed by the configured path/origin policy. Applications must authorize and contain file paths before constructing downloads. A response body that has begun streaming cannot be replaced by a complete API error if a later chunk fails. See [HTTP Responses](Responses.md) for the full contract.
