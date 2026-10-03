# API resources

An API resource maps a source value to the public fields of a JSON representation. Define application resources under `Project/Api/Resources/` and extend `App\Plugins\ApiResource`. This directory is an application convention supported by the existing `Project\` autoloader; it requires no registration, provider, or generator.

For a contracted Resource endpoint, an explicit [API verification case](ApiVerification.md) sends a real request through the Kernel and validates the resulting JSON against `contractSchema()`. A closed schema can detect unexpected public fields in that response; it cannot decide whether the schema itself was designed with the right privacy policy.

An explicitly registered `contractSchema()` can also inform [generated client types](SdkGeneration.md). The generator does not invoke `toArray()` or infer hidden Model attributes, so keep the declared schema aligned with the actual public Resource output and verify representative responses.

Resources can read Models, arrays, application objects, or scalars. Their output is explicit: a raw Model or another arbitrary object cannot appear in the resolved result. Resources do not validate request input or authorize access. Perform those checks in the normal [Validation](Validation.md) and [Authorization](Authorization.md) paths before constructing the response. The base deliberately accepts a mixed source; put any resource-specific source type checks in its constructor or `toArray()`.

## Define public fields

```php
namespace Project\Api\Resources;

use App\Plugins\ApiResource;
use Project\Models\User;

final class UserResource extends ApiResource
{
    public function toArray(): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }
}
```

Only `id` and `name` are selected here. A Model's password, tokens, other attributes, loaded relations, and internal state are not serialized automatically. Model `$hidden` rules still matter wherever Model serialization is used elsewhere; a resource has its own explicit field selection.

`$this->resource` is a protected readonly source reference. It cannot be reassigned, but an object stored in it is not deep-frozen or copied. Keep `toArray()` a pure transformation: avoid writes, source mutations, relationship loading, and other side effects. Resolving again can call `toArray()` again and can observe a source object that application code has changed.

`make(mixed $resource): static` constructs the selected resource class. The inherited constructor is sufficient for ordinary resources. If you add a constructor, keep the source as its only required argument so `make()` and `collection()` can construct it consistently; additional parameters must be optional.

## Return an HTTP response

Use `response()` as the HTTP boundary:

```php
namespace Project\Controllers;

use App\Plugins\{JsonResponse, Request};
use Project\Api\Resources\UserResource;
use Project\Models\User;

final class UserApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email',
        ]);

        $user = User::create($data);

        return UserResource::make($user)->response(201, [
            'Location' => '/api/users/' . $user->id,
        ]);
    }
}
```

This example assumes a `users` table and a User Model with `name` and `email` in `$fillable`. Attach the application's authentication and authorization policy to the route or controller. The normal [CSRF](Csrf.md) and [HTTP](Http.md) rules continue to apply.

`response(int $status = 200, array $headers = []): JsonResponse` resolves the representation and passes it to the existing strict JSON response implementation. Its JSON content type and header behavior are unchanged. Controllers must return `UserResource::make($user)->response()` or a collection's `response()`; the dispatcher does not convert a directly returned resource to a response.

For application code or tests, `resolve()` returns the same normalized data before JSON encoding. Both resources and resource collections implement `JsonSerializable`, so `json_encode($resource, JSON_THROW_ON_ERROR)` also uses resolution. Calling your implementation's `toArray()` directly bypasses normalization; use `resolve()` to obtain final output.

## Null, empty values, and output shapes

Single resources and ordinary collections are bare by default. A resource wrapping `null` resolves to `null` without calling `toArray()`:

```php
UserResource::make($user)->resolve(); // ['id' => 7, 'name' => 'Ada']
UserResource::make(null)->resolve();  // null
UserResource::collection([])->resolve(); // []
```

| Construction | Resolved shape |
| --- | --- |
| `UserResource::make($user)` | Selected field array |
| `UserResource::make(null)` | `null` |
| `UserResource::collection($users)` | List of selected field arrays or `null` items |
| `UserResource::make($user)->withMeta(['request_id' => 'r1'])` | `['data' => selected fields, 'meta' => ['request_id' => 'r1']]` |
| `UserResource::collection($users)->withMeta(['request_id' => 'r1'])` | `['data' => selected item list, 'meta' => ['request_id' => 'r1']]` |
| `UserResource::collection($page)` | `['data' => selected item list, 'meta' => page metadata]` |

An empty field array, empty collection, or empty metadata array encodes as JSON `[]`, not `{}`. Explicit null fields remain present. A null resource with metadata has the shape `['data' => null, 'meta' => ...]`.

## Collections and source values

```php
use Project\Api\Resources\UserResource;
use Project\Models\User;

$users = User::query()->sort('id')->get();

return UserResource::collection($users)->response();
```

`collection(iterable|Page $resources): ResourceCollection` accepts arrays, ModelCollections, generators, and other iterables, plus an existing Page. Each source item is mapped through the selected resource class. A null item resolves to `null`. Source keys are discarded, input order is preserved, and output is a list indexed from zero.

An iterable is consumed and its items are snapshotted when the collection is constructed. Subsequent resolutions do not rewind the original iterator. This snapshots the item list, not the mutable state of objects inside it; bound input sizes before constructing a collection.

Array sources use the same explicit mapping:

```php
namespace Project\Api\Resources;

use App\Plugins\ApiResource;

final class SummaryResource extends ApiResource
{
    public function toArray(): array
    {
        return [
            'label' => $this->resource['label'],
            'count' => $this->resource['count'],
        ];
    }
}

// SummaryResource::make(['label' => 'Open', 'count' => 3])->response();
```

## Conditional and nested fields

`when(bool $condition, mixed $value)` is a protected helper for `toArray()`. A false condition omits that entry. A true condition includes the value, including an explicit `null`. Pass a `Closure` for lazy work; the closure executes only when the condition is true. An ordinary expression is evaluated by PHP before `when()` is called.

Use `relationLoaded()` with `getRelation()` to serialize only relationships the application already loaded:

```php
namespace Project\Api\Resources;

use App\Plugins\ApiResource;
use Project\Models\User;

final class DetailedUserResource extends ApiResource
{
    public function toArray(): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'profile' => $this->when(
                $user->relationLoaded('profile'),
                fn () => ProfileResource::make($user->getRelation('profile')),
            ),
            'roles' => $this->when(
                $user->relationLoaded('roles'),
                fn () => RoleResource::collection($user->getRelation('roles')),
            ),
        ];
    }
}

final class ProfileResource extends ApiResource
{
    public function toArray(): array
    {
        return ['bio' => $this->resource->bio];
    }
}

final class RoleResource extends ApiResource
{
    public function toArray(): array
    {
        return ['name' => $this->resource->name];
    }
}
```

In application code, place each class in its matching `Project/Api/Resources/<Class>.php` file. The example assumes a nullable `profile` relationship and a to-many `roles` relationship. Load them deliberately before resource construction, for example with `User::query()->with(['profile', 'roles'])->get()`. An unloaded relationship is omitted; a loaded missing profile becomes `null`; loaded empty roles become `[]`.

The resource layer does not issue SQL. Ordinary Model property access such as `$user->roles` can lazy-load a relationship, so it can issue SQL inside application-written `toArray()` code. The loaded-relation pattern above avoids that access. It uses the existing Model relation cache and does not add request context or a separate relationship loader. See [Relationships](Relationships.md).

Nested resources and resource collections are resolved recursively wherever they occur in output arrays. Their own metadata envelopes are retained. Omitted entries are removed at every array depth; arrays that were lists are reindexed after omission, while associative keys are retained. For example, a list containing `['first', $this->when(false, 'second'), null]` resolves to `['first', null]`.

## Metadata is immutable and replaced

```php
$plain = UserResource::make($user);
$first = $plain->withMeta(['request_id' => 'r1']);
$second = $first->withMeta(['source' => 'directory']);

$plain->resolve();  // Bare fields.
$first->resolve();  // data + meta containing request_id.
$second->resolve(); // data + meta containing only source.
```

`withMeta(array $metadata): static` returns a clone and leaves the original resource or collection unchanged. Repeated calls replace application metadata rather than merge it. Even `withMeta([])` opts into the `data`/`meta` envelope. Top-level metadata keys must be nonempty strings; numeric keys and empty names raise `ResourceException`. Metadata values pass through the same strict normalization as data.

## Reuse existing pagination

```php
use Project\Api\Resources\UserResource;
use Project\Models\User;

$page = User::query()->sort('id')->page(2, 20);

return UserResource::collection($page)
    ->withMeta(['source' => 'directory'])
    ->response();
```

A Page collection always returns `data` and `meta`. Its metadata uses the existing Page getters: `page`, `per_page`, `total`, `pages`, `from`, `to`, `has_next`, and `has_previous`. Application metadata is added beside those fields. Supplying any of these reserved names to `withMeta()` raises `ResourceException`, even if the supplied value matches the Page. Replacing application metadata leaves Page metadata intact.

Resource construction reads `items()` and metadata getters; it does not call Page's `toArray()`, reserialize Models, query the database, create a paginator, or derive URLs from a Request. Empty and out-of-range pages retain the Page's existing total, page count, and null `from`/`to` behavior, with `data: []`. See [Pagination](Pagination.md) for the database work performed when the Page itself is created.

## Strict output and errors

Resolved output and metadata may contain strings, integers, finite floats, booleans, nulls, arrays, and nested resources or resource collections. Arbitrary objects are rejected, including raw Models, `DateTimeInterface` values, and unrelated `JsonSerializable` objects. Streams, closures outside a true `when()` value, and non-finite floats are also rejected. Select fields explicitly and format dates to strings, for example `$date->format(\DateTimeInterface::ATOM)`, before returning them. Resolution also validates JSON encoding, including UTF-8 in strings and array keys.

`App\Plugins\ResourceException` is the public exception alias for resource failures. Normalization detects resource cycles and uses a depth limit of 64 to reject excessive nesting, including recursive arrays. Source construction, iteration, and transformation failures are wrapped with a generic safe message and their cause preserved. Invalid JSON values and text encoding also raise `ResourceException` at resolution. Do not expose exception causes to clients; they can contain application details. A successfully resolved value then passes through the existing JsonResponse encoder and normal HTTP status/header validation.

The public Plugins entries are `ApiResource` (an abstract base), `ResourceCollection` (an exact final-class alias), and `ResourceException` (an exact exception alias). Canonical types live in `App\Api`; internal normalization and omission types have no Plugins gateways. There is no resource service provider, global helper, ambient Request, or resource-specific Diagnostics service.

Phase 12B [API responses and errors](ApiResponses.md) adds an opt-in error contract around the existing HTTP boundary. It preserves all resource shapes above: single resources and collections remain bare unless their existing metadata or Page rules add an envelope. API correlation adds an `X-Request-ID` header without inserting a body field. Continue returning `->response()` from controllers and using `resolve()` outside HTTP.

[API versioning](ApiVersioning.md), [CORS](Cors.md), [personal access tokens](ApiTokens.md), and the separate [SqueHub webhook profile](Webhooks.md) are independent policies/services. An API Resource may optionally declare `public static function contractSchema(): ?ContractSchema` for the [Application Contract](ApplicationContract.md); existing Resources do not need that method, and export does not run `toArray()` to infer fields. The contract alone does not prove runtime Resource/schema consistency; add explicit [API verification cases](ApiVerification.md) for representative responses. [Client generation](SdkGeneration.md) can use the declared schema but does not infer fields by running `toArray()`. API resources do not change authentication, authorization, CSRF, request validation, or routing behavior.
