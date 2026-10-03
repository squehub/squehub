# SqueHub v2 database foundation

Optional [Observability](Observability.md) records bounded database operation counts and timings without SQL text, bindings, or row values. The [development profiler](Profiler.md) can show those safe timings in completed work; neither feature changes QueryBuilder or Model results.

Use [Seeders](Seeders.md) for deliberate data setup and [Factories](Factories.md)
for repeatable modern Model test data. `migrate` never runs Seeders
automatically. The v1 Dumper API does not run in v2; migrate those classes to
modern Seeders before relying on them.

The [code generators](Generators.md) can create a Model, a migration skeleton,
and a modern Seeder. Generation writes source files only: it does not connect
to the database, apply migrations, or run Seeders. `make:seeder` is the v2
data-setup generator.

`seed:status` reports matching root Seeder filenames without loading their
classes or reporting which ones have run. An
explicit [ReversibleSeeder](Seeders.md#explicit-rollback) may be invoked with
`seed:rollback Class`; no automatic data inverse or persisted Seeder history
exists.

This page describes the implemented data API in the core repository. The public v1.x documentation remains unchanged.

The [Validation](Validation.md) subsystem uses this database layer for `unique:table,column` and `exists:table,column`. Those rules query actual table rows, including soft-deleted rows; they do not apply Model scopes. Values are bound and identifiers validated. A passing unique check remains advisory until a database unique constraint enforces the write.

## Configuration and connections

`Config/Database.php` reads the central `Environment` object and places database settings in the Application's configuration repository. The default connection is `database.default` (`DB_CONNECTION`, default `mysql`). `database.connections.mysql` uses `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USER`, and `DB_PASSWORD`; it defaults to the `utf8mb4` charset. `database.connections.sqlite` uses `DB_SQLITE_DATABASE` (default `:memory:`). The flat `database.host`, `database.database`, `database.user`, and `database.password` keys remain for legacy configuration consumers.

`DatabaseServiceProvider` registers `DatabaseManager` and `ConnectionFactory` in the container. The manager stores named `Connection` objects. Creating an Application, obtaining the manager, selecting a connection, or constructing a table query does not open PDO. The first query, explicit `pdo()` call, or transaction opens the connection. The manager reuses it until `disconnect()` is called. MySQL and SQLite are supported; an unsupported driver raises `ConnectionException` instead of trying an unrelated DSN. PDO uses exception mode and associative fetches; MySQL also disables emulated prepares. Connection errors use a generic public message so credentials and DSNs are not exposed.

```php
$manager = database();
$default = $manager->connection();
$reporting = $manager->connection('reporting'); // If configured.
$pdo = $default->pdo(); // Opens the connection here.
$manager->disconnect();
```

`database()` resolves the Application-owned manager after bootstrap. `db('users')` creates a `QueryBuilder` for that table on the default connection. For another configured connection, use `database()->table('users', 'reporting')`. The helpers are convenient entry points; the manager can also be injected into application classes.

## Table queries

The SqueHub query vocabulary starts with a table, then uses `filter`, `sort`, and `all`:

```php
$users = db('users')
    ->filter('status', 'active')
    ->sort('created_at', 'desc')
    ->all();

$user = db('users')->filter('email', $email)->first();
```

`all()` returns associative row arrays. `first()` returns an associative row array or `null`. Queries also support `select([...])`, `distinct()`, `orFilter()`, `filterIn()`, `filterNotIn()`, `filterBetween()`, `filterNull()`, `filterNotNull()`, `join()`, `leftJoin()`, `group()`, `having()`, `limit()`, and `skip()`. A three-argument filter supplies an operator, for example `filter('age', '>=', 18)`. `exists()` returns a boolean; `count()` returns an integer. `sum()`, `avg()`, `min()`, and `max()` return the driver's numeric scalar or `null`. `toSql()` and `bindings()` expose the compiled select query for inspection without executing it.

```php
$ids = db('users')->filterIn('id', [1, 2, 3])->all();

$rows = db('users')
    ->join('profiles', 'users.id', '=', 'profiles.user_id')
    ->filterNotNull('profiles.display_name')
    ->select(['users.id', 'profiles.display_name'])
    ->all();
```

`insert([...])`, `update([...])`, and `delete()` return affected row counts. `insertId([...])` returns the PDO insert ID as a string. `update()` and `delete()` require a restrictive filter; use `allowAll()` deliberately for an unfiltered table-wide write.

```php
$id = db('users')->insertId(['name' => 'Valentine']);
$changed = db('users')->filter('id', $id)->update(['status' => 'active']);
$deleted = db('users')->filter('id', $id)->delete();
```

For more complex but still bound queries, group predicates explicitly and
compose a same-connection subquery:

```php
$users = db('users')
    ->filter('enabled', true)
    ->filterGroup(static function ($group): void {
        $group->filter('role', 'admin')->orFilter('role', 'editor');
    })
    ->all();

$orders = database()->table('orders');
$matching = database()->table('order_items')
    ->select(['order_items.id'])
    ->filterColumn('order_items.order_id', '=', 'orders.id');
$orders->filterExists($matching)->all();
```

`filterGroup()` and `orFilterGroup()` accept predicate-only builders; the
callback cannot add joins, sorting, windows, or another projection.
`filterColumn()` compares validated identifiers rather than binding a
right-hand literal. `filterExists()`, `filterNotExists()`, `filterInQuery()`,
`filterNotInQuery()`, and `filterSubquery($column, $operator, $subquery)`
accept a `QueryBuilder` from the **same Connection**. An `IN` subquery must
select exactly one column. A scalar subquery must select exactly one column,
set `limit(1)`, and avoid skip or row locks. None of these APIs treats
untrusted input as raw SQL. ModelQuery forwards the same read-filter helpers.

`insertMany($rows)` validates a nonempty, homogeneous list of named row
arrays and sends bounded parameter batches. If more than one statement is
needed, it wraps them in the existing Connection transaction so a failed
later batch rolls back the earlier batches; nested caller transactions use
savepoints. `upsert($values, $conflictColumns, $updateColumns)` writes one
row with backend-native conflict handling:

```php
db('counters')->upsert(
    ['account_id' => $accountId, 'kind' => 'login', 'count' => 1],
    ['account_id', 'kind'],
    ['count']
);
```

The conflict columns must correspond to a real unique constraint. SQLite
targets the specified columns. MySQL's `ON DUPLICATE KEY UPDATE` reacts to
**any** violated unique key in the table, so the supplied conflict list does
not restrict which MySQL uniqueness violation triggers the update. Return
values are backend affected-row counts, which may differ for inserts,
updates, and no-op updates. Bulk table operations do not hydrate Models,
fire Model lifecycle events, or apply Model casts/timestamps. Use
`QueryBuilder::chunk()` or `ModelQuery::chunk()` for bounded forward keyset
processing; see [Pagination](Pagination.md).

Query values use prepared bindings. Table, column, alias, sort, and join identifiers are validated and quoted because PDO cannot bind identifiers. Comparison operators and sort directions are restricted to supported values. Ordinary strings are never interpreted as SQL expressions. For intentional raw SQL, `database()->raw($sql, $bindings)` returns a `PDOStatement`; callers must supply trusted SQL structure and bind untrusted values.

## Transactions

`database()->transaction()` begins on the default connection, commits when the callback returns, and rolls back and rethrows when it fails. For a named connection, pass its name as the second argument and run its queries through `database()->table('users', 'reporting')` or the callback's `Connection` argument. Manual `begin()`, `commit()`, and `rollback()` are also available on the manager or a `Connection`.

```php
database()->transaction(function () use ($data): void {
    $userId = db('users')->insertId($data);
    db('audit_logs')->insert(['user_id' => $userId, 'event' => 'created']);
});
```

Nested transactions use framework-named savepoints. An inner rollback
discards only its savepoint's pending `afterCommit()` callbacks; the outer
transaction remains the caller's responsibility. If a driver ends a managed
transaction unexpectedly, the Connection clears its depth and callbacks
before reporting an error. A rollback failure preserves the original
callback error as the thrown failure and drops uncertain local state.

An outer transaction may retry a **driver-confirmed transient conflict**
using `attempts` from 1 to 5. Arbitrary application exceptions are never
retried, and an inner savepoint cannot request multiple attempts. Retries
require a confirmed rollback; if code or the driver ends the PDO transaction
outside the manager, its outcome is uncertain and the callback is not replayed.
A retried callback can run more than once, so keep external side effects outside it or
schedule them through the existing after-commit boundary:

```php
database()->transaction(
    fn () => db('counters')->filter('id', $id)->update(['count' => $next]),
    attempts: 3
);
```

For MySQL, choose one bounded `App\Plugins\TransactionIsolation` enum when
starting an outer transaction, for example `READ_COMMITTED`. SQLite rejects
an explicit MySQL-style isolation selection. Changing isolation inside a
nested transaction is rejected. `lockForUpdate()` and `lockShared()` are
available on table and Model queries **only within a managed MySQL
transaction**; SQLite rejects row-lock requests rather than silently
ignoring them:

```php
database()->transaction(static function (): void {
    $account = db('accounts')->filter('id', 42)->lockForUpdate()->first();
    // Perform the guarded update before this callback returns.
});
```

These locks are database-scoped, not distributed locks for Queue or other
process resources. The MySQL retry/lock/isolation path requires guarded live
qualification on a disposable server; SQLite tests alone cannot prove it.

## Models and v1 compatibility

New application models extend `App\Plugins\Model`, which inherits the modern `App\Database\Model` behavior. The default table uses snake case and common English plural endings, and the default primary key is `id`; set `$table` explicitly for irregular names. Override `$primaryKey` or `$connection` in a subclass when needed. `$guarded = ['*']` blocks mass assignment by default. Define `$fillable` or deliberately set `$guarded = []` before calling `create()` or `fill()`.

```php
use App\Plugins\Model;

final class User extends Model
{
    protected array $fillable = ['name', 'email'];
}

$users = User::query()->filter('status', 'active')->sort('id')->all();
$user = User::find($id);
$created = User::create(['name' => 'Valentine', 'email' => 'user@example.com']);
```

`User::query()->all()` and `get()` return a [ModelCollection](Collections.md) of `User` objects; `first()`/`find()` return a `User` or `null`. Hydration establishes a clean original snapshot without writing. Models expose current attributes through property access, `getAttribute()`, `setAttribute()`, and `attributes()`. `changed()`, `changes()`, and `original()` inspect pending values and the snapshot. `save()` inserts or updates the selected changed columns; `refresh()` reloads a persisted row. Explicit casts and optional UTC timestamps are available. `hasOne`, `hasMany`, `belongsTo`, `belongsToMany`, `hasOneThrough`, `hasManyThrough`, `morphTo`, `morphOne`, and `morphMany` support lazy access, `load()`, and batched nested `with()` eager loading. Multi-model relations return `ModelCollection`; singular relations return a Model or `null`. Correlated existence/count reads and through relations require one compatible connection; see [Relationships](Relationships.md). Both raw table and modern model queries support [offset and forward cursor pagination](Pagination.md), with array rows and model collections respectively. Array and JSON serialization omit hidden fields, including common sensitive names. See [Models](Models.md) for the model and [lifecycle observer](Models.md#model-lifecycle-and-observers) contracts and [Schema](Schema.md) for the schema API. Opt-in soft deletes and named scopes are available on modern ModelQuery; see [Soft Deletes](SoftDeletes.md) and [Scopes](Scopes.md).

Modern `ModelQuery` also offers nested `has()`/`doesntHave()`/`whereHas()`
paths, explicit `whereHasMorph()` constraints for registered morph aliases,
and direct-relation `withCount()` projections. These compile into bound,
correlated SQL rather than loading one relation per parent. Count values are
read-only query projections and never become Model write columns. Raw
`db('users')` queries do not inherit Model relation rules, scopes, or soft
deletes. See [Relationships](Relationships.md#filter-models-by-related-rows)
for query shape and cross-connection boundaries.

Existing `App\Core\Model` is a distinct compatibility API. Its static read methods still return arrays or `false`, and its `create()` retains its legacy insert-ID return contract. It now reaches the Application's database manager through `App\Core\Database`, so normal legacy and v2 calls share the configured connection. Migrate application models explicitly to `App\Plugins\Model` when object results and guarded mass assignment are desired; changing a legacy model's base class changes its return contracts.

## Schema and migrations

The Phase 6A [Schema](Schema.md) service provides portable create, drop,
inspection, supported column types, indexes, and foreign keys on a selected
connection. [Migrations](Migrations.md) documents tracked changes, the
optional typed `Schema` migration argument, CLI commands, history
compatibility, and different SQLite and MySQL failure behavior.

The optional persistent [Queue](Queue.md) driver uses the existing configured database connection, prepared statements, and explicit migrations for `queue_jobs`, `queue_failed_jobs`, and retryable failed payloads. The default synchronous Queue driver needs no tables. Queue registration never opens PDO or creates schema; a worker needs the tables installed before it starts. Immediate dispatch on the same connection inside a caller-owned transaction participates in that transaction, while a different connection can make a job visible before commit. `Queue::afterCommit()` follows the chosen `Connection` transaction lifecycle and waits for its outermost commit; nested transactions use savepoints and rollback discards their registered callbacks. The Queue insert occurs **after** business commit, so failure cannot undo committed business changes. Queue reservation uses a guarded claim and token-fenced settlement rather than Model queries.

The [Scheduler](Scheduler.md) uses the existing connection for `schedule_runs` and `schedule_locks` when its database store is selected. Its separate migration creates a unique task-key/UTC-minute occurrence marker and an expiring, token-fenced overlap lock. Definitions and `schedule:list` do not need a connection; due `schedule:run` tasks need installed tables. No task payload or callback argument is stored in these tables. The optional array store is limited to one process.

Raw `db('users')` table queries do not apply Model scopes or soft-delete filtering. `App\Core\Model` keeps its legacy contract.

[Account security](AccountSecurity.md) uses the same DatabaseManager and QueryBuilder for a dedicated hash-only token table. Its migration example uses portable Schema indexes; no token table is created during application boot.

During a Kernel request, [diagnostics](Diagnostics.md) counts attempted statements through `Connection::raw()` and modern `QueryBuilder`, with total and per-connection execution time. Set `diagnostics.slow_query_ms` in `Config/Diagnostics.php` to a finite, non-negative number of milliseconds to count attempts at or above that duration; `null` (the default) disables slow-query counting. The snapshot reports only aggregate `slow_queries` and the configured threshold, never SQL or bindings. Direct PDO use and migration callbacks that receive PDO are outside this metric; the count is intentionally not a complete database trace.

## Application-facing Plugins import

Application code may import `App\Plugins\DB`, `App\Plugins\Model`, `App\Plugins\ModelQuery`, `App\Plugins\QueryBuilder`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
