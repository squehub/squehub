# SqueHub v2 routing

This page describes the v2 routing API in the core repository. The public v1.x documentation remains unchanged. Application route definitions load from `Project/Routes/`, including nested PHP files. `App/Routes/` is not a route source; `App/` contains framework code. Package route files load only when their Package is enabled; see [Packages](Packages.md). A [Kit](Kits.md) can publish an ordinary `Project/Routes/` file. That file continues to load through normal Project routing even after Kit disable; removing it requires a separate reviewed Kit removal. Provenance may identify the file's Kit origin, but the Kit entry class never boots to register the route.

All route declarations are application-relative. An explicit `APP_BASE_PATH=/app` mount makes public `/app/users` reach `Route::path('/users')` without changing Project or Package route files. Root deployment remains the default. A request outside that mounted path is not dispatched into the application. See [mounted HTTP requests](Http.md#url-base-path-and-mounted-requests) and [Deployment](Deployment.md#subdirectory-mounts-and-shared-hosting).

## Define routes

Normal application route files can use `Route` directly without a `use` statement. SqueHub registers this global shortcut before loading project and package routes. The facade delegates to the Application's `RouteRegistry`; it does not keep an independent route list. Start with the path, then choose the HTTP method and handler.

For explicit imports, application code should use `App\Plugins\Route` and `App\Plugins\View`. Existing `App\Core\Route` and `App\Core\View` imports remain compatible; all route names use the same registry. `App\Core\Model` remains the legacy model, while new models can extend `App\Plugins\Model`.

```php
use App\Plugins\Route;

Route::path('/users')->get([\Project\Controllers\UserController::class, 'index']);
Route::path('/users')->post([\Project\Controllers\UserController::class, 'store']);
Route::path('/users/{id}')->get([\Project\Controllers\UserController::class, 'show']);
Route::path('/users/{id}')->put([\Project\Controllers\UserController::class, 'update']);
Route::path('/users/{id}')->patch([\Project\Controllers\UserController::class, 'update']);
Route::path('/users/{id}')->delete([\Project\Controllers\UserController::class, 'destroy']);
Route::path('/users')->options([\Project\Controllers\UserController::class, 'options']);
```

Closure handlers are also supported. They can return a value or echo output; the HTTP dispatcher captures echoed output as the response body. Controllers are resolved through the service container, so constructor dependencies can be injected. A required `{id}` matches one path segment and is available through `$request->route('id')`. Percent-encoded segment values are decoded once; encoded slashes, backslashes, controls, malformed percent encoding, and `.` or `..` segment values are rejected. Route values are input, not trusted or validated values. [Optional parameters and constraints](#optional-parameters-and-constraints) extend this same path-first declaration.

```php
Route::path('/hello')->get(static fn (): string => 'Hello');

Route::path('/welcome')->get(function (): void {
    echo 'Welcome';
});
```

Unnamespaced route files also have `View`, `Request`, `Response`, `JsonResponse`, `Auth`, `Cache`, `Events`, `Log`, `Session`, and `Storage` shortcuts. Existing global helpers such as `db()`, `cache()`, `storage()`, `logger()`, `auth()`, and `response()` need no import. PHP still requires an import or fully qualified name when a namespaced controller or model refers to a class outside its own namespace. SqueHub does not guess arbitrary short class names because different packages may use the same name. A project class already using one of these global names takes precedence over the shortcut.

Modern registration rejects duplicate method, URI, and host combinations. Among normal routes, matching prioritizes static path segments, then constrained parameters, broad required parameters, and optional parameters. An exact host outranks a dynamic host, which outranks an unrestricted host when path priority is equal. Equivalent shapes retain registration order. A path with no matching route returns 404; a matching path and host requested with an unsupported method returns 405 with an `Allow` header. A HEAD request uses a GET route when no explicit HEAD route exists; an explicit HEAD route takes precedence. Response sending suppresses the HEAD body. Explicit `OPTIONS` routes remain available; only a genuine configured [CORS preflight](Cors.md) is answered before dispatch. [Route caching](PerformanceCaching.md#route-cache) is available for cacheable declarations and validates source changes before replay.

## Optional parameters and constraints

Put optional path parameters at the end of a route with `{name?}`. When an optional segment is missing, its name is **absent** from `$request->route()`; asking for it returns the supplied default (or `null`). It is never inferred from a controller type hint or default value. A controller that accepts both forms should declare an explicit default such as `?string $year = null`, or read the value from the Request. A nullable type without a default still requires an argument.

```php
use App\Plugins\{Request, Route};
use Project\Controllers\ReportController;

Route::path('/summary/{year?}')->get(static function (Request $request): string {
    return $request->route('year', 'current');
});

Route::path('/reports/{year?}/{month?}')
    ->get([ReportController::class, 'show'])
    ->named('reports.show');
```

For the second route, `/reports`, `/reports/2026`, and `/reports/2026/09` are valid shapes. A later optional value requires every preceding optional value. `/reports/{year?}/summary` and `/reports/{year?}/{month}` are rejected at registration because they would make the meaning of omitted segments ambiguous. An optional segment is not a wildcard and does not absorb a slash. The normal path normalization continues to treat `/reports/` as `/reports`.

Use `where()` after the HTTP verb to constrain a declared path or host parameter. A mismatch makes this route ineligible; another eligible route can match, and no controller validation error or Model lookup occurs for the rejected route.

```php
use App\Plugins\Route;
use Project\Controllers\{ReportController, UserController};

Route::path('/users/{id}')
    ->get([UserController::class, 'show'])
    ->where('id', 'integer');

Route::path('/reports/{year?}')
    ->get([ReportController::class, 'show'])
    ->where('year', '[0-9]{4}');
```

The named ASCII presets are `integer` and `numeric` (`[0-9]+`), `alpha` (`[A-Za-z]+`), `alphanumeric` (`[A-Za-z0-9]+`), `slug` (ASCII letters/digits separated by single hyphens), and `uuid` (hexadecimal UUID shape). A custom constraint is a PCRE **fragment** matched against the entire decoded value; supply neither delimiters nor anchors. The fragment is compiled and validated at registration, has a length bound, and is evaluated with a match limit. This is a bounded guard, not a formal guarantee against every expensive regex; prefer simple patterns for public routes. Constraints are route matching rules, not a replacement for request validation. Unknown parameters, duplicate constraints, and malformed expressions fail when routes load.

### Precedence and ambiguous shapes

For `/users/create`, `/users/{id}` with `where('id', 'integer')`, and `/users/{slug}`, the static route wins for `create`, while a numeric value selects the constrained route before the broad slug route. A shape such as `/users/{id}` versus `/users/{slug}` with no distinguishing constraint retains registration order. Use a visible constraint or a different path when both meanings matter; parameter names alone do not distinguish requests. A fallback is examined only after normal matching and method handling.

## Host conditions

Add a host before selecting the verb. Routes without `host()` remain available on ordinary configured hosts. Host-restricted and unrestricted routes can coexist at the same path.

```php
use App\Plugins\Route;
use Project\Controllers\{AdminDashboardController, TenantAccountController};

Route::path('/dashboard')
    ->host('admin.example.com')
    ->get([AdminDashboardController::class, 'index']);

Route::path('/account')
    ->host('{tenant}.example.com')
    ->get([TenantAccountController::class, 'show'])
    ->where('tenant', 'slug');
```

`$request->route('tenant')` returns the dynamic host label as a lowercase string, alongside path parameters. It remains a scalar rather than a resolved Model. Host and path parameter names must be distinct. Host parameters capture one valid ASCII DNS label, not an arbitrary subdomain tree. Matching is case-insensitive for DNS names. Incoming `Host` ports are ignored for route selection; a declaration uses a host without a port. Literal `localhost`, IPv4 addresses, and bracketed IPv6 addresses are supported. Malformed hosts do not satisfy a host-restricted route. SqueHub does not automatically convert internationalized Unicode domains to IDNA; configure an ASCII/Punycode host deliberately if needed.

Without an explicitly configured trusted proxy, the server's ordinary `HTTP_HOST` (or `SERVER_NAME` fallback) remains authoritative. Forwarded host headers from a direct or untrusted peer are ignored. Behind a trusted immediate proxy, `Config/TrustedProxies.php` may select either the RFC `Forwarded` or `X-Forwarded-*` profile. Only a valid host from that selected profile becomes effective for `Request::host()` and host-restricted route matching. The same host and port validation applies to direct and forwarded values; `Route::host()` still compares the normalized hostname without a port. A second, optional `allowed_hosts` policy can reject a disallowed effective host with HTTP 400 before dispatch. See [HTTP request metadata](Http.md#trusted-proxies-and-request-metadata) and [Deployment](Deployment.md).

For example, `Host: 127.0.0.1:8080` together with `X-Forwarded-Host: admin.example.com` can match the first route only when the immediate `REMOTE_ADDR` is configured as trusted and the selected profile is `x-forwarded`. The same header from a direct client cannot enter an admin host route. DNS and web-server virtual-host setup remain deployment responsibilities; no Redis, Node process, or special proxy is needed to use host matching on a shared host.

Groups can share a host condition:

```php
use App\Plugins\Route;
use Project\Controllers\AdminUserController;

Route::group()->host('admin.example.com')->prefix('/admin')->through('auth')
    ->routes(function (): void {
        Route::path('/users')->get([AdminUserController::class, 'index']);
    });
```

Nested groups inherit the host. A conflicting child group or route host is rejected rather than silently replacing the parent. API URI prefixes, route/group `apiVersion()` metadata, and path-scoped [CORS](Cors.md) remain separate and compose through the same matcher. Host routing does not create host-based API version negotiation.

## Explicit fallbacks

Declare a fallback against a **static path prefix**. Its handler runs only when no normal route matches the request's host/path and the matcher has not found a method mismatch requiring 405. It uses the ordinary route middleware pipeline and keeps the status of the Response the handler returns.

```php
use App\Plugins\{Response, Route};

Route::path('/docs')->fallback(static fn (): Response =>
    new Response('Documentation page not found.', 404)
)->through('auth');

Route::path('/admin')->host('admin.example.com')->fallback(
    static fn (): Response => new Response('Admin page not found.', 404)
);
```

The `/docs` fallback covers `/docs` and descendants such as `/docs/unknown`, but not `/documents`. A fallback at `/` covers otherwise unmatched paths application-wide. More specific prefixes win; host specificity breaks a tie. Fallbacks are method-agnostic, but they never erase an established 405. A normal route, including a root route, always has priority. Group prefixes and middleware apply to a fallback inside that group. A fallback can be host-specific; it cannot contain path placeholders. It does not imply a single-page-app rewrite or frontend routing.

A fallback is a normal application handler, so the application chooses its response body and status. For an API prefix, return the intended API response explicitly. When no fallback matches, SqueHub's existing browser page or machine-readable API 404 remains in charge. This differs from `Route::error(404, ...)`, which customizes the error renderer after an unmatched request. Do not use a fallback merely to style the standard error page.

For a browser application with client-side paths, use the separate opt-in [SPA routing policy](SpaRouting.md) rather than a generic `Route::path('/')->fallback(...)`. It selects one backend View only after normal route and fallback matching and only for eligible HTML `GET`/`HEAD` navigation. A generic fallback at `/` retains precedence and will prevent the SPA policy from running. API paths, missing assets, JSON/fetch requests, and unsafe methods do not become successful HTML responses. A selected frontend [profile](FrontendProfiles.md) can configure this policy without replacing the Router or its 405 behavior.

## Bind a route parameter to a Model

Model lookup is **explicit per route**. Use `bind()` on a path-first route after choosing its HTTP method. The first argument names an existing `{parameter}` in that route; the second is a modern Model class. An ordinary unbound route continues passing the decoded scalar path value to its action. A Model type hint alone never starts a database query; without `bind()`, passing that scalar to a Model-typed parameter produces a PHP type error.

```php
use App\Plugins\Route;
use Project\Controllers\UserController;
use Project\Models\User;

Route::path('/users/{user}')
    ->get([UserController::class, 'show'])
    ->bind('user', User::class)
    ->named('users.show')
    ->through('auth');
```

The controller receives the resolved Model when its parameter name matches the bound path parameter. It can still read the original path value from the request. For example, a controller importing `App\Plugins\Request` can declare `show(User $user, Request $request)` and read `$request->route('user')` for the decoded scalar value. Query/body input stays separate from route parameters. Resolved Models are kept only for the current controller invocation; route definitions, matched metadata, URL generation, and diagnostics do not retain them.

By default, binding looks up the Model's configured primary key through its normal Model query. A Model with a string primary key or a named connection uses those declarations. For a route-specific lookup column, pass `key:`:

```php
Route::path('/posts/{post}')
    ->get([PostController::class, 'show'])
    ->bind('post', Post::class, key: 'slug');
```

The key must be a safe database identifier and belong to the route declaration, not an incoming request value. The captured value is supplied through the QueryBuilder's prepared value binding. Use a unique database constraint for a key such as `slug` if the route must identify exactly one row; SqueHub does not create or infer that constraint. A declaration for a missing path parameter, an invalid Model class, a duplicate binding, or an unsafe key is rejected instead of silently falling back to scalar dispatch.

Multiple parameters can be bound independently:

```php
Route::path('/teams/{team}/members/{member}')
    ->get([TeamMemberController::class, 'show'])
    ->bind('team', Team::class)
    ->bind('member', Member::class);
```

Each declared binding performs at most one normal Model lookup when a matching request reaches the controller. Binding does **not** infer that the member belongs to the team; enforce that relationship and authorization explicitly in application code. A missing required Model produces the existing 404 response. Browser error pages, including an application `Route::error(404, ...)` handler, and the configured API error envelope use the normal HTTP error boundary. A soft-deleted Model is missing under the default Model query policy; this API has no implicit deleted-record override.

The request order is: global middleware, route matching, API version selection, route middleware in declaration order, explicit Model binding, controller, response. A route middleware that stops the request—for example Auth, an ability check, or a rate limiter—prevents the binding query. Route middleware sees raw scalar values through `$request->route()`, including on bound routes. Put a resource-specific authorization decision that needs the resolved Model in the controller or an application service after binding. Binding only locates a record; it does not grant access.

Registration, `route:list`, package inspection, provenance snapshots, `contract:export`, and other static route inspection read binding declarations without resolving Models or contacting the database for binding. Project and enabled Package route files share the same route definition behavior. Disabled Packages do not register their routes. This is a modern path-first feature; the legacy `$router` registration path does not acquire implicit binding. A required path parameter can be constrained before a Model binding query; a constraint mismatch performs no lookup. Optional parameters do not become implicit Model bindings. Scoped parent/child lookup and custom resolver callbacks remain separate future work. [Route caching](PerformanceCaching.md#route-cache) is available only for fully cacheable declarations and preserves supported binding metadata.

## Names and URLs

```php
Route::path('/dashboard')->get([DashboardController::class, 'index'])->named('dashboard');
Route::path('/users/{id}')->get([UserController::class, 'show'])->named('users.show');

route('dashboard');                 // /dashboard
route('users.show', ['id' => 10]); // /users/10

// For a named /reports/{year?}/{month?} route:
route('reports.show');                             // /reports
route('reports.show', ['year' => 2026]);           // /reports/2026
route('reports.show', ['year' => 2026, 'month' => 9]); // /reports/2026/9
```

Named URL generation encodes parameter values as path segments and checks the route's constraints. Slash, backslash, controls, empty values, and `.` or `..` segment values are rejected. Omit trailing optional parameters (or pass `null`) to omit those segments. You cannot supply `month` while omitting `year`. A missing required parameter or unknown name raises an error; duplicate route names are rejected during registration. The generated URL remains a **path**, not an absolute URL, even for a host-restricted route. It does not select a tenant, resolve DNS, or infer a public origin.

For a temporary bearer link to a modern named route, use `App\Plugins\SignedUrl::temporary()` and attach `SignedUrl::middleware()` to the destination route. It signs the route path, query, expiry, purpose, and method while preserving the public mount path. See [Signed URLs](SignedUrls.md) for generation, verification, static-host behavior, and security limits.

Under `APP_BASE_PATH=/app`, the same declarations above produce `route('dashboard') === '/app/dashboard'` and `route('users.show', ['id' => 10]) === '/app/users/10'`. The route registry adds the mount at generation time; it does not store `/app` in the route declaration or static `route:list`/contract metadata. The application root route produces `/app/`. Use `route()` for browser links and form actions so a change of mount does not require editing templates. Package routes follow the same rule. A literal `<a href="/dashboard">` remains literal and points at the host root; the template compiler does not rewrite arbitrary HTML. Existing root-deployment outputs stay as shown in the example.

An application route may itself begin with the same word as the mount. If the declared route is `/app/foo` under `APP_BASE_PATH=/app`, its named `route()` result is `/app/app/foo`. A raw `/app/foo` passed to a redirect or `asset()` is treated as **already public** and is not prefixed twice; a raw string cannot express that ambiguous application route. Use its named route for redirects and links. For an asset whose application-owned path is `/app/logo.svg`, `asset('app/logo.svg')` produces `/app/app/logo.svg` under this mount.

## Groups

```php
Route::group()->through('auth')->routes(function (): void {
    Route::path('/dashboard')->get([DashboardController::class, 'index']);
});

Route::group()->prefix('/admin')->through(['auth', 'admin'])->routes(function (): void {
    Route::path('/users')->get([AdminUserController::class, 'index']);
});
```

Nested prefixes compose. An inner `/users` group inside `/admin` produces `/admin/users`. Group middleware is collected from outer to inner, then route middleware is appended. Modern `through()` accepts aliases, class names, and explicit handler or callable objects such as `RequireAbility::named('reports.view')`; the latter checks a registered global [authorization](Authorization.md) ability. See [middleware](Middleware.md) for execution order and aliases.

API routes may also declare an explicit version on a group or individual route:

```php
Route::group()->prefix('/api/v1')->apiVersion('1')->routes(function (): void {
    Route::path('/users')->get([UserApiController::class, 'index']);
});
```

The normal prefix creates the URL and version metadata describes the matched route; no second router or automatic route duplication is involved. See [API versioning](ApiVersioning.md) for header strategy and request access. A group may also add the [host condition](#host-conditions) shown above. [CORS](Cors.md) is a separate path-scoped configuration policy, not a route header shortcut.

## Resource routes

```php
Route::resource('/users', UserController::class);
```

| Method | URI | Action | Name |
| --- | --- | --- | --- |
| GET | `/users` | `index` | `users.index` |
| GET | `/users/{id}` | `show` | `users.show` |
| POST | `/users` | `store` | `users.store` |
| PUT | `/users/{id}` | `update` | `users.update` |
| PATCH | `/users/{id}` | `update` | `users.update` |
| DELETE | `/users/{id}` | `destroy` | `users.destroy` |

Resource routes do not add HTML `create` or `edit` routes. PUT and PATCH share one update name. Register `OPTIONS` explicitly when needed.

## Document selected API routes

Attach a SqueHub-native [Application Contract](ApplicationContract.md) with `->contract(Contract::operation()->...)` after a path-first route declaration. Method, path, route name, and version still come from the existing Router. Only deliberately contracted public operations appear in OpenAPI export; attaching a contract does not change dispatch or middleware order. A route with `->internal()` contract stays out of public export. Path placeholders must have matching required `path()` parameters. The current native Contract, OpenAPI bridge, verifier, and SDK model cannot faithfully represent an optional path, per-route host condition, or fallback, so attaching an `OperationContract` to those route shapes is rejected. They remain runtime routes; use a distinct required-path contract route where a public operation is needed.

## Inspect routes

Run `php squehub route:list` to list application, framework, and enabled Package routes. Its table includes method, URI, name, handler, middleware, host, and fallback columns. The command reads declaration metadata without matching a request, invoking handlers, or resolving Models. Constraints, host patterns, and fallback status are declarations; no request parameter values are stored in route provenance or static output.

## Compatibility

Existing `$router->add($method, $uri, $action, $name, $middleware)` route files remain supported. Their fourth argument is the route name and their fifth is middleware. Legacy registrations are mirrored into the same registry used by modern routes. The old `Controller@method` action form, project and package controller lookup, groups, and custom not-found handler remain compatibility APIs. A custom legacy not-found handler supplies the 404 body for unknown paths that reach the error boundary; known paths with an unsupported method still produce 405. `$router->setErrorHandler($status, $callback)` registers browser error pages for other 4xx/5xx statuses in application route files, and `Route::error($status, $callback)` is the modern equivalent. See [HTTP](Http.md) for view examples and error rendering. Legacy registrations overwrite duplicate method and URI pairs or names; modern registrations throw. Legacy nested groups retain their inner prefix replacement behavior, while modern groups compose prefixes. The legacy Router retains its historical dispatch API for applications that call it directly. The new optional, constraint, host, and fallback declarations belong to the modern path-first API.

Register routes with `Route::path($uri)->get($action)` or the corresponding path-first call for another HTTP verb. Group routes with `Route::group()->prefix(...)->through(...)`, then name or decorate them with `->named()` and `->through()`. `App\Plugins\Route` shares this API. The v1 `$router` API remains supported.

## Application-facing Plugins import

Application code may import `App\Plugins\Route`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
