# SqueHub v2 migrations

The migrator retains the existing `Database/Migrations` directory,
filename-derived migration classes, PDO method argument, and `migrations`
history table. The public v1.x documentation is unchanged.

`php squehub make:migration create_posts_table` creates a dated migration
skeleton with matching `Schema::create()` and `dropIfExists()` calls. Other
valid names start with empty `up()` and `down()` methods. Generation is
filesystem-only and does not run `migrate`; see [Generators](Generators.md).

## Write a migration

Files are discovered in deterministic filename order. A date prefix is
removed to derive the class name:
`2026_09_01_create_posts.php` contains `CreatePosts`, either globally or
in the `Database\Migrations` namespace. The established first-letter
capitalization variants are accepted, but two files that would identify the
same migration or class are rejected before any migration runs.

```php
<?php

use App\Plugins\Schema;
use App\Plugins\Table;

class CreatePosts
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('posts', function (Table $table): void {
            $table->id();
            $table->string('title', 190);
            $table->text('body');
            $table->index('title');
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->drop('posts');
    }
}
```

The typed second `Schema` parameter is optional and uses the migrator's
selected connection. Existing `up(PDO $pdo)` and `down(PDO $pdo)`
migrations still work. A migration should reverse its own changes in
`down()`; batch rollback does not infer reverse DDL. See [Schema](Schema.md)
for the supported definitions and driver differences.

## Commands and history

```bash
php squehub migrate
php squehub migrate:status
php squehub migrate:rollback
php squehub migrate:reset
```

SqueHub also provides a separate static migration source plan:

```bash
php squehub migrate:plan
php squehub migrate:plan --json
```

`migrate:plan` uses the shared [Change Plan](ReviewableChanges.md) to list
bounded, physical migration `.php` files and their SHA-256 source fingerprints.
Each entry is a **candidate source file**, with review risk. It does not load
the PHP, open a database connection, create the `migrations` table, check
history, classify SQL effects, or apply/rollback anything. A listed file is
**not** proven pending; a filename is **not** proof that its `up()` or `down()`
method is safe. Duplicate filename/class identities and unsafe linked source
entries block the plan. Review the selected database and source separately
before the mutating `migrate` command. The plan's source fingerprint is a
digest of its static file inventory, not authorization to execute.

`migrate` applies pending files in order and records one batch number for
that invocation. Repeating it with no new files does nothing. `migrate:status`
shows `pending`, `applied`, or `missing` for a recorded file no longer
present on disk. It opens the selected database and ensures the tracking
table exists; it does not run migration bodies. `migrate:rollback` reverses
the latest batch in reverse history order. `migrate:reset` reverses all
recorded migrations. A missing file or failed `down()` keeps the affected
history row so recovery can be done deliberately. The legacy table gains a
`batch` column if absent, and previous rows retain their order as individual
batches.

Migration history with two names that resolve to the same accepted filename
identity is rejected before status, run, or rollback. The rows remain in
place for manual reconciliation. Preparing the tracking table inside an
existing transaction is rejected, including for status inspection, because
MySQL DDL could commit that transaction.

`migrate`, `migrate:rollback`, and `migrate:reset` acquire a narrow,
nonblocking deployment guard before inspecting pending/history state. A
second cooperating runner fails instead of racing the same migration. For
a file-backed SQLite database, this is a local `flock()` on a sibling
`<canonical database>.squehub-migrations.lock` file. The file may remain
after release; its open lock, not its existence, marks ownership. In-memory
SQLite databases are process-local and do not need a deployment lock. MySQL
uses a server advisory lock scoped to the selected database and releases it
in a `finally` block. `migrate:status` remains a read operation and does not
acquire this execution guard. The MySQL advisory-lock path still needs a
guarded disposable-server qualification run for this hardening pass.

This guard coordinates cooperating SqueHub migration runners; it is not a
crash-safe journal, distributed lock across unrelated backends, or
exactly-once guarantee for arbitrary migration bodies. Keep deployment
coordination and review of MySQL's possible partial DDL changes.

The CLI uses the application's configured default connection. Code using
`new App\Database\Migrations\Migrator(database(), $basePath, 'reporting')`
can select a configured named connection. Review the selected connection
before invoking migration commands; these commands change its schema.

## Failure behavior

For SQLite, each `up()` plus history insert and each `down()` plus history
removal runs in one framework-managed transaction. A failure rolls back
both schema and tracking changes for that migration. Earlier successful
migrations in a batch remain applied. The migrator rejects an already-active
transaction and detects a migration that ends or replaces its managed
transaction. If the transaction identity is lost, it reports uncertain state
even if a replacement transaction was rolled back.

For MySQL, DDL statements can implicitly commit. A failed migration may
leave some schema changes even though no history row was added (or even
though a rollback was attempted). The public error identifies the migration,
selected connection, failing step, and that the database state needs
inspection. Inspect the schema and `migrations` table before retrying;
manually reconcile any partial DDL. The original exception is retained as
`getPrevious()` for secure diagnostics, while CLI output omits arbitrary
SQL, values, DSNs, and exception text. See the [PHP PDO transaction
documentation](https://www.php.net/manual/en/pdo.transactions.php) and
[MySQL implicit commit documentation](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html).

Do not assume a group of MySQL DDL statements is atomic. MySQL atomic DDL
protects an individual supported statement, not an entire multi-statement
migration; see [MySQL atomic DDL](https://dev.mysql.com/doc/refman/8.4/en/atomic-ddl.html).

## Verification

The normal test suite exercises migration order, history, rollback, failure
recovery, foreign keys, and real CLI commands against temporary SQLite
databases. `Tests/Integration/MySqlSchemaOptInTest.php` is an opt-in
real-server suite. It requires `SQUEHUB_TEST_MYSQL_ENABLED=1`, explicit
`SQUEHUB_TEST_MYSQL_HOST`, `SQUEHUB_TEST_MYSQL_PORT`,
`SQUEHUB_TEST_MYSQL_DATABASE`, `SQUEHUB_TEST_MYSQL_USER`, and
`SQUEHUB_TEST_MYSQL_PASSWORD` settings, plus
`SQUEHUB_TEST_MYSQL_CONFIRM_DATABASE` equal to the database name.
The database name must start with `squehub_test_` and
the selected database must be empty. The suite never reads application
`DB_*` settings. An advisory lock serializes cooperating runs before the
emptiness check and remains held through cleanup. Cleanup removes only the
tables created in that confirmed empty database. Leave the opt-in flag unset
for ordinary `composer test` runs.

After setting the explicit test variables, run
`php vendor/bin/phpunit --group mysql`. The test reports the server version
and default engine. Do not reuse an application database for this group.

Migration checks passed with temporary SQLite databases on Windows and a case-sensitive Linux filesystem. Guarded live checks passed with disposable MySQL 8.4.11/InnoDB, and the reported Linux full suite passed with live MySQL opt-in enabled. These results are scoped to the tested environments; they do not establish behavior for every MySQL topology or MariaDB. See [verification status](Status.md).

The [account-security token table](AccountSecurity.md#configuration-and-schema) is an application migration, not an automatic bootstrap side effect. Create it before using database-backed password reset or email verification.

The optional [Webhook](Webhooks.md) database stores have two explicit framework migrations: `2026_09_26_create_webhook_receipts.php` for incoming atomic claims and `2026_09_26_create_webhook_deliveries.php` for safe outgoing metadata. They are not created at Application boot. Run `php squehub migrate` against the intended database before selecting those stores, and keep the resulting history/schema in your deployment plan. Applications using `array` stores for isolated tests do not need those tables.

## Application-facing Plugins import

Application code may import `App\Plugins\DB`, `App\Plugins\Schema`, `App\Plugins\Table`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
