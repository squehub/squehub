# Model factories

Application factories live in `Database/Factories/` with the
`Database\Factories` namespace. They target modern `App\Plugins\Model`
subclasses and use normal Model construction and persistence.

```php
namespace Database\Factories;

use App\Plugins\ModelFactory;
use Project\Models\User;

final class UserFactory extends ModelFactory
{
    protected string $model = User::class;

    protected function definition(): array
    {
        return [
            'name' => $this->fake()->name(),
            'email' => $this->fake()->email(),
            'status' => 'pending',
        ];
    }

    protected function states(): array
    {
        return [
            'verified' => ['status' => 'verified'],
            'admin' => ['role' => 'admin'],
        ];
    }
}
```

`UserFactory::new()` creates an independent builder. `make()` returns one
unsaved Model; `create()` returns one persisted Model. After `count(2)` or
more, either returns a read-only `ModelCollection` of distinct Models.
`count(0)` fails. Definitions and states are evaluated for each Model.

```php
$unsaved = UserFactory::new()->make();
$user = UserFactory::new()->create(['email' => 'known@example.test']);
$users = UserFactory::new()->seed(1234)->state('verified')->count(10)->create();
```

Attribute precedence is definition, then states in application order, then
explicit `make()` or `create()` overrides. Unknown states fail. States are
simple attribute arrays; callable states and factory callbacks are deferred.
Factory attributes pass through the Model's fillable, cast, timestamp,
connection, and soft-delete rules. A normal factory creates active Models;
it cannot mass-assign `deleted_at`. To make a deleted Model, create it and
call `delete()` deliberately.

There is no direct Faker dependency. `fake()` returns a small per-factory
generator with `name()`, `email()`, `username()`, `sentence()`, `integer()`,
`boolean()`, `uuid()`, `date()`, `datetime()`, and `element()`. `seed(1234)`
reproduces that factory's sequence without changing PHP's process RNG.
It is test data, not a cryptographic generator or global uniqueness service;
database constraints remain authoritative.

Factory `create()` does not wrap a batch in a transaction. If a later insert
fails, earlier successful rows remain unless the caller used an explicit
`database()->transaction(...)`. There are no factory relation builders or
hooks yet. Existing relationships remain straightforward:

```php
$user = UserFactory::new()->create();
$role = RoleFactory::new()->create();
$user->roles()->attach($role);
```

Factories do not target `App\Core\Model`. Generator commands such as
`make:factory` are deferred until the CLI generator architecture is ready.

## Application-facing Plugins import

Application code may import `App\Plugins\ModelFactory`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
