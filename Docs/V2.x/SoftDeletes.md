# Soft deletes

Opt in per modern model and add a nullable datetime column through an
application migration. The model does not create schema automatically.

```php
final class User extends \App\Plugins\Model
{
    protected bool $softDeletes = true;
    protected array $fillable = ['name'];
}

// In a Schema table definition:
$table->datetime('deleted_at')->nullable();
```

The default column is `deleted_at`; override `protected string
$deletedAtColumn = 'removed_at'` to use another validated name. It must differ
from the primary key and managed timestamps. An explicit cast for the column
must be `datetime`; it is cast automatically to `DateTimeImmutable` when
non-null. SQL null stays null. Serialization follows normal datetime and
`$hidden` rules. `fill()` and `setAttribute()` reject deletion-column writes;
use lifecycle methods for normal transitions.

```php
$user->delete();       // soft-delete UPDATE
$user->isDeleted();    // true after success
$user->restore();      // clear deletion timestamp
$user->forceDelete();  // physical DELETE

User::query()->all();                 // active only
User::query()->withDeleted()->all();  // active and deleted
User::query()->onlyDeleted()->all();  // deleted only
```

The last explicit query mode wins. `find()`, `first()`, `count()`, `exists()`,
and `page()` honor that mode. Scope filters and deleted mode apply to both page
count and items. Related models follow their own default policy; explicit
relation queries can call `withDeleted()` or `onlyDeleted()`. Eager loading
uses the default mode and stays batched.

Lifecycle methods return `false` when no row changes, including repeat delete
or restore. A soft-deleted model stays persisted and retains its identity.
`save()` may update ordinary attributes without restoring it. `refresh()`
reloads the same row even if deleted; a successful refresh discards pending
edits. A physical `forceDelete()` prevents that instance from saving a new row.
`restore()` and `forceDelete()` reject models without soft deletes;
`isDeleted()` returns false for them and for a permanently removed instance.

The model clock provides one UTC instant for `deleted_at` and, when enabled,
`updated_at`. `created_at` stays unchanged. Restore clears the deletion column
and updates `updated_at`. Lifecycle writes leave unrelated unsaved changes
pending. A successful write clears only that model's loaded relation cache;
other Model instances may remain stale until reloaded.

Soft deletion does not trigger database `ON DELETE` actions or remove unique
constraints. It records a timestamp, not an audit history. `forceDelete()`
follows normal foreign-key rules. Raw `db()` queries still see all rows, and
`App\Core\Model` keeps its legacy behavior. A caller-owned transaction rollback
cannot automatically restore an in-memory model snapshot; refresh after
rollback when appropriate. Generic global scopes, bulk lifecycle operations,
and configured eager-loading modifiers are not part of this release.

## Application-facing Plugins import

Application code may import `App\Plugins\Model`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
