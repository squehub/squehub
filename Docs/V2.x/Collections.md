# Model collections

Modern `App\Plugins\ModelQuery::all()` and `get()` return an ordered
`App\Plugins\ModelCollection`, including when no row matches. `hasMany`,
`hasManyThrough`, `morphMany`, and `belongsToMany` resolve to the same type,
including when empty. `first()`, `find()`, `hasOne`, `hasOneThrough`,
`belongsTo`, and `morphTo` remain one Model or `null`.

## Read an already loaded result

```php
$users = User::query()->sort('id')->get();
count($users);
$users[0];
$users->first();
$users->last();
$users->at(2); // Zero-based third item; null if missing.
$users->isEmpty();
$users->isNotEmpty();
$emails = $users->pluck('email'); // plain array
$active = $users->filter(fn (User $user): bool => $user->active);
$names = $users->map(fn (User $user): string => $user->name); // plain array

foreach ($users as $user) {
    echo $user->name;
}
```

The collection is ordered and read-only. It accepts only modern Model
instances, preserves their identity, and does no database work of its own
when iterated or serialized. Its membership is fixed, while each contained
Model is still an ordinary mutable object. Index assignment and removal throw
`LogicException`. A missing numeric index returns `null` through `at()` or
array access.

## Transform items in memory

`filter()` returns a new collection with matching **same Model objects** in
the original order, leaving the original membership untouched. Unlike
`User::query()->filter('active', true)->get()`, it runs in PHP after rows
have been fetched. `map()` and `pluck()` return plain arrays, not another
ModelCollection. `pluck()` calls `getAttribute()`, so it returns cast or
accessor values and can read a hidden attribute. Treat the resulting array
accordingly if it holds private fields. User callbacks can perform their
own work, but collection transforms do not themselves issue SQL.

## Load relations on existing Models

`load()` accepts one relation path or a list, preserves the collection's
membership and object identity, and returns the **same collection**. It
refreshes the named relation caches and batches each requested relation
level across the collection rather than running one query per Model.

```php
$users = User::query()->sort('id')->get();
$users->load(['posts.author', 'roles']);

$posts = $users->first()?->posts; // Already loaded for that user.
```

Unlike iteration or `filter()`, `load()` executes relation queries. The
path length is capped at eight valid method-name segments. Use
`User::query()->with(['posts.author', 'roles'])->get()` for initial eager
loading, or `$user->load('posts')` for one Model. See
[Relationships](Relationships.md) for cache, polymorphic, and nested-loading
rules.

## Serialize and paginate

`toArray()`, `jsonSerialize()`, and `toJson()` delegate to each Model's
serializer. Casts and hidden attributes are respected. Loaded relations and
pivot metadata remain absent because Model serialization currently omits
them. `toJson()` throws `JsonException` on values JSON cannot encode.

```php
$rows = $users->toArray(); // Plain list of serialized Model attributes.
$json = $users->toJson();

$page = User::query()->sort('id')->page(1, 20);
$items = $page->items(); // ModelCollection even on an empty page.
$total = $page->total(); // Matching rows before page bounds.
```

`Page::items()` is a ModelCollection for modern Model queries and a plain
array of associative rows for raw table queries. Page serialization includes
the serialized model attributes under `items` plus pagination metadata.
See [Pagination](Pagination.md) for offset and cursor semantics.

Raw `db('users')->all()` still returns associative row arrays. The genuine
legacy `App\Core\Model` keeps its historical array and insert-ID contracts.
Changing a legacy model's base class to the modern Model therefore changes
its result type intentionally.

## Application-facing Plugins import

Application code may import `App\Plugins\ModelCollection` and
`App\Plugins\Page`. They are aliases of the canonical result classes. See
[Plugins](Plugins.md) for the complete mapping and compatibility rules.
