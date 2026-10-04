# API versioning

The [Application Contract](ApplicationContract.md) reads explicit route/group `apiVersion()` metadata. `contract:export --api-version=<version>` selects declared operations for a version; URI-prefixed versions retain their real paths, while header-based versions retain their header policy. A contract does not create or duplicate versioned routes.

The [API verification gate](ApiVerification.md) compares declared version metadata with the selected route and can exercise version-specific requests through the Kernel. Register separate explicit cases for required, unsupported, or conflicting version branches that matter to an application.

[Client SDK generation](SdkGeneration.md) uses that same declared metadata. Use `sdk:generate --api-version=<version>` when more than one version is present; a generated header-version call sends its declared version header, while URI versions retain their ordinary paths. The client does not choose the newest compatible version automatically.

SqueHub versions API routes explicitly. A route or group declares a version, and the same Router still matches the request. Versioning is optional: an ordinary API route stays unversioned, and no `/api/v1` URL is imposed on an application. SqueHub does not duplicate endpoints or select a latest compatible version.

## Declare a version

Use an ordinary route prefix for URI versions and attach semantic metadata to the routes:

```php
use App\Plugins\Route;
use Project\Controllers\UserApiController;
use Project\Controllers\UserV2ApiController;

Route::group()
    ->prefix('/api/v1')
    ->apiVersion('1')
    ->routes(function (): void {
        Route::path('/users')->get([UserApiController::class, 'index']);
    });

Route::path('/api/v2/users')
    ->get([UserV2ApiController::class, 'index'])
    ->apiVersion('2');
```

These register `/api/v1/users` and `/api/v2/users` as distinct normal routes. A group declaration applies to its routes, including nested groups. A route outside a versioned group can declare its own version. Repeating the inherited version is allowed; declaring a conflicting version in a nested group or route fails during registration. Keep a route's declared version consistent with the URL you chose; the framework does not create or rewrite version prefixes.

The optional deployment mount is separate from the application version prefix. With `APP_BASE_PATH=/app`, the first route is publicly reached at `/app/api/v1/users`, but its declaration and version metadata remain `/api/v1/users` and `1`. A base path does not create, select, or change API versions. See [mounted HTTP paths](Http.md#url-base-path-and-mounted-requests).

Unversioned routes use the same routing API without `apiVersion()`:

```php
use App\Plugins\Route;
use Project\Controllers\StatusController;

Route::path('/api/status')->get([StatusController::class, 'show']);
```

Modern route registration allows one handler per method, URI, and host condition. Version metadata does not create parallel handlers for the same host and path. Use distinct paths when two versions must coexist under URI versioning. A host condition can restrict an ordinary versioned runtime route, but it does not itself select or negotiate an API version.

## Choose how clients declare a version

`Config/Api.php` uses the `versioning` section. The default `uri` strategy reads the version declared by the matched route; the URI itself is built with ordinary route prefixes. The strategy does not parse a prescribed URI shape or add a version header requirement:

```php
return [
    'enabled' => true,
    'paths' => ['/api'],
    'versioning' => [
        'strategy' => 'uri',
        'header' => 'X-API-Version',
    ],
];
```

Applications that want an explicit request header can select the `header` strategy:

```php
return [
    'enabled' => true,
    'paths' => ['/api'],
    'versioning' => [
        'strategy' => 'header',
        'header' => 'X-API-Version',
    ],
];
```

The outer `enabled` and `paths` keys select the general [API error contract](ApiResponses.md); version metadata and resolution are separate. A deliberate version failure still uses the existing `ApiError` envelope.

For a versioned route, the client then sends the configured header with the route's declared version, for example `X-API-Version: 1`. The framework compares the normalized request value with route metadata. It does not infer a version from an arbitrary URL segment, choose a default when the header is missing, or negotiate semantic version ranges. An unversioned route has no required API version.

The normal Router matches method, path, constraints, and any declared host condition first. A version header cannot select another handler for the same URL and host; unknown paths and methods retain ordinary 404/405 behavior. A host-restricted route uses the direct server Host unless the immediate peer and selected forwarding profile are explicitly trusted under [request metadata configuration](Http.md#trusted-proxies-and-request-metadata). A direct client's forwarded-host header cannot select a versioned host route. Global CSRF still runs before route matching on unsafe requests, so a missing CSRF token can yield 403 before version resolution.

The present [Application Contract](ApplicationContract.md) does not encode a route-specific host, optional path, or fallback. Such runtime routes cannot attach an `OperationContract`, and therefore do not appear in `contract:export`, `contract:verify`, or generated clients. Versioned routes intended for those public artifacts should use explicit required paths without a route-local host condition until the contract can represent it correctly. This boundary does not disable version metadata on a runtime host-restricted route.

If a cross-origin browser client sends the version header, add its name explicitly to the CORS `allowed_headers` list. A client that also sends a [Bearer token](ApiTokens.md) must explicitly allow `Authorization` too. Versioning and token authentication do not broaden CORS permissions. See [CORS](Cors.md).

## Read the resolved version

Controller and middleware code can read the matched version from the current Request:

```php
use App\Plugins\JsonResponse;
use App\Plugins\Request;

final class VersionController
{
    public function show(Request $request): JsonResponse
    {
        return new JsonResponse(['version' => $request->apiVersion()]);
    }
}
```

`apiVersion()` returns a normalized version string for a successful versioned match and `null` for an unversioned request. It is request scoped; one request's version is not a global setting for the next request or another Application. This is an API version, separate from the SqueHub framework version.

## Version errors and caching

Under the header strategy, a missing required version, an unsupported or malformed value, and conflicting submitted values fail with HTTP 400 and the existing [API error envelope](ApiResponses.md). The codes are `api_version_required`, `unsupported_api_version`, and `api_version_conflict`, respectively. A failure includes the ordinary trusted `request_id`, `X-Request-ID`, and `Cache-Control: no-store`; it does not expose route internals. For example:

```json
{
  "error": {
    "code": "api_version_required",
    "message": "An API version is required."
  },
  "request_id": "7e2f4c9038d1a6b5920ec4a7f8d31b65"
}
```

Header-dependent versioned responses add the configured header name to `Vary`, including managed version errors. Unversioned routes receive no version-related `Vary`. Existing `Vary` values are retained. Under the URI strategy, the URI distinguishes routes for ordinary HTTP caching.

Version identifiers are trimmed at their outer HTTP spaces or tabs and lowercased. The normalized value must be 1–32 bytes, begin with a lowercase letter or digit, and then contain only lowercase letters, digits, `_`, or `-`. Whitespace other than those outer spaces or tabs, and all control characters, are rejected. Examples include `1`, `2026-09`, `beta`, and `v2`. Malformed declarations and conflicting group/route declarations fail during route registration; invalid or unknown `versioning` policy options fail during Application boot. Future deprecation and sunset metadata can be added without changing how these routes are matched. Version policy does not schedule shutdowns, transform old payloads, or migrate clients automatically.
