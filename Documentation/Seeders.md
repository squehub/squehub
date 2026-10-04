# Seeders

Application Seeders live in `Database/Seeders/` with the
`Database\Seeders` namespace. The conventional root is
`Database/Seeders/DatabaseSeeder.php`. The shipped root is empty; add child
Seeders deliberately. Seeders run after schema exists and are never triggered
by `migrate`.

```php
namespace Database\Seeders;

use App\Plugins\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RoleSeeder::class, UserSeeder::class]);
    }
}
```

`call()` executes in declaration order and repeats a Seeder if requested
twice. Cycles fail clearly. The runner resolves each class through the
Application container, so constructor injection works. A missing, invalid,
or failing Seeder stops the chain; `SeederException` identifies the class
and preserves the original exception. CLI output does not dump data.

```bash
php squehub seed
php squehub seed UserSeeder
php squehub seed --force
php squehub seed UserSeeder --force
php squehub seed:status
```

`seed` uses the configured Application environment. In `production`, `prod`,
`staging`, `stage`, and `preprod`, it refuses to resolve or execute a Seeder
without `--force`. It does not prompt. Review the selected database before
running it. There is no Seeder history table or one-time guarantee.
`seed:status` lists matching root Seeder filenames and explicitly reports that
execution history is **not persisted**. It does not load those files or prove
their classes are valid. It is an availability inspection, not an
applied/pending state like `migrate:status`; do not use it to decide whether
a Seeder already ran. It does not run Seeder bodies.
Application authors control idempotency:

```php
if (Role::query()->filter('name', 'admin')->first() === null) {
    Role::create(['name' => 'admin']);
}
```

The runner does not start a transaction. Use `database()->transaction(...)`
when all effects are database writes on the selected connection and an atomic
run is appropriate. A transaction cannot roll back filesystem or API effects.
Seeders use normal Model scopes and soft-delete filtering; inspect deleted
reference rows explicitly with `withDeleted()` when needed.

## Explicit rollback

Rollback is available only when a named Seeder explicitly implements
`App\Plugins\ReversibleSeeder` and defines `rollback(): void`:

```php
namespace Database\Seeders;

use App\Plugins\ReversibleSeeder;
use App\Plugins\Seeder;

final class RoleSeeder extends Seeder implements ReversibleSeeder
{
    public function run(): void
    {
        // Insert known role records deliberately.
    }

    public function rollback(): void
    {
        // Remove only records this Seeder owns, after checking dependencies.
    }
}
```

```bash
php squehub seed:rollback RoleSeeder
php squehub seed:rollback RoleSeeder --force
```

The command requires an explicit class; it does not infer the last run or
reverse a whole Seeder tree. Production-like environments require `--force`.
There is no run history, automatic inverse, or implicit transaction. Review
the selected database and make rollback code safe for its real effects. A
`database()->transaction(...)` call can protect suitable database writes, but
cannot reverse filesystem or external-service work.

For new data setup, extend `Seeder` in the canonical Seeders directory.
`php squehub make:seeder UserSeeder` creates a modern, empty Seeder there;
`--preview` shows the target without writing. Complete its `run()` method
before `php squehub seed UserSeeder`. The generator does not implement
`ReversibleSeeder` or invent rollback code, because reversal is specific to
the data it writes. Package-local Seeder generation is not
supported because Package Seeder discovery is not a current runner contract.
See [Generators](Generators.md).

## Migrating v1 Dumper code

The v1 Dumper base and its `make:dumper`, `dump:run`, and `dump:rollback`
commands are retired from v2. Move application-owned setup into modern
`Database\Seeders` classes and call them through `seed`. Port a historical
rollback only if it has a safe, explicit inverse: implement
`ReversibleSeeder` and use `seed:rollback Class`. A v1 `deleteBy()` call is not
automatically translated to a modern Model operation. See [Upgrade from
v1.x](UpgradeFromV1.md).

## Application-facing Plugins import

Application code may import `App\Plugins\Seeder` and `App\Plugins\ReversibleSeeder`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
