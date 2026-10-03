# Model collections

Modern `App\Plugins\ModelQuery::all()` and `get()` return
`App\Plugins\ModelCollection`. `hasMany` and `belongsToMany`
resolve to the same type, including when empty. `first()`, `find()`,
`hasOne`, and `belongsTo` remain one model or `null`.

```php
$users = User::query()->sort('id')->get();
count($users);
$users[0];
$users->first();
$users->last();
$users->at(3); // null if missing
$users->isEmpty();
$emails = $users->pluck('email'); // plain array
$active = $users->filter(fn (User $user): bool => $user->active);
$names = $users->map(fn (User $user): string => $user->name); // plain array
$users->load(['posts.author', 'roles']); // Batched reload for the collection.
```

The collection is ordered and read-only. It accepts only modern Model
instances, preserves their identity, and does no database work when iterated
or serialized. `filter()` returns a new collection without changing the
original order or membership; unlike `User::query()->filter(...)`, it runs in
memory. Index assignment and removal throw. A missing numeric index returns
`null` through `at()` or array access.

`toArray()`, `jsonSerialize()`, and `toJson()` delegate to each Model's
serializer. Casts and hidden attributes are respected. Loaded relations and
pivot metadata remain absent because Model serialization currently omits
them. `toJson()` throws on invalid JSON values. `pluck()` is explicit
attribute access and can read a hidden attribute; it is not serialization.

`load()` accepts one relation path or a list, preserves the collection's
membership and object identity, and returns the same collection. It batches
each requested relation level across the collection rather than running one
query per Model. The path length is capped at eight segments. See
[Relationships](Relationships.md) for cache, polymorphic, and nested-loading
rules.

Raw `db('users')->all()` still returns associative row arrays. The genuine
legacy `App\Core\Model` keeps its historical array and insert-ID contracts.
Changing a legacy model's base class to the modern Model therefore changes
its result type intentionally.

## Application-facing Plugins import

Application code may import `App\Plugins\ModelCollection`, `App\Plugins\Page`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
