# Plugins developer API gateway

[Observability](Observability.md), the [development profiler](Profiler.md), [framework performance caches](PerformanceCaching.md), and [local Studio](Studio.md) are operational services and CLI tools. They add no application-facing `App\Plugins` aliases for these services; ordinary Plugins routing, HTTP, and data APIs remain the developer-facing surface.

`App\Plugins\Health` is the application-facing gateway for `live()`, `ready()`, `doctor()`, and `infrastructure()`. `App\Plugins\HealthManager`, `HealthCheck`, `HealthResult`, and `HealthReport` alias the canonical `App\Health` contracts for application/package checks. See [Health](Health.md). Framework internals use canonical types.

`App\Plugins` is SqueHub's stable application-facing import namespace. It provides short names for **framework APIs** while canonical subsystem namespaces continue to work. It is unrelated to installable packages or third-party plugin loading.

`App\Plugins\Translation` exposes the current Application's locale, catalog lookup, ICU plurals, and optional locale-aware number, currency, and date/time formatting. It uses the same manager as the escaped View `@translate` directive. There is no global translation helper or process-global locale change. See [Internationalization](Internationalization.md).

`App\Plugins\Mfa` exposes the current Application's optional [TOTP and recovery-code service](MFA.md). `Mfa::forGuard('web')` binds enrollment, challenge completion, recovery regeneration, and disable to one session guard. Pending challenges remain guests to `Auth` and authorization middleware.

`App\Plugins\SignedUrl` generates temporary, purpose-bound links to modern named routes with `temporary()`, checks a matched Request with `valid()`, and creates the canonical `RequireSignedUrl` route middleware with `middleware()`. It uses the current Application's routes and Crypt key ring. There is no global signed URL helper. See [Signed URLs](SignedUrls.md).

The global `route()` and `asset()` helpers need no Plugins import. They return public paths for the active Application: with `APP_BASE_PATH=/app`, `route('users.index')` and `asset('/assets/app.css')` include `/app`. Route declarations remain application-relative, and external asset URLs are left alone. There is no new Plugins gateway for the URL mount. See [Routing](Routing.md#names-and-urls), [Assets](Assets.md), and [Deployment](Deployment.md#subdirectory-mounts-and-shared-hosting).

Shared View Context uses the existing `App\Plugins\View` gateway for `share()`, `provide()`, and `compose()`. Provider and composer callbacks may type their argument as `App\Plugins\ViewContext`, a read-only context interface exposing the owning Application, optional current Request, and optional logical View name. The Application owns the registrations and request lifecycle; framework internals use `App\View` classes. See [Shared View Context](ViewContext.md).

The same `View` gateway accepts explicit Package names such as `Commerce::Orders.Index` for rendering, composers, assets, and Fragments. The Component and Include directives use that identity without a new Plugins API. See [Package and Namespaced Views](PackageViews.md).

`View::fragment($view, $name, $data)` uses that same gateway and returns the immutable `App\Plugins\FragmentRenderResult` exact alias. `View::renderResult($view, $data)` similarly returns `App\Plugins\ViewRenderResult` for a complete View and its finalized stacks without producing an HTTP response. `View::response($view, $data, $status, $headers)` renders the same complete View without echoing and returns the existing `App\Http\Response`, exposed to application code as the `App\Plugins\Response` exact alias. No separate View response class or Plugins alias is needed. Fragment HTML and stacks remain separate from HTTP transport; the application chooses a `Response` or `JsonResponse` for them. See [Returnable View Responses](ViewResponses.md), [Fragments and Partial Responses](Fragments.md), and [View Diagnostics and Testing](ViewDiagnosticsTesting.md).

The `App\Plugins\Cookie` alias is the public typed cookie value. `Response::withCookie()` attaches it to the same ordinary Response used by JSON, redirects, Views, local files, and streams. The existing `response()` helper exposes `binary()`, `download()`, `file()`, and `stream()` through `ResponseFactory`; their emitter and range parser remain internal. See [HTTP Responses](Responses.md).

Applications declare client-facing operations with the existing `App\Plugins\Contract` and `ContractSchema` gateway. [SDK generation](SdkGeneration.md) is an offline CLI tool over that native contract and adds no application-facing Plugins symbol. Generated clients run outside the SqueHub PHP framework.

Kit authors extend `App\Plugins\Kit` for explicit lifecycle hooks and type their hook argument as `App\Plugins\KitContext`. The context is an exact alias for the canonical lifecycle value type. Kit entry classes do not run during normal application boot; static Kit inspection does not include them. See [SqueHub Kits](Kits.md).

Application tests can extend `App\Plugins\TestCase` and use the exact `TestApplication`, `TestClient`, `TestResponse`, and `ViewTestResult` aliases. `TestCase::view()` and `fragment()` use the production renderer and return fluent `ViewTestResult` assertions. These are PHPUnit development helpers over the canonical `App\Testing` classes; they do not install a second HTTP or View stack or replace PHPUnit. See [Testing](Testing.md) and [View Diagnostics and Testing](ViewDiagnosticsTesting.md).

```text
Project application code → App\Plugins → canonical App subsystem
```

Framework internals should continue depending on canonical subsystem contracts. The gateway neither owns services nor changes routing, ORM, Mail, Notification, security, or storage behavior. Public Plugins names follow SqueHub's normal compatibility expectations as internals evolve.

## Application imports

```php
use App\Plugins\{Route, View, Cache, Storage};
use Project\Models\User;

Route::path('/users')->get(static function () {
    $users = User::query()->get();

    return View::render('users.index', ['users' => $users]);
});
```

A route handler may render a View directly; `View::render()` emits template output for the HTTP dispatcher to capture. Path-first `Route::path('/users')->get(...)` is the Plugins and canonical v2 registration form for each supported HTTP verb. `App\Plugins\Route` and `App\Routing\Route` expose the same registration style. The v1 `$router` compatibility API remains supported.

```php
namespace Project\Models;

use App\Plugins\Model;

final class User extends Model
{
    protected string $table = 'users';
}
```

`Model` and `ModelFactory` extend the modern ORM bases. They inherit the same hydration, casts, timestamps, relations, dirty tracking, and persistence behavior. `App\Core\Model` remains the separate legacy API.

```php
use App\Plugins\{Cache, DB, Storage};

$users = DB::table('users')->filter('status', 'active')->all();
Cache::write('active-users', $users, 60);
$stored = Cache::read('active-users');
Storage::write('reports/latest.txt', 'ready');
```

`DB::table()` remains a raw table query with no Model scopes or soft-delete policy. `Cache::write()` delegates to `CacheStore::store()`; `Storage::write()` delegates to the configured StorageManager. For advanced operations use `Cache::store()`, `Storage::manager()`, or `Storage::drive($name)`.

```php
use App\Plugins\{Mail, MailMessage, Notification, Notifiable, Notifications};

Mail::send((new MailMessage())
    ->to('user@example.com')
    ->subject('Welcome')
    ->text('Hello'));

final class WelcomeNotification extends Notification
{
    public function via(mixed $notifiable): array { return ['mail']; }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())->subject('Welcome')->text('Hello');
    }
}

Notifications::send($user, new WelcomeNotification());
```

The Mail gateway uses the modern `App\Mail` foundation. `App\Core\Mail` is the older API. Plugins `Notification` is the delivery base; `App\Core\Notification` and `App\Components\Notification` remain session-flash helpers. `Notifiable` may be used by a Model or plain object with an explicit mail route. The existing `mailer()`, `notifications()`, `cache()`, `storage()`, and other global helpers remain valid.

`Mail::send($message)` and ordinary Notifications deliver synchronously. `Mail::queue($message)` uses the existing Queue worker; a Notification implementing `ShouldQueue` supplies explicit `toQueuePayload()` and `fromQueuePayload()` methods, and an object recipient implements `QueueNotifiable` for worker-time reconstruction. These contracts have exact Plugins aliases. Internal Mail and Notification delivery jobs are framework implementation details and have no Plugins gateway.

`Queue::afterCommit($job)` is available through the same `App\Plugins\Queue` gateway as `Queue::dispatch($job)`. `Mail::queue($message, afterCommit: true)` delegates to it. Queueable Notifications can override `queueAfterCommit(): bool`; no additional marker or Plugins symbol is required. These APIs use the current Application's Database transaction lifecycle and do not change synchronous `Mail::send()` or ordinary Notification delivery. Selecting the Redis Queue driver, including `QUEUE_CONNECTION=auto`, does not require another Plugins import; the existing Queue gateway and worker handle the backend. See [Redis Queue](RedisQueue.md).

`Queue::chain([...])` and `Queue::batch([...])` compose existing `QueueJob` values and return an exact `CompositionHandle` alias. `CompositionStatus` is the exact immutable status alias; both expose counts rather than job payloads. `Queue::composition($id, $connection)`, `cancelComposition()`, and `pruneCompositions()` address one fixed Queue connection. See [Queue composition](QueueComposition.md).

`Event::listenQueued(EventClass::class, ListenerClass::class)` opts a class listener into the normal Queue. Its event must implement the exact `QueueableEvent` interface alias with `toQueuePayload()` and `fromQueuePayload()`; ordinary `Event::listen()` remains synchronous. The queued delivery job is internal. See [Events](Events.md#queued-class-listeners).

`Broadcast::send($event)` publishes an explicit `BroadcastEvent` through the selected adapter; `Broadcast::queue($event)` uses the existing Queue worker. `Channel::public($name)` and `Channel::private($name)` are exact value-type aliases used by the event contract. `Broadcast::privateChannel($pattern, $authorizer)` registers a server-side subscription rule; applications or provider adapters call `authorizePrivate($channel)` in an authenticated request. Provider authors can implement the exact `BroadcastAdapter` and `ChannelAuthorizer` interfaces and type adapter input as the exact `BroadcastMessage` alias. `BroadcastException` is an exact catch alias. Broadcasting is disabled by default and includes no WebSocket server. See [Broadcasting](Broadcasting.md).

The optional Redis gateway provides string commands and named connections without making Redis a requirement for other services:

```php
use App\Plugins\Redis;

Redis::set('notice', 'ready', ttl: 60);
$notice = Redis::get('notice');
Redis::connection('cache')->set('fragment', 'ready');
```

`redis()` returns the same Application-owned manager. Existing `App\Plugins\Cache`, `Session`, and `RateLimit` gateway calls work unchanged when their configured backend selects Redis; no backend-specific Plugins symbols are needed. See [Redis](Redis.md) for configuration, capability probing, and deployment limits.

Outgoing API calls use `App\Plugins\Http`, a separate service from incoming `Request` and `Response`. `Http::get($url)` and fluent calls such as `Http::withToken($token)->acceptJson()->post($url, $data)` resolve the current Application's HTTP Client. `HttpResponse` is the exact outgoing response alias; it does not replace `App\Plugins\Response`. See [HTTP Client](HttpClient.md).

`App\Plugins\RetryPolicy` is the exact bounded timing type used by `Http::withRetryPolicy($policy)`. The [shared retry guide](Retries.md) explains safe-method defaults and why Queue keeps its worker backoff. `App\Plugins\CircuitBreaker` and `CircuitPolicy` are exact types for optional local dependency protection; see [Circuit breaker](CircuitBreaker.md). `App\Plugins\IdempotentRequests` is the exact explicit route middleware for authenticated [HTTP idempotency](Idempotency.md).

Application-secret encryption, keyed MAC signing, and secure tokens use `App\Plugins\Crypt`. It resolves one Application-owned `CryptManager`; `App\Plugins\CryptException` is the exact general catch alias. `crypto()` reaches the same manager. The driver and key ring remain internal configuration, not Plugins symbols. See [Cryptography](Cryptography.md).

API representations extend `App\Plugins\ApiResource` and select public fields in `toArray()`. `UserResource::make($user)->response()` returns the existing JsonResponse; `UserResource::collection($users)` returns the exact `App\Plugins\ResourceCollection` alias. `App\Plugins\ResourceException` is the catch alias. Resources require no service gateway or request context, and normalization/omission internals have no Plugins symbols. See [API resources](ApiResources.md) for explicit output, conditional relations, metadata, and Page support.

Deliberate application errors use `throw App\Plugins\ApiError::make(code: 'order_conflict', message: 'The order cannot be changed.', status: 409)`. `ApiError` is an exact exception alias for `App\Api\ApiError`; the existing ExceptionHandler renders its safe JSON contract and request ID. It has no `response()` method or static service resolver. Internal API scope, mapping, and rendering types have no Plugins gateway. See [API responses and errors](ApiResponses.md).

Personal access tokens use the existing `Auth` gateway. `Auth::tokens('api')` manages tokens for a configured named guard; `Auth::token()` returns safe metadata for the request-selected guard and `Auth::tokenAllows('orders.read')` checks only its token ability. Routes attach `RequireToken::guard('api')` followed by `RequireTokenAbility::named('orders.read')`. The middleware and issued/metadata return types have Plugins aliases; repository drivers, token hashes, and parsing internals remain canonical-only. See [API tokens](ApiTokens.md).

Optional [roles and permissions](RBAC.md) use `Rbac::createRole()`, `Rbac::createPermission()`, `Rbac::grantPermission()`, and `Rbac::assignRole()` from `App\Plugins\Rbac`. The same gateway exposes removal and membership checks. Repository classes and identity digests are internal; `Gate` and `RequireAbility` still make authorization decisions.

External OIDC sign-in uses `OAuth::provider('company')->redirect()` and `->callback($request)`, or the equivalent `oauth()->provider('company')` helper. The callback returns the exact `ExternalIdentity` alias; an application maps its issuer and subject to a local identity, then explicitly calls `Auth::login($user)`. Discovery, JWKS, session transactions, and provider token exchange remain internal. See [OIDC login](OAuth.md).

SqueHub-profile webhooks use `Webhook::event('order.paid', $data)`, `Webhook::endpoint('billing')->send($event)` or `->queue($event)`, and `Webhook::source('billing')->handle($request, $callback)`. `webhooks()` resolves the same Application-owned manager. `WebhookEvent`, `WebhookDeliveryResult`, `WebhookDeliveryTicket`, and `VerifiedWebhook` are exact value-type aliases. Signing, transport, receipt/delivery stores, and the internal Queue job stay in their canonical namespaces. See [Webhooks](Webhooks.md) for configuration, signing, CSRF, and at-least-once behavior.

`App\Plugins\Contract` is the application-facing gateway for explicit [Application Contracts](ApplicationContract.md). `App\Plugins\ContractSchema` aliases the SqueHub-native API/JSON schema value type. They can describe route requests, responses, security, API Resources, and outgoing Webhooks, then export OpenAPI 3.2.1. `Contract::verify($caseName)` also registers an explicit [API verification case](ApiVerification.md) through the same Application-owned manager; no additional Plugins alias is required for verifier internals. The existing `App\Plugins\Schema` continues to mean **database table schema**, so do not substitute it for `ContractSchema`. Compiler, registry, and normalization internals have no Plugins gateway.

## Supported symbols

The following tables classify **all 142** current `App\Plugins` symbols as stable public v2 gateways or exact public type aliases. This includes 39 forwarding/base classes, 100 exact aliases, two traits, and one interface. An exact alias remains the same public type as its canonical name. No Plugins symbol is a v1-only compatibility gateway. Names absent from these tables stay in their canonical namespaces; there is no dynamic fallback. Discovery, transport, driver, store, compiler, and execution internals do not acquire public gateway status just because their canonical class can be autoloaded. The v1 `$router` bridge and compatibility-only helpers are classified separately in [Routing](Routing.md#compatibility) and [Helpers](Helpers.md#v1-compatibility-and-migration).

| Plugins symbol | Canonical implementation | Gateway form |
| --- | --- | --- |
| `Route` | `App\Routing\Route` | Static forwarding class |
| `View` | `App\Core\View` | Inherited static API |
| `Model` | `App\Database\Model` | Abstract base |
| `ApiResource` | `App\Api\ApiResource` | Abstract base |
| `Contract` | `App\Api\Contract\Contract` | Static declaration and export gateway |
| `ModelFactory` | `App\Database\Factories\ModelFactory` | Abstract base |
| `Seeder` | `App\Database\Seeding\Seeder` | Abstract base |
| `ReversibleSeeder` | `App\Database\Seeding\ReversibleSeeder` | Interface for explicit Seeder rollback |
| `ServiceProvider` | `App\Foundation\ServiceProvider` | Abstract base |
| `Kit` | `App\Kits\Kit` | Abstract lifecycle base |
| `TestCase` | `App\Testing\TestCase` | PHPUnit base subclass for disposable SqueHub application tests |
| `DB` | `App\Database\DatabaseManager` via `App\Database\Database` | Static forwarding class |
| `Cache` | `App\Cache\CacheStore` via `App\Cache\Cache` | Static forwarding class |
| `Lock` | `App\Locks\LockManager` via `App\Locks\Lock` | Static acquire, run, and manager gateway |
| `Storage` | `App\Storage\StorageManager` via `App\Storage\Storage` | Static forwarding class |
| `Auth` | `App\Auth\AuthManager` via `App\Auth\Auth` | Static forwarding class, including named token management |
| `SignedUrl` | `App\Security\SignedUrl\SignedUrlManager` via `App\Security\SignedUrl\SignedUrl` | Static generation, verification, and middleware gateway |
| `OAuth` | `App\OAuth\OAuthManager` via `App\OAuth\OAuth` | Static configured-provider and manager gateway |
| `Webhook` | `App\Webhooks\WebhookManager` via `App\Webhooks\Webhook` | Static named endpoint, source, event, and manager gateway |
| `Gate` | `App\Authorization\AuthorizationManager` | Static forwarding class |
| `Rbac` | `App\Authorization\Rbac\RbacManager` | Static role and permission gateway |
| `Event` | `App\Events\EventDispatcher` | Static forwarding class, including queued class-listener registration |
| `Broadcast` | `App\Broadcasting\BroadcastManager` via `App\Broadcasting\Broadcast` | Static publication, queue, and private-channel gateway |
| `Log` | `App\Logging\Logger` | Static forwarding class |
| `Mail` | `App\Mail\Mailer` | Static forwarding class |
| `Notifications` | `App\Notifications\NotificationManager` | Static forwarding class |
| `Queue` | `App\Queue\QueueManager` | Static dispatch, composition, after-commit dispatch, and manager gateway |
| `Redis` | `App\Redis\RedisManager` | Static string commands, named connection, and capability gateway |
| `Http` | `App\HttpClient\HttpClient` | Static request and fluent builder gateway |
| `Crypt` | `App\Cryptography\CryptManager` | Static encryption, MAC, token, and manager gateway |
| `Schedule` | `App\Scheduler\Scheduler` | Static registration and manager gateway |
| `Notification` | `App\Notifications\Notification` | Abstract base |
| `Validator` | `App\Validation\Validator` | Static `for($data)` entry |
| `Rule` | `App\Validation\Rule` | Static rule builder |
| `Session` | `App\Session\SessionManager` | Static forwarding class |
| `Health` | `App\Health\HealthManager` via `App\Health\Health` | Static live, ready, doctor, and infrastructure gateway |
| `Mfa` | `App\Mfa\MfaManager` via `App\Mfa\Mfa` | Static named-guard MFA gateway |
| `Translation` | `App\Translation\TranslationManager` via `App\Translation\Translation` | Static locale and catalog gateway |
| `RateLimit` | `App\RateLimit\RateLimiter` | Static forwarding class |
| `AccountSecurity` | `App\AccountSecurity\AccountSecurityManager` | Static manager entry |
| `Csrf` | `App\Security\Csrf\CsrfTokenManager` | Static manager entry |
| `Notifiable` | `App\Notifications\Notifiable` | Trait |
| `StopsEventPropagation` | `App\Events\StopsEventPropagation` | Trait |
| `Authenticatable` | `App\Auth\Contracts\Authenticatable` | Exact interface alias |
| `ValidationRule` | `App\Validation\ValidationRule` | Exact interface alias |
| `ValidatedData` | `App\Data\ValidatedData` | Exact interface alias for optional Request rule declarations |
| `QueuePayloadData` | `App\Data\QueuePayloadData` | Exact interface alias for selected durable typed values |
| `StoppableEvent` | `App\Events\StoppableEvent` | Exact interface alias |
| `QueueableEvent` | `App\Events\QueueableEvent` | Exact interface alias for queued event payloads |
| `BroadcastEvent` | `App\Broadcasting\BroadcastEvent` | Exact interface alias for explicit publications |
| `BroadcastAdapter` | `App\Broadcasting\BroadcastAdapter` | Exact interface alias for provider adapters |
| `ChannelAuthorizer` | `App\Broadcasting\ChannelAuthorizer` | Exact interface alias for private-channel rules |
| `NotificationChannel` | `App\Notifications\NotificationChannel` | Exact interface alias |
| `QueueJob` | `App\Queue\QueueJob` | Exact interface alias |
| `ShouldQueue` | `App\Notifications\ShouldQueue` | Exact interface alias |
| `QueueNotifiable` | `App\Notifications\QueueNotifiable` | Exact interface alias |
| `ViewContext` | Implemented by `App\View\ViewContext` | Public read-only composer context interface |

The following are exact PHP type aliases. The alias and canonical name identify the **same class**, so values, return types, and `instanceof` checks retain their canonical behavior.

| Plugins symbols | Canonical subsystem |
| --- | --- |
| `MailMessage`, `MailAddress` | `App\Mail` |
| `Request`, `Response`, `JsonResponse`, `RedirectResponse`, `Cookie` | `App\Http` |
| `FragmentRenderResult`, `ViewRenderResult`, `ViewNotFoundException`, `FragmentNotFoundException`, `InvalidFragmentNameException`, `ViewRenderException` | `App\View` |
| `CompilerException` | `App\View\Compiler` |
| `ViewTestResult` | `App\Testing` |
| `ResourceCollection`, `ResourceException`, `ApiError` | `App\Api` |
| `ContractSchema` | `App\Api\Contract\Schema` |
| `TokenManager`, `IssuedToken`, `TokenMetadata` | `App\Auth\Tokens` |
| `ExternalIdentity` | `App\OAuth` |
| `WebhookEvent`, `WebhookDeliveryResult`, `WebhookDeliveryTicket`, `VerifiedWebhook` | `App\Webhooks` |
| `HttpResponse`, `HttpClientException` | `App\HttpClient` |
| `RetryPolicy`, `CircuitBreaker`, `CircuitPolicy`, `CircuitOpenException`, `CircuitException` | `App\Reliability` |
| `IdempotentRequests` | `App\Idempotency\Middleware` |
| `LockHandle` | `App\Locks` |
| `CryptException` | `App\Cryptography` |
| `ModelQuery`, `QueryBuilder`, `TransactionIsolation` | `App\Database` |
| `ModelCollection` | `App\Database\Collections` |
| `Page`, `CursorPage` | `App\Database\Pagination` |
| `MorphMap`, `MorphTo`, `MorphOne`, `MorphMany` | `App\Database\Relations` |
| `ModelObserverRegistry`, `ModelLifecycleEvent` | `App\Database\Lifecycle` |
| `Schema`, `Table` | `App\Database\Schema` |
| `RateLimitRule`, `RateLimitResult` | `App\RateLimit` |
| `ValidationResult`, `ErrorBag`, `UniqueRule`, `UploadedFile` | `App\Validation` |
| `DataMapper`, `DataMappingException`, `NestedData`, `TypedPayloadRegistry`, `TypedPayloadException` | `App\Data` |
| `SecurityToken` | `App\AccountSecurity` |
| `HealthCheck`, `HealthManager`, `HealthReport`, `HealthResult` | `App\Health` |
| `AuthorizationDecision` | `App\Authorization` |
| `RateLimitRequests` | `App\RateLimit\Middleware` |
| `RequireAbility` | `App\Authorization\Middleware` |
| `RequireToken`, `RequireTokenAbility` | `App\Auth\Middleware` |
| `QueueException` | `App\Queue` |
| `CompositionHandle`, `CompositionStatus` | `App\Queue\Composition` |
| `Channel`, `BroadcastMessage`, `BroadcastException` | `App\Broadcasting` |
| `ScheduledTask`, `SchedulerException`, `SchedulerRunResult` | `App\Scheduler` |
| `TestApplication`, `TestClient`, `TestResponse` | `App\Testing` |
| `KitContext` | `App\Kits` |

Each symbol has a matching, capitalized `App/Plugins/<Name>.php` source file. Composer's existing `App\` PSR-4 rule resolves these files. Exact aliases are necessary for final value objects and framework-returned types; wrapping them would break type identity. The forwarders call the same Application-owned resolver or manager and do not instantiate duplicate services. The route and View gateways similarly share canonical state.

## Boundaries and compatibility

- `App\Plugins\...` names framework APIs. `Project\Models\User`, `Project\Events\UserRegistered`, `Project\Controllers\UserController`, and other `Project\...` classes remain application code.
- Existing canonical imports, global helpers, and legacy APIs continue to work. No mass migration of Project code is required.
- Static service gateways follow the currently bootstrapped Application, just as existing helpers do. In a process with multiple Applications, resolve a manager from the intended Application container when that context must remain fixed.
- No Plugins-specific diagnostics, exception domain, or backend exists. Canonical subsystem diagnostics and exceptions still apply.
- Advanced and internal APIs remain available in their canonical namespaces. The gateway does not promise every implementation class as a public shortcut.
- This namespace does not install or discover third-party extensions, packages, manifests, drivers, or lifecycle hooks.

Future SqueHub features intended for normal application use must provide an appropriate `App\Plugins` entry when that feature is implemented. Queue provides `Queue`, `QueueJob`, `QueueException`, `CompositionHandle`, and `CompositionStatus`; queued Events use `QueueableEvent`, while queued Notifications use their distinct `ShouldQueue` and `QueueNotifiable` contracts. Broadcasting provides its explicit event, channel, adapter, and authorizer types. Scheduler provides `Schedule`, `ScheduledTask`, `SchedulerRunResult`, and `SchedulerException`; Kit authors use `Kit` and `KitContext`. Internal Kit discovery, state, and lifecycle planning types remain in `App\Kits`, as internal delivery jobs, drivers, codecs, reservations, stores, and workers remain in their canonical subsystem namespaces.

Optional [Typed application data](ApplicationData.md) exposes exact `DataMapper`, `DataMappingException`, `NestedData`, `ValidatedData`, `QueuePayloadData`, `TypedPayloadRegistry`, and `TypedPayloadException` aliases. The typed registry is an Application-owned Container service, not a static facade; Queue and Event codecs remain in their canonical internal namespaces. A public data value does not gain automatic Queue eligibility merely by using the mapper.

The optional [Agent and AI integration](AgentAndAI.md) is an explicit local CLI/MCP inspection tool rather than an ordinary application-facing service. Its `App\Agent` manager and capability types remain in their canonical namespace; the framework does not add an `App\Plugins\Agent` static facade or make application HTTP code depend on the MCP SDK. Existing public Plugins symbols are included only as bounded metadata in Agent context.

The `squehub://framework` `public_api.symbols` list is built from canonical PHP filenames directly under the framework's `App/Plugins/` directory, with a 256-file bound. It is an inventory of filesystem-declared symbol names, not an autoload, availability proof for every extension, or a call through those Plugins gateways. The same resource includes the registered CLI inventory when the console supplies it. See [MCP tools and resources](AgentMcpTools.md).

The Agent and AI integration passed Windows and user-run native Linux verification, including official MCP SDK client interoperability on Linux. The Agent integration continues to expose no new Plugins gateway; see [verification status](Status.md).

Scheduler definition files may live in `Project/Scheduler/`, the legacy application-owned `Project/<Name>/Scheduler/` layout, or an enabled Package's `Project/Packages/<PackageName>/Scheduler/`. They are loaded only by `schedule:list` and `schedule:run`. Definition files use `.php`. See [Scheduler](Scheduler.md) for registration and execution rules.
