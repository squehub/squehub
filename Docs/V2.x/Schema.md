# SqueHub v2 schema foundation

This is the implemented Phase 6A schema API in the core repository. It supports
SQLite and MySQL. The public v1.x documentation is unchanged.

## Select a connection

`schema()` uses the configured default connection. `schema('reporting')`
uses a named connection from `database.connections`. The same service can be
obtained through `database()->schema('reporting')` or
`database()->connection('reporting')->schema()`. Constructing the schema
object does not open PDO; inspection and DDL do.

```php
use App\Plugins\Table;

schema()->create('members', function (Table $table): void {
    $table->id();
    $table->string('email', 190);
    $table->unique('email');
    $table->boolean('active')->default(true);
    $table->datetime('joined_at')->nullable();
});

schema()->hasTable('members');            // bool
schema()->hasColumn('members', 'email');   // bool
schema()->dropIfExists('members');
```

`drop('members')` fails if the table is absent.
`dropIfExists('members')` succeeds when it is absent. These calls change the
selected database immediately. Use migrations for application schema changes.

## Columns and constraints

The current column methods are `id()`, `integer(name)`,
`foreignId(name)`, `string(name, length = 255)`, `text(name)`,
`boolean(name)`, and `datetime(name)`. A column supports `nullable()`
and `default(value)`; `id()` is a generated primary key and cannot take
either modifier. Defaults are scalar literals of the column's compatible type
or `null` on a nullable column. SQL expressions and raw defaults are not part
of this API.

```php
schema()->create('posts', function (Table $table): void {
    $table->id();
    $table->foreignId('member_id');
    $table->string('slug', 120);
    $table->text('body');
    $table->integer('sort_order')->default(0);

    $table->unique(['member_id', 'slug']);
    $table->index(['member_id', 'sort_order']);
    $table->foreign('member_id', 'members', 'id')
        ->onDelete('cascade')
        ->onUpdate('restrict');
});
```

`primary('name')` or `primary(['first', 'second'])` defines a manual
primary key when `id()` is not used. `unique()` and `index()` accept
one column or an array, plus an optional explicit constraint name.
`foreign()` accepts one or more local columns, the referenced table, one or
more referenced columns, and an optional constraint name. The supported
foreign-key actions are `restrict`, `cascade`, and `set null`; a local
column using `set null` must be nullable. Local foreign-key columns must be
declared with `foreignId()` so they match the generated `id()` type on each
driver. Referenced tables and keys must exist when the DDL runs.

`text()` columns cannot be indexed through this portable API because MySQL
requires a driver-specific prefix length. String length is restricted to
1–255, but SQLite does not enforce the declared `VARCHAR` length. Defaults
with control characters or backslashes are rejected because their MySQL
meaning can depend on SQL mode.

Column, table, index, and constraint identifiers are validated and quoted.
Definitions are compiled and validated completely before the first SQL
statement is sent. Invalid columns, references to absent local columns,
duplicate names, incompatible defaults, and unsafe identifiers fail before
the table is created. SQLite indexes generated as separate statements are
executed in the same transaction when no caller already owns a transaction.

## Index and foreign-key inspection

Use the physical metadata APIs to check an existing table before a reviewed
schema change:

```php
$indexes = schema()->indexes('posts');
$hasSlugIndex = schema()->hasIndex('posts', 'posts_member_id_slug_unique');
$foreignKeys = schema()->foreignKeys('posts');
```

`indexes()` returns each physical index name, ordered column list, `unique`,
and `primary` flag. `foreignKeys()` returns local and referenced columns,
referenced table, and update/delete actions. The SQLite result has `name =
null` for foreign keys because `PRAGMA foreign_key_list` does not retain the
declared constraint name. A SQLite `UNIQUE` table constraint may appear as a
`sqlite_autoindex_*` physical index rather than under its declared name; use
its column list and uniqueness when inspecting it. Metadata reads use numeric
PDO result positions, so server-specific associative-key casing does not
change the result.

`dropIndex($table, $name)` removes an existing, explicitly named non-primary
index. It refuses SQLite autoindexes that enforce table constraints. On
MySQL, `dropForeignKey($table, $name)` removes an existing named foreign key;
SQLite rejects that call because changing a foreign key requires a reviewed
table rebuild. Neither method silently rewrites a table. These calls execute
immediately and belong in deliberate migrations, not ordinary requests.

## Driver differences and limits

MySQL uses InnoDB, `BIGINT UNSIGNED AUTO_INCREMENT` for `id()`, and
`BIGINT UNSIGNED` for `foreignId()`. SQLite uses `INTEGER PRIMARY KEY
AUTOINCREMENT` for `id()` and `INTEGER` for `foreignId()`. SQLite
foreign-key enforcement is enabled and checked when the framework opens its
PDO connection, before any transaction. SQLite's `datetime()` is stored as
text, and `boolean()` is an integer limited to 0 or 1 by a check constraint.
MySQL's `boolean()` is `TINYINT(1)` and does not enforce that two-value range.

The portable subset covers create, drop, table/column/index/foreign-key
inspection, the listed column types, indexes, foreign keys, and the narrow
index/FK removals above. General table alteration, renaming columns or
indexes, dropping individual columns, SQLite foreign-key removal, and
driver-specific SQL expressions remain outside the portable API. For an
unsupported operation, use a deliberate, reviewed raw migration and account
for each driver's DDL behavior. In particular, MySQL DDL can commit an
existing transaction implicitly; a migration must not promise SQLite-style
atomic schema rollback on MySQL.

Compilation tests cover both drivers without a server. SQLite execution was
verified on Windows with PHP 8.2.12 and `pdo_sqlite`. The guarded opt-in
MySQL 8.4.11/InnoDB suite later passed on a disposable WSL database; that
result covers the tested paths, not every server configuration. See
[Release readiness](ReleaseReadiness.md).

See [Migrations](Migrations.md) for tracked changes and
[Database](Database.md) for connections and transactions. SQLite documents
foreign-key initialization in its [foreign key pragma reference](https://www.sqlite.org/pragma.html#pragma_foreign_keys).

## Application-facing Plugins import

Application code may import `App\Plugins\DB`, `App\Plugins\Schema`, `App\Plugins\Table`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
