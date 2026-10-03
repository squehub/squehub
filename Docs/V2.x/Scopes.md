# Named model scopes

Modern models declare reusable query conditions explicitly:

```php
use App\Plugins\Model;
use App\Plugins\ModelQuery;

final class User extends Model
{
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
active `ModelQuery` first and must return that same query instance. An unknown,
invalid, or non-callable scope, or a wrong return value, raises `ScopeException`.
An exception thrown by the callable is preserved as the cause. Error text names
the model and scope without printing argument values.

Applying a scope changes query state only; SQL runs when the query is consumed.
Repeated calls apply the scope again. Separate queries have separate state.
Related model queries also support `scope()`:

```php
$published = $user->posts()->scope('published')->get();
```

Scopes can deliberately call `withDeleted()` or `onlyDeleted()` on the query.
Eager loading does not apply named scopes automatically. Raw `db()` queries and
legacy `App\Core\Model` have no scopes. Scopes are query reuse, not an
authorization or generic global-scope system.

## Application-facing Plugins import

Application code may import `App\Plugins\Model`, `App\Plugins\ModelQuery`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
