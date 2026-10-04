# Typed application data

Typed application data is **optional**. Arrays, Models, ordinary services, `Request::validate()`, Views, API Resources, Events, Queue jobs, and Notifications retain their existing contracts. Use a small PHP class when a named constructor boundary makes application code clearer; no base DTO class, generator, database table, or new CLI command is required.

The shipped entry points are `DataMapper::map()`, `Request::validatedAs()`, `NestedData`, `ValidatedData`, `QueuePayloadData`, `TypedPayloadRegistry`, and `TypedPayloadException`. Applications can import `App\Plugins\Request`, `DataMapper`, `DataMappingException`, `NestedData`, `ValidatedData`, `QueuePayloadData`, `TypedPayloadRegistry`, and `TypedPayloadException`; the data symbols are exact aliases of their `App\Data` counterparts, with no parallel mapper or registry. See [Plugins](Plugins.md) for the gateway mapping.

```php
$values = $request->validate([
    'name' => 'required|string',
    'email' => 'required|email',
]);
```

The optional typed equivalent runs the **same Validator first**, then calls the chosen class's public constructor with only declared, validated fields:

```php
use App\Plugins\{Request, ValidatedData};

final readonly class CreateUserData implements ValidatedData
{
    public function __construct(
        public string $name,
        public string $email,
        public int $age,
    ) {
    }

    public static function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'email' => 'required|email',
            'age' => 'required|integer|min:18',
        ];
    }
}

$data = $request->validatedAs(CreateUserData::class);
```

`ValidatedData::rules()` is an opt-in declaration, not a replacement Validator. A plain class can instead receive explicit rules at the call site: `$data = $request->validatedAs(CreateUserData::class, ['name' => 'required|string', 'email' => 'required|email', 'age' => 'required|integer']);`. The third argument supplies the same optional custom messages accepted by `validate()`. Omitting rules for a class that does not implement `ValidatedData` is a configuration error; SqueHub does not construct a value from unchecked request input.

## Constructor mapping and conversion

`App\Plugins\DataMapper::map(ClassName::class, $values)` is available for **trusted, already selected** data sources outside Request validation. It does not validate those values. Never pass raw HTTP input directly to it in place of `Request::validatedAs()`. The mapper uses the public constructor, including defaults and nullable parameters; it does not inject properties, resolve services, hydrate Models, or use PHP serialization. Both ordinary final and `final readonly` classes work. Unrecognized input keys are ignored and never become constructor arguments. Keep Model fillable/guarded rules and authorization checks in place when a service later persists data.

| Constructor type | Accepted input |
| --- | --- |
| `string` | A PHP string, without implicit trimming or numeric casting. |
| `int` | A PHP integer or canonical decimal string within PHP's integer range; `"001"` and floats are rejected. |
| `float` | A finite float, integer within ±(`2^53 - 1`), or strict finite decimal/exponent string within that magnitude. Decimal fractions still have normal floating-point approximation. |
| `bool` | PHP booleans, `0`/`1`, or the exact strings `"0"`, `"1"`, `"false"`, `"true"`. |
| `array` | A PHP array; its members are not implicitly converted to objects. |
| Backed enum | A valid backing value, using the integer policy for int-backed cases. |
| Nullable type | Actual `null`; an empty string does not become null. |

Missing required fields and invalid input types/enum cases become the existing `ValidationException` at the Request boundary. Browser and API responses therefore retain their existing safe 303/422 behavior. Unsupported constructor types, missing rules, invalid nested declarations, Model construction, and constructor invariant failures raise a safe `DataMappingException`; a constructor's original exception remains its internal cause but its message and input values are not published. The mapper rejects union, intersection, `mixed`, variadic, by-reference, and unmarked object parameters. It does not maintain a cross-request reflection cache or shared mutable mapping state.

Nested construction requires a deliberate parameter attribute. The nested class comes from trusted PHP type declarations, never from an input class name:

```php
use App\Plugins\{NestedData, ValidatedData};

final readonly class AddressData
{
    public function __construct(public string $city) {}
}

final readonly class RegisterData implements ValidatedData
{
    public function __construct(
        public string $name,
        #[NestedData] public AddressData $address,
    ) {
    }

    public static function rules(): array
    {
        return [
            'name' => 'required|string',
            'address' => 'required|array',
            'address.city' => 'required|string',
        ];
    }
}
```

The `address` array is filtered by the existing nested Validator rules before `AddressData` is constructed. Extra `address` members are not copied. An unmarked `AddressData` parameter is rejected, and the recursion depth is bounded. Ordinary `array` fields remain arrays; SqueHub does not guess a collection item type.

## Browser form: CSRF, errors, and old input

This example assumes an existing `profiles` table and an application service that owns persistence. The typed object is an input boundary, not an ORM substitute:

```php
namespace Project\Services;

use Project\Data\CreateProfileData;

final class CreateProfile
{
    public function create(CreateProfileData $data): void
    {
        db('profiles')->insert([
            'name' => $data->name,
            'email' => $data->email,
        ]);
    }
}
```

```php
namespace Project\Data;

use App\Plugins\ValidatedData;

final readonly class CreateProfileData implements ValidatedData
{
    public function __construct(public string $name, public string $email) {}

    public static function rules(): array
    {
        return ['name' => 'required|string|max:100', 'email' => 'required|email'];
    }
}
```

```php
namespace Project\Controllers;

use App\Plugins\{RedirectResponse, Request, Response, View};
use Project\Data\CreateProfileData;
use Project\Services\CreateProfile;

final class ProfileController
{
    public function __construct(private CreateProfile $profiles) {}

    public function form(): Response
    {
        return View::response('Profiles.New');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validatedAs(CreateProfileData::class);
        $this->profiles->create($data);
        return new RedirectResponse(route('profiles.new'), 303);
    }
}
```

```php
use App\Plugins\Route;
use Project\Controllers\ProfileController;

Route::path('/profiles/new')->get([ProfileController::class, 'form'])->named('profiles.new');
Route::path('/profiles')->post([ProfileController::class, 'store'])->named('profiles.store');
```

```html
<form method="POST" action="{{ route('profiles.store') }}">
    @csrf
    <label for="name">Name</label>
    <input id="name" name="name" value="{{ old('name', '') }}">
    @error('name')<p role="alert">{{ $message }}</p>@enderror

    <label for="email">Email</label>
    <input id="email" name="email" value="{{ old('email', '') }}">
    @error('email')<p role="alert">{{ $message }}</p>@enderror

    <button type="submit">Create</button>
</form>
```

Save the template as `Project/Views/Profiles/New.squehub.php`. The existing global CSRF middleware rejects a bad or absent token with 403 **before** validation or construction. A valid but invalid submission follows the existing safe browser 303 redirect, `ErrorBag`, and filtered one-request old-input flow when the previous internal GET is known. Keep submitted text in escaped `{{ ... }}`. Passwords, tokens, uploads, CSRF fields, and other sensitive fields remain excluded from old input; typed mapping does not introduce a second flash store. A successful submission reaches the service with only the two declared fields and returns the explicit success redirect. See [Forms](Forms.md), [Validation](Validation.md), and [CSRF](Csrf.md).

## JSON input and an explicit API Resource

The same mapping works with a JSON request. The selected `ApiResource` continues to own **response projection**; a typed input object does not become public JSON automatically:

```php
namespace Project\Api\Resources;

use App\Plugins\ApiResource;
use Project\Data\CreateProfileData;

final class ProfileResource extends ApiResource
{
    public function toArray(): array
    {
        /** @var CreateProfileData $profile */
        $profile = $this->resource;
        return ['name' => $profile->name];
    }
}
```

```php
use App\Plugins\{JsonResponse, Request};
use Project\Api\Resources\ProfileResource;
use Project\Data\CreateProfileData;

public function store(Request $request): JsonResponse
{
    $data = $request->validatedAs(CreateProfileData::class);
    // Application logic may persist the data before selecting response fields.
    return ProfileResource::make($data)->response(201);
}
```

The example response contains `name`, not `email`. In a real endpoint, authorize the action and select the persisted result appropriate for its Resource. An enabled API error scope preserves `validation_failed` 422 details and its existing request ID; a constructor invariant failure remains a generic 500. Existing array/Model Resources, pagination, token guards, CORS, and explicit Application Contract declarations are unchanged. SqueHub does not infer an OpenAPI schema from a PHP constructor or automatically serialize typed objects into Resource fields. See [API development](ApiDevelopment.md) and [API resources](ApiResources.md).

## Explicit typed Queue payloads

A plain data object is **not** automatically eligible for persistence. A value must implement `App\Plugins\QueuePayloadData` and trusted application bootstrap must register its class under a stable alias and positive version. The Application owns the canonical `App\Data\TypedPayloadRegistry`; `App\Plugins\TypedPayloadRegistry` is its exact type alias. Resolve that service from the intended Application's Container. There is no process-global payload registry or helper.

```php
namespace Project\Data;

use App\Plugins\QueuePayloadData;

final readonly class UserRegisteredData implements QueuePayloadData
{
    public function __construct(public int $userId) {}

    public function toQueueData(): array
    {
        return ['user_id' => $this->userId];
    }

    public static function fromQueueData(array $data): static
    {
        if (!is_int($data['user_id'] ?? null) || $data['user_id'] < 1) {
            throw new \InvalidArgumentException('Invalid queued user identifier.');
        }
        return new static($data['user_id']);
    }
}
```

Register the same alias/version/class at sender and worker Application boot, for example from an application Service Provider:

```php
use App\Plugins\{ServiceProvider, TypedPayloadRegistry};
use Project\Data\UserRegisteredData;

final class ApplicationPayloadProvider extends ServiceProvider
{
    public function boot(): void
    {
        $payloads = $this->app->container()->make(TypedPayloadRegistry::class);
        $payloads->define('user.registration', 1, UserRegisteredData::class);
    }
}
```

At a trusted Queue job/Event/Notification boundary, call `$envelope = $payloads->encode(new UserRegisteredData($userId));`. The result is exactly `['type' => 'user.registration', 'version' => 1, 'data' => ['user_id' => $userId]]`; the receiving Application calls `$payloads->decode($envelope)` after its registration has booted. Embed that array in the **existing explicit** `QueueJob::toQueuePayload()`, `QueueableEvent::toQueuePayload()`, or queueable Notification payload where your application deliberately uses this value. Those outer contracts and their workers remain unchanged; ordinary in-process Events do not require serialization. There is no automatic event, notification, or Model-to-DTO conversion.

Only `toQueueData()` fields persist. The registry never introspects private properties or trusts a class name from stored data. Aliases are bounded lowercase identifiers; duplicate or unknown aliases, unknown versions, malformed envelopes, non-JSON values, nested objects, and unsupported reconstruction fail through the safe `App\Plugins\TypedPayloadException`. Exceptions thrown by application `toQueueData()` or `fromQueueData()` callbacks, even a callback-thrown `TypedPayloadException`, become generic safe `TypedPayloadException` messages with **no previous cause**. Registry-generated validation errors retain specific safe messages. Reconstruction must still validate its own field shape, as the example does. The existing Queue JSON codec caps the **entire queued job** at 60,000 bytes, so a typed envelope cannot bypass that limit. Persisted payloads can nevertheless contain application secrets: encrypt/protect Queue storage and choose fields deliberately. Do not put tokens or recipient details in diagnostics or failed-job metadata. Native `serialize()`/`unserialize()` is not used.

Queue retains **at-least-once** execution. A worker crash or retry may process the same typed value again; use application-level idempotency for consequential side effects. Typed envelopes provide explicit type and version, not exactly-once delivery, a migration/upcaster system, a new Queue driver, or a second Notifications transport. See [Queue](Queue.md), [Events](Events.md), and [Notifications](Notifications.md).

## Runtime limits

Typed application data was exercised with Windows PHP 8.2.12 and SQLite 3.39.2, and on a native case-sensitive Linux filesystem with disposable SQLite. The focused and full suites passed in those environments. These checks do not establish macOS support, every PHP or Linux variant, live MySQL or Redis behavior for typed data, or production multi-node Queue operation. Verify the selected runtime and durable backends for your deployment; see [v2 status](Status.md).
