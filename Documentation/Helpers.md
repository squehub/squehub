# Global helpers

SqueHub's global helpers are short entry points into existing Application services. They express common application intent; the Routing, View, Session, Auth, Database, and other subsystems still own the behavior. This guide describes the functions actually defined in `App/Core/Helper.php` in the v2 development tree. It is the supported helper inventory, including the retained v1 compatibility functions.

Normal SqueHub HTTP and CLI bootstraps load that file with `require_once`. Composer's `vendor/autoload.php` alone does not load it. These are **global functions**, not methods of an `App\Core\Helper` class. In namespaced code, `asset(...)` works when there is no same-named local function; write `\asset(...)` when you need to identify the global one explicitly. Do not create a colliding project or Package global helper name.

Helpers that select an Application use the currently selected SqueHub context. Boot and select the intended Application before calling them in a CLI script or test. A helper call does not bootstrap another Application, load `.env` again, or open an unrelated connection. `request()` reads only the active HTTP request scope and fails outside it; it never creates a Request from `$_SERVER`. Controller injection remains the direct way to pass a Request to services.

## Application and configuration

`config(string $key, mixed $default = null): mixed` reads a dot-separated key from the selected Application's configuration repository. It is read-only and returns the supplied default when the key is absent. Configuration is loaded at boot; this helper does not reopen `.env` or write configuration. See [Configuration](Configuration.md).

```php
$name = config('app.name', 'SqueHub');
```

## Routing and public asset URLs

`route($name, $params = [])` resolves a registered named route. Its modern registry result is a **public path**, including the selected Application's `APP_BASE_PATH`, not an absolute origin. Named path parameters are encoded and checked against the route pattern; optional trailing parameters may be omitted. Unknown names and missing or invalid required parameters fail rather than producing a guess. There is no helper argument for arbitrary query parameters. See [Routing](Routing.md#names-and-urls).

```php
use App\Plugins\Route;
use App\Plugins\Request;

Route::path('/users/{user}')
    ->get(static fn (Request $request): string => (string) $request->route('user'))
    ->named('users.show');
Route::path('/dashboard')->get(static fn (): string => 'Dashboard')->named('dashboard');
Route::path('/reports/{year?}/{month?}')
    ->get(static fn (): string => 'Reports')
    ->named('reports.show');
$path = route('users.show', ['user' => 42]); // /users/42, or /app/users/42 under /app.

route('dashboard');                         // /dashboard, or /app/dashboard.
route('reports.show');                      // /reports.
route('reports.show', ['year' => 2026]);   // /reports/2026.
```

`asset(string $url): string` resolves an application-owned public asset URL for the selected Application. Use the same `/assets/...` reference in root and mounted deployments. It references images, CSS, JavaScript, fonts, media, documents, and other public files without inspecting their extensions. It does not check file existence, read filesystem paths, or serve the resource. Direct `@style`/`@script` resources and registered View assets use the same URL resolution internally; do not wrap those declarations in `asset()`. Host-bearing HTTP(S) and protocol-relative external URLs remain external; malformed hostless forms fail. See [Public asset URLs](Assets.md#public-asset-urls) for accepted local inputs, query/fragment behavior, external URLs, and validation boundaries.

```php
<img src="{{ asset('/assets/images/logo.png') }}" alt="Logo">
<link rel="stylesheet" href="{{ asset('/assets/css/app.css') }}">
<script src="{{ asset('/assets/js/app.js') }}"></script>
<video src="{{ asset('/assets/media/intro.mp4') }}" controls></video>
<a href="{{ asset('/assets/files/manual.pdf') }}">Download manual</a>
```

With `APP_BASE_PATH=/squehub-v2`, the first call emits `/squehub-v2/assets/images/logo.png`. An already mounted input is not prefixed twice. Use `route()` for a named application route and `asset()` for a public resource; literal template attributes are not rewritten.

## Responses and redirects

`request(): Request` returns the Request bound to the active HTTP handling scope. It throws `LogicException` outside that scope, including an ordinary CLI operation. It does not capture a new request and does not retain one from an earlier Kernel call. Pass the Request into a controller or middleware method when that dependency can be explicit. See [HTTP requests](Http.md).

```php
$method = request()->method(); // Only while this Application handles a Request.
```

`response()` returns the established `ResponseFactory`; `response($content, $status = 200, $headers = [])` returns an ordinary `Response`. The factory provides `json`, `redirect`, `binary`, `download`, `file`, and `stream` creation. A download or inline file reads an application-selected local file when sent; passing the current `Request` to `download` or `file` opts into bounded single-file range handling. A stream producer runs at send time. See [HTTP Responses](Responses.md).

```php
return response('Saved', 201);
return response()->json(['saved' => true], 201);
return response()->redirect(route('users.show', ['user' => 42]));
return response()->download($authorizedPath, 'report.pdf');
return response()->stream(static fn (): iterable => ['first', 'second']);
```

`redirect('/path', $status = 302)` is the short form for a **strict application-root path** such as `/dashboard`. It returns an ordinary `RedirectResponse` without sending it. The selected Application's base path is applied once: under `/app`, `redirect('/dashboard')` sets a public `/app/dashboard` Location. The path cannot contain a query, fragment, percent escape, traversal segment, backslash, control character, or external/protocol-relative URL. Supply one of the supported redirect statuses (301, 302, 303, 307, or 308); `withHeader()` can add a validated header to the returned response. Its input is always interpreted as an application path: under `/app`, `redirect('/app/foo')` means the application's `/app/foo` route and becomes `/app/app/foo`. For a named route, `route()` has already produced a public mounted path, so use `response()->redirect(route('dashboard'))`. Use `response()->redirect($reviewedTarget)` for an intentionally selected query-bearing or external destination after validating it in application code. See [HTTP Responses](Responses.md) and [mounted HTTP paths](Http.md#url-base-path-and-mounted-requests).

`redirectBack(Request $request, string $fallback = '/', int $status = 303): RedirectResponse` reuses browser navigation's safe previous-path policy when the browser forms service is registered. Its first argument is an explicit Request with the selected Application's URL base path; a mismatched mount fails. It accepts a prior successful browser GET/HEAD path or a same-origin Referer inside the active mount, discards Referer query/fragment data, and otherwise uses the strict application-root fallback. Without browser navigation, it returns the fallback. The destination is mounted once. It does not trust raw `HTTP_REFERER`, and it is not a substitute for authorization on the destination. See [Forms](Forms.md) and [HTTP requests](Http.md).

```php
return redirect('/dashboard');
return redirect('/dashboard', 303);
// For a reviewed raw Location plus additional headers, use the factory:
return response()->redirect('/dashboard', 303)->withHeader('X-Flow', 'profile');
return response()->redirect(route('users.show', ['user' => 42]));
return redirectBack($request, '/dashboard');
```

The zero-argument `redirect()` call retains the v1 compatibility object: its `to()` and `back()` methods immediately send a raw header and exit, and `back()` reads raw `HTTP_REFERER`. Do not use that form in new code. The old `url(...$pathSegments)` likewise constructs an absolute URL from raw server variables and is neither trusted-proxy-aware nor `APP_BASE_PATH`-aware. Use a named `route()` path, and choose a reviewed public origin separately for security-sensitive absolute links. See [mounted HTTP paths](Http.md#url-base-path-and-mounted-requests).

## Views, forms, and validation

`csrf_token()` returns the current Session-bound token value. `csrf_field()` returns the trusted hidden input markup with the configured field name. A `.squehub.php` form can use `@csrf`; when calling the HTML helper directly, emit its trusted markup raw. `method_field($method)` similarly returns a hidden `_method` field for `PUT`, `PATCH`, or `DELETE`; `@method('PATCH')` is the corresponding View directive. The [forms guide](Forms.md) covers browser method transport and CSRF enforcement.

```php
<form method="post" action="{{ route('profile.update') }}">
    {!! csrf_field() !!}
    {!! method_field('PATCH') !!}
</form>
```

```php
<form method="post" action="{{ route('profile.update') }}">
    @csrf
    @method('PATCH')
    <input name="email" value="{{ old('email', $email) }}">
    <input type="checkbox" name="news" {{ checked($wantsNews) }}>
    <option value="daily" {{ selected($frequency === 'daily') }}>Daily</option>
</form>
```

`old($key = null, $default = null)` reads filtered input flashed for the next request; `errors()` returns the read-only validation `ErrorBag`. `checked()` and `selected()` require a boolean and return only the fixed attribute name or an empty string. `validator($data)` starts standalone validation and returns a `Validator`; `$request->validate(...)` is the browser/API request path. See [Validation](Validation.md) and [Forms](Forms.md).

```php
$token = csrf_token();
$firstEmailError = errors()->first('email');
$result = validator(['email' => 'ada@example.com'])->check(['email' => 'required|email']);
```

`json($value)` encodes JSON with mandatory inline-script safety flags. In a View script body, use `@json($value)`; in a quoted HTML attribute, use normal escaped `{{ json($value) }}`. `classes($entries)` composes text for a quoted `class` attribute and never marks it as trusted HTML. `environment(...$names)` and `debugging()` read the selected Application's environment and debug presentation state at render time. See [Template Utilities](TemplateUtilities.md).

```php
<div class="{{ classes(['card', 'is-active' => $active]) }}"></div>
<script>window.pageData = @json($pageData);</script>
@if (environment('production') && !debugging())
    @script('/assets/js/app.min.js')
@endif
```

## Session, identity, and data

`session()` returns the current Application's `SessionStore`; its `get`, `put`, `flash`, and `pull` methods retain their Session lifetimes. `flash($key, $message = null)` is the older message-specific bridge: with a message it writes the legacy `_flash` map, and without one it consumes that named message. Prefer the explicit Session API for new state and use the existing Notification View directives only for the legacy browser flash presentation. See [Sessions](Sessions.md).

`auth()` returns the selected Application's `AuthManager`. The helper takes **no guard argument**; use `auth()->guard('admin')` for a configured named guard. `authorize()` returns `AuthorizationManager`; `allows()` checks an ability and `require()` enforces it. Authentication and authorization are separate from View-only presentation directives. See [Authentication](Authentication.md) and [Authorization](Authorization.md).

```php
$user = auth()->user();
$admin = auth()->guard('admin')->user();
authorize()->require('reports.view');
session()->flash('notice', 'Saved');
```

`database()` returns the Application's `DatabaseManager`, `db($table)` starts a query on its default connection, and `schema($connection = null)` returns the default or named connection's Schema service. Connection objects are built lazily; actual database operations still require the configured backend. See [Database](Database.md) and [Schema](Schema.md).

```php
$users = db('users')->filter('active', 1)->all();
$reporting = database()->table('reports', 'reporting');
$schema = schema('reporting');
```

## Service gateways

The following helpers return Application-owned managers or stores. They are concise alternatives to the corresponding `App\Plugins` gateway; each subsystem guide owns the full API and configuration contract. Merely resolving an optional service does not require unrelated Redis, Mail, or database infrastructure.

| Concern | Global helper | Typical use and boundary |
| --- | --- | --- |
| [Account security](AccountSecurity.md) | `accountSecurity()` | `accountSecurity()->forGuard('web')`; reset and verification policy stays in the manager. |
| [Cryptography](Cryptography.md) | `crypto()` | `crypto()->encrypt($plaintext)`; key configuration remains mandatory for use. |
| [Health](Health.md) | `health()` | `health()->ready()`; readiness can check configured dependencies. |
| [Rate limiting](RateLimiting.md) | `rateLimiter()` | `rateLimiter()->consume('login', $key, 5, 60)`; configured stores govern enforcement. |
| [Mail](Mail.md) | `mailer()` | `mailer()->send($message)`; delivery needs a valid transport and `MailMessage`. |
| [Notifications](Notifications.md) | `notifications()` | `notifications()->send($recipient, $notification)`; delivery is distinct from browser flash messages. |
| [Queue](Queue.md) | `queue()` | `queue()->dispatch($job)` uses the default connection; pass its fourth argument for a named connection. |
| [Redis](Redis.md) | `redis()` | `redis()->connection('default')`; Redis is optional and connection is lazy. |
| [Locks](Locks.md) | `lock()` | `lock()->acquire('invoice:123', ttl: 30)` uses the selected Application's configured lease store. |
| [Scheduler](Scheduler.md) | `schedule()` | `schedule()->call($callback)` registers an Application schedule definition. |
| [Logging](Logging.md) | `logger()` | `logger()->info('Task finished')`; do not log secrets. |
| [Cache](Cache.md) | `cache()` | `cache()->read('key')` or `cache()->store('key', $value)`; uses configured store. |
| [Storage](Storage.md) | `storage()` | `storage()->write('reports/latest.txt', $content)`; `drive($name)` selects a named drive. |
| [Events](Events.md) | `events()` | `events()->listen(Event::class, $listener)` and `events()->emit($event)`. |
| [Diagnostics](Diagnostics.md) | `diagnostics()` | `diagnostics()->requestId()` returns the active request ID, or `null` outside one. |
| [Outgoing HTTP](HttpClient.md) | `httpClient()` | `httpClient()->get($url)` uses the configured outgoing client and its safety policy. |
| [OIDC](OAuth.md) | `oauth()` | `oauth()->provider('company')` selects a configured external identity provider. |
| [Webhooks](Webhooks.md) | `webhooks()` | `webhooks()->endpoint('payments')` selects a configured outgoing peer; inbound sources use `source($name)`. |

SqueHub keeps this helper list small. Remember-me uses the existing `auth()`/`App\Plugins\Auth` surface. The optional [RBAC](RBAC.md), [MFA](MFA.md), and [signed URL](SignedUrls.md) features have `App\Plugins` gateways and Application-owned services; they do not introduce `rbac()`, `mfa()`, or `signed_url()` global helpers.

## Complete reference

All entries below are global declarations in `App/Core/Helper.php`. “Application” means a selected SqueHub Application; “Session” means an active or startable Session store; “HTTP” means a current browser/request context when the operation uses it. The examples and links above give the normal usage; this table records exact helper signatures and return contracts. A helper that returns a manager can be called from CLI after Application boot, although the manager's own operation may still require configured infrastructure.

| Helper and signature | Returns / purpose | Context | Status |
| --- | --- | --- | --- |
| `config(string $key, mixed $default = null): mixed` | Read-only configuration value or default | Application | Supported |
| `session(): SessionStore` | Application Session store | Application; Session on use | Supported |
| `auth(): AuthManager` | Authentication manager; named guard via `guard()` | Application; request identity on use | Supported |
| `authorize(): AuthorizationManager` | Ability checks and enforcement | Application; policy context on use | Supported |
| `accountSecurity(): AccountSecurityManager` | Credential/reset/verification service | Application; policy context on use | Supported |
| `crypto(): CryptManager` | Encryption/signing manager | Application; configured key for use | Supported |
| `health(): HealthManager` | Liveness/readiness checks | Application | Supported |
| `rateLimiter(): RateLimiter` | Named rate-limit policies | Application; store on use | Supported |
| `mailer(): Mailer` | Outgoing mail service | Application; transport on send | Supported |
| `notifications(): NotificationManager` | Delivery notifications | Application; channel on send | Supported |
| `queue(): QueueManager` | Queue dispatch/worker gateway | Application; selected connection on use | Supported |
| `redis(): RedisManager` | Optional named Redis connections | Application; Redis on connect | Supported |
| `lock(): LockManager` | Configured named lease acquisition | Application; selected store on use | Supported |
| `schedule(): Scheduler` | Scheduled task definitions | Application | Supported |
| `old(?string $key = null, mixed $default = null): mixed` | Flashed old input | Application; Session/request lifecycle | Supported |
| `route($name, $params = [])` | Named public route path | Active route registry or legacy router | Supported |
| `asset(string $url): string` | Public asset URL | Application | Supported |
| `csrf_token(): string` | Session-bound CSRF token | Application; Session | Supported |
| `csrf_field(): string` | Trusted hidden-input HTML | Application; Session | Supported |
| `method_field(mixed $method): string` | Trusted `_method` HTML for PUT/PATCH/DELETE | None beyond helper bootstrap | Supported |
| `checked(mixed $condition): string` | Fixed `checked` or empty text | Boolean argument | Supported |
| `selected(mixed $condition): string` | Fixed `selected` or empty text | Boolean argument | Supported |
| `json(mixed $value): string` | Inline-script-safe JSON text | Encodable value | Supported |
| `classes(array $entries): string` | Class attribute text | Valid class entries | Supported |
| `environment(string ...$names): bool` | Environment-name match | Application; at least one name | Supported |
| `debugging(): bool` | Effective debug flag | Application | Supported |
| `errors(): ErrorBag` | Read-only validation errors | Application; Session/request lifecycle | Supported |
| `request(): Request` | Current bound HTTP Request | Active HTTP scope; throws outside it | Supported |
| `response(?string $content = null, int $status = 200, array $headers = []): Response|ResponseFactory` | Response with arguments; factory with none | Helper bootstrap | Supported |
| `redirect(?string $path = null, int $status = 302): object` | Supplied strict application path returns `RedirectResponse`; zero arguments return legacy immediate redirect object | Application for path form; legacy HTTP globals for zero-argument form | Supported path form; compatibility-only zero-argument form |
| `redirectBack(Request $request, string $fallback = '/', int $status = 303): RedirectResponse` | Safe browser previous path or strict local fallback | Application and explicit Request | Supported |
| `database(): DatabaseManager` | Named/default database gateway | Application; backend on query | Supported |
| `db(string $table): QueryBuilder` | Default-connection table query | Application; backend on execution | Supported |
| `schema(?string $connection = null): Schema` | Default or named Schema | Application; backend on operation | Supported |
| `validator(array $data): Validator` | Standalone validation | Input data | Supported |
| `logger(): Logger` | Configured logging | Application | Supported |
| `cache(): CacheStore` | Configured Cache store | Application; selected backend on use | Supported |
| `storage(): StorageManager` | Default/named Storage drives | Application; selected drive on use | Supported |
| `events(): EventDispatcher` | Listener registration and emission | Application | Supported |
| `diagnostics(): Diagnostics` | Current operational counters/request ID | Application; HTTP data during request | Supported |
| `httpClient(): HttpClient` | Configured outgoing HTTP client | Application; network on send | Supported |
| `oauth(): OAuthManager` | Configured OIDC providers | Application; provider on use | Supported |
| `webhooks(): WebhookManager` | Configured incoming/outgoing peers | Application; peer on use | Supported |

## v1 compatibility and migration

These functions still exist in `App/Core/Helper.php` for older application code. Their presence does not make their old behavior a recommended v2 pattern. The [upgrade guide](UpgradeFromV1.md) gives migration order.

| Helper and signature | Current behavior | V2 direction |
| --- | --- | --- |
| `startSessionIfNotStarted()` | Starts the Application Session store. | Use `session()` and let its operations start the store. |
| `validateCsrfToken(): void` | Legacy POST-only check; throws the modern CSRF exception on denial. | Let Kernel CSRF middleware protect browser routes; use `@csrf`/`csrf_field()` in forms. |
| `CsrfTokenValidator(): bool` | Legacy POST-only boolean check of `_csrf`/`_token` in globals. | Use Kernel CSRF protection and current `Request` handling. |
| `is_post(): bool` | Reads raw `$_SERVER['REQUEST_METHOD']`; returns `false` when no request method exists in CLI. | Use injected `$request->method() === 'POST'`. |
| `maskEmail(string $email): string` | Masks all but first two local-part characters. | Application-specific presentation utility; validate input first. |
| `maskPhoneNumber(string $phone): string` | Masks all but the last two characters. | Application-specific presentation utility; validate input first. |
| `slugify(string $text): string` | Legacy transliteration and slug conversion. | Keep only where existing output is required; evaluate a project utility for new slug policy. |
| `flash(string $key, ?string $message = null)` | Writes/consumes the legacy `_flash` message map. | Use `session()->flash()` for new Session flash data. |
| `priceFormatter(float $amount, string $currency = '', bool $symbolBefore = true, int $decimals = 2): string` | Number formatting with optional currency text. | Application-specific currency/locale presentation. |
| `url(...$pathSegments)` | Absolute URL from raw server protocol/Host. | Use `route()` for named public paths and a reviewed origin for absolute links. |
| `redirect()` with no argument | `to()`/`back()` immediately send header and exit; `back()` trusts raw Referer. | Use `redirect('/path')` for a local application path, `response()->redirect(route('name'))` for a named route, or `redirectBack($request, '/fallback')` for safe browser back behavior. |

The published v1 helper page also described project and Package `Utils` files as globally loaded. V2's root legacy `Bootstrap.php` eagerly requires PHP files under the first matching `Project/Utils` casing variant during normal application startup. Package utility files load only for enabled Packages through their activation lifecycle; a disabled Package does not contribute helpers. Composer autoload alone does not run either loader. Prefer namespaced utility classes where possible, and avoid SqueHub/PHP global function name collisions.
