# SqueHub v2 models

`php squehub make:model User` creates a minimal modern Model under
`Project/Models/`. It does not infer attributes, mass-assignment policy,
relationships, or a database table. See [Generators](Generators.md) before
editing and using the generated class.

[Factories](Factories.md) construct and persist modern Models through their
normal fillable, cast, timestamp, connection, and soft-delete contracts.

Modern models support explicit [named scopes](Scopes.md) and optional [soft
deletes](SoftDeletes.md). These policies belong to `App\Plugins\Model`, which
inherits the canonical `App\Database\Model`; neither the legacy model nor raw
table queries apply them.

This page describes the modern application-facing `App\Plugins\Model`. It is
separate from the `App\Core\Model` compatibility API. The public v1.x
documentation and separate documentation project are unchanged.

## Choosing the model API

New application models extend `App\Plugins\Model`, which inherits the modern `App\Database\Model` behavior. Its `query()->all()` and
`get()` return a [ModelCollection](Collections.md); `query()->first()` and
`find()` return a model or `null`.
`create()` returns the inserted model. The default table name is inferred from
the class name using snake case and common plural endings. Set `$table` for an
irregular name. `$primaryKey` defaults to `id`, and `$connection` defaults to
the configured database connection.

```php
use App\Plugins\Model;

final class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'active', 'preferences'];
    protected array $casts = [
        'active' => 'boolean',
        'preferences' => 'array',
    ];
    protected bool $timestamps = true;
}

$user = User::query()->filter('active', 1)->first();
$users = User::query()->sort('name')->all();
```

`App\Core\Model` remains the v1 compatibility base class. Its static reads
return associative arrays or `false`, and `create()` returns a string insert ID
or `false`. Changing a legacy model's base class changes those return
contracts, so migrate application models deliberately.

## Models in route actions

The path-first Router can resolve a required parameter to a modern Model when
the route **explicitly** declares a binding. A controller type hint by itself
does not query the database. The default lookup uses the Model's configured
primary key and connection, including a string primary key when declared.

```php
use App\Plugins\Route;
use Project\Controllers\UserController;
use Project\Models\User;

Route::path('/users/{user}')
    ->get([UserController::class, 'show'])
    ->bind('user', User::class);
```

In `UserController::show(User $user)`, `$user` is the resolved Model. For a
route-local slug lookup, use `->bind('user', User::class, key: 'slug')` and
ensure the database makes slugs unique if each URL must identify one row.
The route key is validated as a database identifier; the incoming path value
is passed as a prepared query value. `Request::route('user')` still exposes
the original decoded string to middleware and controller code.

The lookup happens after route middleware permits the request and before the
controller runs. A missing or normally excluded soft-deleted row produces the
existing browser/API 404 response; it does not pass `null` to the controller.
Binding only locates the row. It does not authorize access to it, infer a
parent/child relation, or include deleted records. A registration, route list,
Package inspection, or static API contract export does not perform the lookup.
The binding belongs to its Application's route definition, and the Model
instance is not retained for another request. See [Routing](Routing.md#bind-a-route-parameter-to-a-model)
for multiple parameters, middleware ordering, and limitations.

## Attributes and pending changes

`new User([...])`, `fill([...])`, `setAttribute($name, $value)`, and property
assignment set current attributes. `$guarded = ['*']` blocks mass assignment
by default. Declare `$fillable`, or explicitly use `$guarded = []`, before
using the constructor with attributes, `fill()`, or `create()`. Direct property
assignment is explicit and does not use the mass-assignment allowlist.
Hydration from a trusted database row bypasses the allowlist; a cast
declaration does not make an attribute fillable.

Hydration creates a clean original snapshot and marks the instance as an
existing row. A new model remains unsaved until insertion succeeds. Use
`changed()` for any pending change, `changed('name')` for one attribute,
`changes()` for an associative array of pending new values, and
`original('name')` for the snapshot value. `original()` without an argument
returns the snapshot array for compatibility with earlier v2 code.
`isDirty()` and `isDirty('name')` remain aliases for pending-change checks.
These values are not included automatically in serialized output.

An absent field differs from an explicitly assigned `null`. A loaded field
that is later assigned its original value is clean again. Casted attributes
compare by their normalized meaning: for example, a declared integer loaded
from database text `"10"` and then assigned integer `10` is unchanged.
Uncast attributes keep strict PHP comparison. A partial selection tracks only
the selected fields and explicit assignments; an unselected column is never
treated as a `null` update. `getAttribute()` returns `null` for both an absent
attribute and a present `null`; use `array_key_exists()` on `attributes()` or
`original()` when that distinction matters.

```php
$user = User::find($id);
$user->name = 'Updated';

if ($user->changed('name')) {
    $pending = $user->changes(); // ['name' => 'Updated']
    $previous = $user->original('name');
}
```

The original snapshot is local to each instance. Reading a casted field,
calling `toArray()`, or serializing the model does not create a change.

### Explicit accessors and mutators

Declare attribute transforms in the Model class. Each map uses an exact
attribute name; SqueHub does not infer method names or look for `getXAttribute`
and `setXAttribute` methods. A callback receives the value and the current
Model. The second argument is useful for computed reads; omit it when the
transform needs only the value.

```php
use App\Plugins\Model;

final class Member extends Model
{
    protected array $fillable = ['name', 'email'];

    protected static function accessors(): array
    {
        return [
            'name' => static fn (mixed $value): string => strtoupper((string) $value),
            'label' => static fn (mixed $value, Model $model): string =>
                'Member ' . $model->rawAttribute('name'),
        ];
    }

    protected static function mutators(): array
    {
        return [
            'name' => static fn (mixed $value): string => trim((string) $value),
        ];
    }
}

$member = Member::create(['name' => ' Ada ', 'email' => 'ada@example.test']);
$member->name;                     // "ADA": accessor presentation
$member->rawAttribute('name');     // "Ada": canonical current value
$member->getAttribute('label');    // "Member Ada": computed read
$member->original('name');         // "Ada": last confirmed value
```

Mutators run once on explicit constructor, `fill()`, `setAttribute()`, or
property assignments, before the declared cast normalizes the value. They do
not run while hydrating, refreshing, generating timestamps, saving, or writing
framework-managed deletion state. `getAttribute()` and property reads apply
accessors after cast normalization, without changing `attributes()`,
`original()`, `changes()`, or SQL writes. `rawAttribute()` and `attributes()`
expose canonical values without accessors. Use raw reads inside a computed
accessor to avoid reading that same accessor recursively. Recursive accessors
and mutators fail clearly.

`toArray()` and JSON apply declared accessors to present attributes. An
accessor therefore owns the serialized presentation for its attribute and
should return a JSON-compatible value. A computed accessor for an absent
column, such as `label` above, is readable but is not automatically appended
to arrays or JSON. Hidden and sensitive-attribute filtering still applies.
Invalid transform names or non-callable declarations fail when the Model is
constructed. Exceptions thrown by application callbacks propagate normally.

### Most recent saved changes

`changed()` and `changes()` describe **current unsaved state**. The separate
`wasChanged()` and `savedChanges()` describe values written by the most recent
confirmed insert, update, soft delete, restore, or trusted single-attribute
write on that instance. `wasChanged('name')` checks one written attribute.
These values remain available after a later unsaved assignment or a no-op
`save()`. Failed writes do not replace the last confirmed write history.
Hydrated Models begin with no saved-change history; a successful `refresh()`
or physical delete clears it. The saved-change map contains canonical values,
including generated keys or managed timestamps when those were written, so
keep it out of public responses unless the application filters it.

```php
$member->name = ' Grace ';
$member->changed('name');        // true: pending assignment
$member->save();
$member->changed('name');        // false: current state is synchronized
$member->wasChanged('name');     // true: the last write changed name
$member->savedChanges()['name']; // "Grace": canonical saved value
```

## Save, delete, and refresh

`save()` returns `true` after a successful insert or update, and an unchanged
existing model returns `true` without issuing an UPDATE. A new model must have
at least one attribute before saving. An update writes only pending columns.
If the driver reports zero affected rows, the model checks whether its row
still exists. `save()` returns `false` if that row is missing; the instance
retains its pending changes and existing-row identity. Database errors throw
an exception and leave the user's unsaved values available. If the INSERT
completed but its affected-row result or generated ID could not be confirmed,
the result is marked uncertain and the same instance cannot be retried; check
the database and use a new instance after reconciliation.

A query-hydrated or successfully saved instance retains its original primary
key, table, and connection for persistence. Manually calling `hydrate($row)`
without a connection uses the current configured connection when the model
first writes or refreshes. Changing the primary key of a persisted model is
rejected. Saving or deleting a partially loaded model without its original key
is rejected. A partially loaded model is never inserted simply because its
key was not selected.

`delete()` returns `true` when a row is deleted and `false` for an unsaved,
already deleted, or missing row. A missing-row result does not change the
object's in-memory state. A successful delete leaves the object marked
deleted; calling `save()` on it cannot silently insert it again.

`refresh()` returns the same instance after reloading a previously existing
row by its original primary key. On success it replaces the current attributes
and snapshot with database values, including database defaults, and discards
unsaved edits. If the row is missing, the query fails, or a stored value fails
its declared cast, it throws and retains the prior in-memory state.
It performs a query only when called; attribute reads do not automatically
query the database.

If a model is saved inside a caller-owned transaction, `save()` does not
commit that transaction. A successful save synchronizes the object's local
snapshot to the transaction-local row. A later outer rollback can leave that
object stale. Refresh a previously existing model after rollback; discard an
object whose insert was rolled back. Its assigned ID alone does not prove the
row still exists.

## Relationships

Models can define `hasOne`, `hasMany`, `belongsTo`, `belongsToMany`,
`hasOneThrough`, `hasManyThrough`, `morphTo`, `morphOne`, and `morphMany`
methods. The
related class must extend `App\Plugins\Model` or its canonical base. The normal default for
`hasOne` and `hasMany` is the parent's short class name in snake case plus
`_id` on the related table; the local key is the parent's primary key. The
default for `belongsTo` is the related class name plus `_id` on the current
model; the owner key is the related model's primary key. Specify keys when
these conventions do not fit. Related models use their own table and
configured connection.

```php
class User extends Model
{
    public function profile() { return $this->hasOne(Profile::class); }
    public function posts() { return $this->hasMany(Post::class); }
    public function ownedPosts() { return $this->hasMany(Post::class, 'owner_uuid', 'uuid'); }
}

class Post extends Model
{
    public function author() { return $this->belongsTo(User::class, 'user_id'); }
    public function owner() { return $this->belongsTo(User::class, 'owner_uuid', 'uuid'); }
}

$published = $user->posts()->filter('status', 'published')->get();
$profile = $user->profile()->first();
$author = $post->author()->first();
```

Relation objects support `filter()` (and `where()`), `sort()`, `limit()`, and
`skip()` before execution. `hasMany()->get()` and `belongsToMany()->get()` return
a `ModelCollection`, including when empty. `hasOne()->get()` and `belongsTo()->get()` return one model or
`null`; `first()` returns one model or `null` for every relation. Calling a relation
method executes only when `get()` or `first()` is called and does not cache its
result. Reading `$user->posts` or `$post->author` resolves and caches the
relation on first access. An actual attribute of the same name takes
precedence over dynamic relation access; `getRelation('posts')` reads a cached
value directly without querying.

```php
use App\Plugins\ModelCollection;

$users = User::query()->get(); // ModelCollection
$user->load(['profile', 'posts']); // Returns the same model.
$user->relationLoaded('posts');   // true, even if the loaded value is null.
$user->setRelation('posts', new ModelCollection());
$user->unsetRelation('posts');    // Next property access queries again.

$users = User::query()->with(['profile', 'posts'])->get();
```

`with()` also works with `all()` and `first()`. It batches each relation by
collecting available keys, reading related rows with bound `IN` queries, and
matching them in memory. Queries are chunked at 500 keys; an empty parent set
or only null/missing keys issues no related query. Eager loading does not mark
models dirty. Loaded relations are stored separately from attributes, casts,
snapshots, writes, and `toArray()`/JSON output. `load()` explicitly reloads
named relations even if they were cached. A successful `refresh()` clears all
loaded relations after it replaces attributes; a failed refresh retains them.

A partial model resolves a relation to an empty `ModelCollection` or `null` without querying if its
required local or foreign key was not selected or is `null`. Include the key
in `select([...])` when eager or lazy loading is needed; the framework does
not alter the selected columns. A related model can use a different named
connection for ordinary lazy reads. Correlated existence/count queries,
many-to-many, and through relations require one shared `Connection`.
Without its own `$connection`, it uses the originating
Application's default. Query-hydrated parents retain their own connection
and manager for later `save()`, `refresh()`, and relationship reads.

Many-to-many relations use an explicit pivot table, isolate pivot metadata from
ordinary attributes, and support `attach()`, `detach()`, `detachAll()`, and
`sync()`. See [Relationships](Relationships.md) for their keys, connection and
transaction policy, and cache behavior.

Use `User::query()->has('posts')`, `doesntHave('posts')`, or
`whereHas('posts', $constraint)` to filter parents with a correlated SQL
relation predicate. Dotted paths such as `has('posts.comments.author')`
remain one root SQL read; the callback for `whereHas()` applies only to the
terminal relation. These methods remain lazy and respect each related
Model's soft-delete policy. A `morphTo` path may be terminal, while
`whereHasMorph()` selects explicit registered aliases with a callback for
each selected target schema. See [relation existence filters](Relationships.md#filter-models-by-related-rows)
for supported relation types and callback limits.

Nested paths such as `with('posts.comments.author')` are supported up to
eight segments. Eager loading applies relation filters from the definition
shared across the parent batch. A relation-level `limit()` or `skip()`
applies to the batched SQL query, not separately to each parent. See
[Relationships](Relationships.md) for explicit morph aliases, cache
semantics, and bounded nested batching.

Model queries support [offset and forward cursor pagination](Pagination.md).
`page($page, $perPage)` computes a total; `cursorPage($limit, $after)`
returns a continuation without a count. Model pages contain a
`ModelCollection`; the same methods on a raw table query contain associative
row arrays.

## Relation-count projections

Use `withCount()` when the result needs a relation count without loading the
relation or issuing a query per Model:

```php
use App\Plugins\ModelQuery;

$posts = Post::query()->withCount(['comments', 'tags'])->get();
echo $posts->first()->comments_count;

$approved = Post::query()->withCount([
    'comments' => static fn (ModelQuery $comments): ModelQuery =>
        $comments->filter('approved', true),
])->page(1, 20);
```

The public field is `<relation>_count`, an integer read through property
access or `getAttribute()`. It appears in `toArray()`/JSON unless the Model
hides it. It is **query-derived**, not a table attribute: `attributes()`,
`original()`, `changed()`, `changes()`, `savedChanges()`, and `save()` do not
include it. Assigning a count alias is rejected. `refresh()` removes the
projection; run a new count query to recalculate. A count alias conflicting
with an existing attribute, cast, fillable name, or relation fails rather than
silently replacing application state. `withCount()` accepts direct relation
names only, including through relations; nested counts are not supported.
The count projection is omitted from a `page()` total query and composes with
`cursorPage()` ordering. [Relationships](Relationships.md#count-related-rows-without-loading-them)
explains soft deletes, pivot filters, and constrained counts.

## Declared casts

Declare casts with protected `$casts`, keyed by attribute name. Undeclared
attributes retain their database and assigned PHP values. Cast errors name
the model, attribute, and expected type without including the submitted value.
An unsupported cast declaration fails clearly. Casts do not perform request
validation or authorization. SQL `NULL` stays PHP `null` for every cast.

| Cast | Accepted non-null input and PHP value | Database representation |
| --- | --- | --- |
| `integer` | PHP integer or signed base-10 integer string within PHP's integer range; returns `int` | Integer |
| `float` | Finite PHP integer, float, or base-10 numeric string (including exponent); returns `float` | Float |
| `boolean` | `true`, `false`, integers `0`/`1`, strings `"0"`/`"1"`/`"true"`/`"false"` (words case insensitive); returns `bool` | Integer `0` or `1` |
| `string` | String, integer, finite float, or boolean (`true` becomes `"1"`, `false` becomes `"0"`); returns `string` | String |
| `array` | PHP array on assignment; returns PHP array | JSON text |
| `date` | Strict `Y-m-d` string or `DateTimeInterface`; returns `DateTimeImmutable` | `Y-m-d` |
| `datetime` | Strict `Y-m-d H:i:s` UTC string, ISO-8601 second-precision string ending in `Z` or a valid numeric offset, or `DateTimeInterface`; returns `DateTimeImmutable` | UTC `Y-m-d H:i:s` |

The integer cast rejects fractional forms and overflow without converting
through float. The float cast rejects NaN and infinity; it is not exact decimal
arithmetic. Boolean conversion follows the table above, so `"false"` is false.
Arrays, resources, and arbitrary objects are rejected where a scalar is
expected.

The `array` cast decodes database JSON into associative PHP arrays and uses
strict JSON errors. On assignment it accepts a PHP array, not JSON text, so
stored JSON is not double encoded. JSON `null` and SQL `NULL` both read as PHP
`null`. An empty JSON object (`{}`) and an empty JSON list (`[]`) both read as
`[]` with this array cast; reading alone does not rewrite either stored value.
JSON integers outside PHP's integer range decode as strings rather than losing
digits. Numeric strings remain strings on encoding. Invalid JSON, empty text,
recursive arrays, unsupported nested objects or resources, and encoding
failures raise a cast error. To edit nested data, read, modify, and assign the
array again:

```php
$preferences = $user->preferences;
$preferences['theme'] = 'dark';
$user->preferences = $preferences;
```

Direct indirect mutation through an overloaded property is not a tracked
operation. For JSON object/list shape that must round-trip exactly, use an
appropriate lower-level representation rather than the `array` cast.

`date` is a calendar day: a `DateTimeInterface` input keeps its local calendar
date rather than shifting days through UTC. Date-only output is `Y-m-d`.
`datetime` interprets timezone-less strings as UTC, normalizes explicit
offsets to UTC, stores whole seconds, and serializes as `Y-m-dTH:i:sZ`.
Date/time inputs are strict: invalid dates, overflow, malformed timezones,
natural-language strings, and trailing text are rejected. Mutable
`DateTimeInterface` inputs become independent immutable values. Existing
naive non-UTC database timestamps require an explicit data migration or
application compatibility decision before declaring them as UTC datetimes.
No process-wide timezone change is made.

## Automatic timestamps

Automatic timestamps are disabled by default. Models that use timestamp
columns opt in with `protected bool $timestamps = true`. Default column names
are `created_at` and `updated_at`. A model may set
`protected ?string $createdAtColumn` or
`protected ?string $updatedAtColumn` to another valid column name, or `null`
to disable that one column. The columns must exist in the table; enabling
timestamps does not create or inspect schema automatically.

An insert fills missing managed timestamps from one UTC clock instant.
Explicit values are respected for imports and backfills. On a real update,
the framework fills `updated_at` unless the caller explicitly assigned that
column, even if the assigned value matches the snapshot. An unchanged save
leaves it alone. Failed writes do not
publish framework-generated timestamp values as saved; the caller's own
pending timestamp assignments remain. Stored timestamps use whole seconds
and are not concurrency or version tokens.

The ordinary clock is current UTC time. Tests can inject an
`App\Database\ModelClock` into `DatabaseManager`; model users do not need to
configure it. The clock is independent of PHP's process-wide timezone.

## Serialization and table-level writes

`toArray()` and JSON serialization expose casted values, with dates as
`Y-m-d` and datetimes as UTC ISO-8601 second-precision strings. They omit
original snapshots, pending changes, persistence state, connection objects,
and fields hidden by `$hidden` or the built-in sensitive-name rules (password,
secret, token, API key, private key, credential). `attributes()` is explicit
unfiltered attribute access; keep it out of public responses when it contains
sensitive data. Serialization does not query or write the database.

`db('users')` and `database()->table('users')` operate on associative rows and
do not instantiate models. Their bulk `update()` and `delete()` operations do
not apply model casts, automatic timestamps, or instance change tracking.
The `QueryBuilder` keeps prepared value bindings, identifier validation,
lazy connection opening, and `allowAll()` protection for unfiltered writes.
`ModelQuery` provides model-object reads, not a bulk model-write API.

See [Database](Database.md) for connection and query details and
[Schema](Schema.md) for creating tables and timestamp columns.

## Model lifecycle and observers

The database manager owns an explicit observer registry for one Application.
Register listeners during a service provider's `boot()` method; a loaded
Model retains the registry of the Application that loaded it. Nothing scans
Model directories or discovers observers automatically.

```php
use App\Plugins\Model;
use App\Plugins\ModelLifecycleEvent;

database()->modelObservers()->listen(
    User::class,
    'created',
    static function (Model $user, ModelLifecycleEvent $event): void {
        // The INSERT succeeded. An enclosing transaction might still roll back.
    }
);

database()->modelObservers()->observe(User::class, UserObserver::class);
```

An observer class can provide public methods named `saving`, `creating`,
`created`, `updating`, `updated`, `saved`, `deleting`, `deleted`,
`restoring`, or `restored`. It is resolved through the Application container
when a matching transition occurs. A method receives the Model and may
optionally receive the `ModelLifecycleEvent` as its second argument. The
event exposes `model`, `phase`, and `operation`; `operation` distinguishes
`create`, `update`, `delete`, `restore`, and `forceDelete`. Direct listeners
have the same arguments. Registration order is delivery order, with class
observers before direct listeners for each registered Model class. The same
event object is also emitted through the existing Application Events service,
so an explicit `ModelLifecycleEvent` listener can observe transitions across
models. No SQL, bound values, or Model attributes are added to diagnostics.

The create order is `saving → creating → INSERT → created → saved`.
The update order is `saving → updating → UPDATE → updated → saved`.
Soft and physical deletion use `deleting → SQL → deleted`; restore uses
`restoring → SQL → restored`. Force deletion uses the deletion phases with
`operation = forceDelete`. An unchanged existing `save()` issues no UPDATE
and emits no lifecycle event. Failed SQL emits no after event. An observer
exception before SQL prevents the write; an observer exception after SQL
propagates after the Model's local snapshot has been synchronized. Inside a
caller-owned transaction, ordinary after events mean **SQL succeeded**, not
**outer commit succeeded**. Use the Connection's existing `afterCommit()`
hook when an external side effect must wait for commit.

Observers may prepare ordinary writable fields before an insert or update;
the Model recalculates pending update fields after before observers run.
Return values never silently cancel a write. A same-instance recursive
`save()`/`delete()`/`restore()` call from an observer fails clearly. Direct
`persistAttributeOnly()` (used internally for credential rehashing) and raw
table writes do not emit per-Model lifecycle events. Hydration and refresh
are reads and do not emit write events. A later caller-owned rollback can
leave the in-memory Model stale; refresh a persisted Model when appropriate.

## Application-facing Plugins import

Application code may import `App\Plugins\Model`, `App\Plugins\ModelQuery`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
