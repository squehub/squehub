# Named model scopes

Modern models declare reusable query conditions explicitly. Each scope is an
opt-in array entry, not a method name inferred by the query builder:

```php
use App\Plugins\Model;
use App\Plugins\ModelQuery;

final class User extends Model
{
    protected string $table = 'users';

    protected static function scopes(): array
    {
        return [
            'active' => static fn (ModelQuery $query): ModelQuery =>
                $query->filter('status', 'active'),
            'role' => static fn (ModelQuery $query, string $role): ModelQuery =>
                $query->filter('role', $role),
        ];
    }
}

$users = User::query()->scope('active')->scope('role', 'admin')->all();
$page = User::query()->scope('active')->sort('id')->page(1, 20);
```

Arguments after the scope name are forwarded exactly. The scope receives the
active `ModelQuery` first and must return that **same** query instance. Scope
names must start with a letter or underscore and then contain only letters,
digits, or underscores. Returning a new query, a Model, a collection, or
nothing is an error. Scopes can add ordinary filters, sorting, eager relation
names, relation counts, or soft-delete modes where the model permits them.

## Compose and consume a query

Applying a scope changes query state only; SQL runs when the query is consumed.
`all()`, `get()`, `first()`, `count()`, `exists()`, `page()`, and
`cursorPage()` consume the completed query. Repeated calls apply the scope
again; a fresh `User::query()` starts with fresh state. To reuse a prepared
starting point in different code paths, build a fresh query or clone it.

```php
$query = User::query()->scope('active')->scope('role', 'editor');
$total = $query->count();
$page = $query->sort('id')->page(1, 20);
// Both the page total and its items use the scoped filters.
```

The model's soft-delete policy stays in force. A scope can deliberately call
`withDeleted()` or `onlyDeleted()` on an opted-in model; the last explicit
deleted-record mode wins. `orFilter()` cannot bypass the required deletion
condition. See [Soft deletes](SoftDeletes.md) and [Pagination](Pagination.md).

## Scopes on related queries

Related model queries also support `scope()`:

```php
$published = $user->posts()->scope('published')->get();

$authors = User::query()->whereHas(
    'posts',
    static fn (ModelQuery $posts): ModelQuery => $posts->scope('published')
)->get();
```

Here `published` must be declared on the Post model. The first call reads
related posts; `whereHas()` constrains related SQL while selecting users.
A relation definition can itself apply a named scope. Eager loading through
`with('posts')` does not apply one automatically. See
[Relationships](Relationships.md) for the other relation filters.

## Errors and boundaries

An unknown, invalid, or non-callable scope, a missing or wrongly typed
argument, or a wrong return value raises `ScopeException`. An exception
thrown by the callable is preserved as the previous exception. Failed scope
application restores the query's prior state. Error text names the model
and scope without printing argument values; avoid logging a cause if it
could contain sensitive data.

Raw `db('users')` queries and legacy `App\Core\Model` reads have no scopes.
Scopes are query reuse, not authorization or automatic global scopes. Code
that must always enforce a tenant or security condition must make it part
of its own access path.

## Application-facing Plugins import

Application code may import `App\Plugins\Model` and
`App\Plugins\ModelQuery`. The application Model inherits the canonical
database Model; ModelQuery is an alias. See [Plugins](Plugins.md) for the
complete mapping and compatibility rules.
