# Model relationships

Related models with [soft deletes](SoftDeletes.md) are excluded by default from
lazy and nested eager loading. Relation queries use the related model's
`ModelQuery`, so they can call `scope('published')`, `withDeleted()`, or
`onlyDeleted()`. Eager loading uses the default deleted-record mode. Soft
deletion leaves pivot rows intact. A relation definition can deliberately
apply a named scope; none is applied automatically. Other live Model instances can retain stale
relation caches until reloaded; there is no global identity map.

Modern `App\Plugins\Model` supports `hasOne`, `hasMany`, `belongsTo`,
`belongsToMany`, `hasOneThrough`, `hasManyThrough`, `morphTo`, `morphOne`,
and `morphMany`. Legacy
`App\Core\Model` keeps its separate compatibility API. [Models](Models.md) covers the shared
relation and model-state contracts.

## Filter models by related rows

Use `has()` and `doesntHave()` on a Model query when a parent must have or
lack a related row. `whereHas()` adds filters or named scopes to the related
query without loading each relation into PHP:

```php
use App\Plugins\ModelQuery;

$authors = User::query()->has('posts')->all();
$withoutPosts = User::query()->doesntHave('posts')->all();
$publishedAuthors = User::query()
    ->whereHas('posts', static function (ModelQuery $posts): ModelQuery {
        return $posts->scope('published');
    })
    ->sort('id')
    ->page(1, 20);
```

These methods add correlated SQL `EXISTS` or `NOT EXISTS` predicates. Applying
them does not execute SQL; `all()`, `first()`, `count()`, `page()`, and
`cursorPage()` consume the completed query. `whereHas()` receives the related
`ModelQuery`; its callback may return that same query or return nothing after
mutating it. Filters, named scopes, and `withDeleted()` in the callback affect
the related query. The related model's normal soft-delete exclusion applies
unless the callback deliberately changes it. Parent soft-delete policy remains
independent. Relation-definition filters and many-to-many `pivotFilter()`
conditions remain in force. Multiple relation predicates and ordinary parent
filters compose.

Existence filters also support nested paths such as `comments.author`. Each
segment becomes a correlated `EXISTS`; `whereHas()` applies its callback to
the **terminal** relation. `doesntHave('comments.author')` means there is no
qualifying complete chain, even when comments exist. The query still sends
one root SQL statement rather than loading intermediate Models per parent.
Declared pivot filters, intermediate scopes, and normal soft-delete policies
remain effective. Invalid dotted paths, unknown segments, and cross-connection
paths fail before changing the caller's query. Traversing *beyond* a
heterogeneous `morphTo` target is not supported by existence filters.

Direct and nested existence checks support `hasOne`, `hasMany`, `belongsTo`,
`belongsToMany`, `hasOneThrough`, `hasManyThrough`, `morphOne`, and `morphMany`.
`morphTo` can be the terminal segment; it checks only aliases registered in
the originating Application's `MorphMap`. A generic `whereHas()` callback
cannot safely constrain different morph target schemas. Use the explicit
per-alias form below. All participating Models must use the same database
`Connection`. Raw `db('users')` table queries do not have these Model-aware
methods, though their lower-level bound subquery helpers remain available.

## Count related rows without loading them

`withCount()` adds one correlated SQL count projection per direct relation to
the parent SELECT. It does not query once per parent or load a relation into
memory:

```php
use App\Plugins\ModelQuery;

$posts = Post::query()
    ->withCount(['comments', 'tags'])
    ->sort('id')
    ->get();

echo $posts->first()->comments_count;

$approved = Post::query()->withCount([
    'comments' => static fn (ModelQuery $query): ModelQuery =>
        $query->filter('approved', true),
])->page(1, 20);
```

A single name, a list of names, or `name => callback` is accepted. The
callback receives the related `ModelQuery` and may return that same query or
nothing. Count projections use the related Model's default soft-delete policy
and declared relation/pivot filters; a callback may deliberately call
`withDeleted()` or a named scope. Through counts count final related rows
whose intermediate path qualifies. `withCount()` currently accepts **direct
relation names only**, not dotted count paths or custom count aliases.

`comments_count` is a read-only query projection, available through property
access, `getAttribute()`, and `toArray()` unless hidden. It is separate from
`attributes()`, `original()`, `changes()`, and `savedChanges()`. Assigning it
fails, and `save()` cannot write it as a table column. `refresh()` clears it;
query again with `withCount()` to recalculate. See [Models](Models.md#relation-count-projections).

## Read through an intermediate Model

Use `hasOneThrough()` for one final Model or `hasManyThrough()` for a
`ModelCollection`. The **final Model comes first**, then the intermediate:

```php
use App\Plugins\Model;

final class Country extends Model
{
    public function posts()
    {
        return $this->hasManyThrough(
            Post::class,
            User::class,
            'country_id', // User foreign key to Country.
            'user_id',    // Post foreign key to User.
            'id',         // Country local key.
            'id'          // User lookup key.
        );
    }
}

$countries = Country::query()->with('posts')->get();
$posts = $countries->first()->posts; // ModelCollection.
$countries->first()->load('posts'); // Explicit cache refresh.
$withPosts = Country::query()->has('posts')->get();
$counts = Country::query()->withCount('posts')->get();
```

The six arguments are `related`, `through`, `firstForeignKey`,
`secondForeignKey`, `localKey`, and `throughKey`; keys are optional after the
two Model classes. Defaults use each Model's primary key and conventional
foreign keys. `hasOneThrough()` uses the same order and returns a Model or
`null`. Both are **read-only**: no through-specific attach/create/save API is
provided. Lazy property reads cache their result; `load()` and nested eager
loading use the ordinary relation cache rules. For up to 500 distinct parent
keys, eager loading uses one parent SELECT, one intermediate SELECT, and one
final SELECT, independent of the number of parents. Repeated intermediate
lookup keys do not duplicate a final row for one parent.

The intermediate and final Models retain their own soft-delete policies;
relation modifiers such as `scope()` and `withDeleted()` target the final
Model. Intermediate, final, and parent Models must share one `Connection`.
Cross-connection through relations reject the read instead of querying an
unrelated database.

## Nested eager loading

Use a dot-separated relationship path for a bounded tree of queries:

```php
$posts = Post::query()->with(['comments.author', 'comments.reactions'])->get();
$posts->load('comments.author');
$posts->first()?->load('comments.author');
```

`with()` configures a Model query, while `load()` explicitly reloads a
Model or `ModelCollection`. The loader shares common path prefixes and
batches parent keys at each level; it does not issue one query per parent.
Paths contain at most eight valid relation-method segments. Invalid or
unknown relation methods fail clearly, including when a relation result is
empty. Repeating a path in one call does not repeat its work. An eager load
refreshes the named relation cache; other unrelated loaded relations remain.
Nested loading does not add callback-configured filters, per-parent limits,
or a global identity map. Cyclic paths must be explicitly finite; the eight
segment bound prevents unbounded recursion. Because relation property access
can lazy-load, prefer `with()` or `load()` when traversing many models.

## Explicit polymorphic types

Store a short application-controlled alias, never a PHP class name, in a
polymorphic type column. Register each alias during Application bootstrap:

```php
database()->morphMap()->define('post', Post::class);
database()->morphMap()->define('video', Video::class);

final class Comment extends Model
{
    public function commentable()
    {
        return $this->morphTo('commentable');
    }
}

final class Post extends Model
{
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
```

The example uses `commentable_type` (`post` or `video`) and
`commentable_id`. Define both columns in a migration, with indexes that suit
your lookup volume. `morphOne()` provides a singular child relationship;
`morphMany()` returns a `ModelCollection`; `morphTo()` resolves one target or
`null`. The methods accept explicit type/ID/local or owner keys when the
convention does not fit. Register aliases explicitly for every parent type
used by a morph child. Unknown stored aliases fail rather than becoming
autoloadable class names. A missing target row resolves to `null`, subject
to its Model's normal soft-delete policy.

`has('commentable')` and `doesntHave('commentable')` test whether a row has a
resolvable, active target among the registered aliases. A missing or
unregistered stored type is a non-match for these SQL existence checks;
ordinary lazy/eager `morphTo` resolution still rejects an unknown non-null
stored alias. With an empty map, `has()` matches nothing and `doesntHave()`
matches every parent. A MorphTo existence query accepts at most 32 registered
target aliases; a larger registry must use a narrower explicit selection.
The SQL binds alias values and never treats a stored type as a PHP class name
or SQL identifier.

For different target schemas, select registered aliases and constrain each
target separately:

```php
use App\Plugins\ModelQuery;

$comments = Comment::query()->whereHasMorph('commentable', [
    'post' => static fn (ModelQuery $posts): ModelQuery =>
        $posts->filter('published', true),
    'video' => static fn (ModelQuery $videos): ModelQuery =>
        $videos->filter('processed', true),
])->get();

// A selected alias can be left unconstrained.
$postComments = Comment::query()->whereHasMorph('commentable', [
    'post' => null,
])->get();
```

The selection must be nonempty and contain at most 32 aliases. Unknown or
malformed aliases, invalid callbacks, and targets on another connection fail
clearly. A callback may return its active target `ModelQuery` or nothing.
Related soft deletes and explicit scopes apply normally. `whereHasMorph()`
accepts one direct `morphTo` relation; it does not infer a shared filter
across types or traverse further relation paths from a heterogeneous target.
A nested ordinary path may **end** in `morphTo`, for example
`has('posts.comments.commentable')`.

Eager `morphTo` groups parents by registered target type and reads each type
in bounded key batches. The originating Application's manager and morph map
are retained for loaded Models, so a later Application selection cannot
reinterpret the stored alias. Polymorphic many-to-many and automatic
cross-type writes are not implemented. The alias is application schema data:
renaming it requires an explicit data migration.

## Many-to-many definitions

Declare a method on the parent model and name the pivot table explicitly:

```php
use App\Plugins\Model;

final class User extends Model
{
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->pivot(['assigned_by']);
    }
}
```

The default pivot keys are the parent and related **class** short names in
snake case plus `_id` (`user_id`, `role_id`). The parent and related lookup
keys default to each model's primary key. All six mapping arguments can be
given explicitly:

```php
return $this->belongsToMany(
    Permission::class,
    'account_permissions',
    'account_uuid',
    'permission_code',
    'uuid',
    'code'
)->pivot(['source']);
```

Pivot tables represent unique pairs. Add a composite unique constraint; the
relation also checks for duplicates it can see and rejects duplicate rows
during reads or synchronization. The database constraint closes the race
between a duplicate check and an insert. Define foreign keys where the schema
permits them. Pivot timestamps are ordinary explicit pivot values; they are
not managed automatically.

## Reading

```php
$roles = $user->roles; // Resolves once and caches the list.
$active = $user->roles()->filter('active', true)->sort('name')->get();
$manual = $user->roles()->pivotFilter('assigned_by', 'admin')->get();
$users = User::query()->with('roles')->get();
$user->load('roles'); // Reloads even if the property was cached.
```

`filter()`, `sort()`, `limit()`, and `skip()` target the related model query.
`pivotFilter()` targets pivot rows and affects reads only. The relation
method returns a query object; it does not populate the property cache.
`get()` returns a [ModelCollection](Collections.md), `first()` one model or `null`.
A persisted parent whose relation key was omitted by `select()` resolves to an empty collection without a
pivot query. Include that key when loading is needed.

The relation selects both pivot keys plus only columns named with `pivot()`.
Each related model exposes this edge's metadata through `$role->pivot()` as
an array. It returns `null` on an ordinary model. Pivot values do not enter
model attributes, dirty tracking, casts, timestamps, `save()`, `toArray()`, or
JSON. A shared related row is copied per edge so two parents can hold
different pivot metadata safely. The related model's `$hidden` rules still
apply to its ordinary attributes.

Eager loading reads parent keys, pivot rows in 500-key chunks, then unique
related keys in 500-key chunks. In the SQLite test fixture, 501 parents with
one shared role use four SELECT statements in total: one parent query, two
pivot queries, and one role query. Query limits and offsets on the related
query apply to each batch, not individually to each parent. Nested eager
loading works through `roles.users` and similar declared paths. Per-parent
limits are not supported.

## Pivot writes

```php
$user->roles()->attach($roleId, ['assigned_by' => 'admin']);
$user->roles()->attach([$roleA, $roleB]);
$user->roles()->detach($roleId);
$user->roles()->detach([1, 3]);
$user->roles()->detachAll();
$result = $user->roles()->sync([1, 3, 7]);
// ['attached' => 1, 'detached' => 2]
```

`attach()` and `detach()` return affected pivot row counts. `detach([])`
returns zero; only `detachAll()` removes every edge for the parent.
`sync()` accepts a list of integer or string IDs or persisted related model
objects, deduplicates equal IDs, retains unchanged edges and their metadata,
and returns `['attached' => int, 'detached' => int]`. Per-ID pivot-data maps
for `sync()` are not supported. `attach()` accepts one pivot-data array shared
by all new edges; duplicate existing edges are left untouched. Values are
bound parameters, column names are validated, and pivot data cannot override
either key. Only pivot rows are deleted by detach and sync.

Writes require a persisted parent with a loaded, unchanged relation key. A
related model argument must also be persisted with an unchanged relation key.
No model is saved automatically. Successful writes that change rows clear the
parent's loaded relation cache (all loaded relation names); failed writes
retain it. No-op calls leave the cache in place.

`attach()`, `detach()`, and `sync()` own a transaction for their multi-step
work only when no transaction is active. A failure rolls back that transaction
and rethrows the original error. Inside a caller-owned transaction, the
relation neither commits nor rolls back. If an operation fails after some
writes in that transaction, the caller must decide whether to roll back; the
cached relation may then be out of step with transaction-local rows. A later
outer rollback cannot restore a relation cache already loaded during the
transaction; use `load()` to refresh it. Ordinary transactional DML is
required for atomic standalone operations.

Parent, pivot, and related rows must resolve through the same `Connection`
instance for a many-to-many relation. A different configured connection, or
a hydrated parent pinned to a different manager, raises `RelationException`.
Other relation types retain their existing related-connection behavior.
Cross-connection many-to-many relations and distributed transactions are not
supported.

## Platform support

Relation queries and eager loading use the related Model's connection and metadata. Check [public status](Status.md) for supported database and platform combinations, and verify application-specific relation mappings against each database you deploy.

## Application-facing Plugins import

Application code may import `App\Plugins\Model`, `App\Plugins\ModelQuery`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
