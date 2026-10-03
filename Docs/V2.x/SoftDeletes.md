# Soft deletes

Soft deletion keeps a row and records when it was removed. Opt in per modern
model and add a nullable datetime column through an application
[migration](Migrations.md). The model does not create or alter schema
automatically.

```php
final class User extends \App\Plugins\Model
{
    protected string $table = 'users';
    protected bool $softDeletes = true;
    protected bool $timestamps = true;
    protected array $fillable = ['name'];
}

// In a Schema table definition:
$table->id();
$table->string('name');
$table->datetime('created_at');
$table->datetime('updated_at');
$table->datetime('deleted_at')->nullable();
```

The default column is `deleted_at`. To use another column, declare
`$deletedAtColumn = 'removed_at'` as a protected string property and create
that column in the migration. It must be a simple database identifier and
differ from the primary key and enabled managed timestamps. An explicit cast
for the column must be `datetime`; the model supplies that cast automatically
otherwise. A non-null value reads as `DateTimeImmutable` while SQL `NULL`
stays `null`. Serialization follows normal datetime and `$hidden` rules.
Do not add the deletion column to `$fillable`: `fill()` and
`setAttribute()` reject writes to it even when listed there.

## Query active and deleted rows

```php
$user->delete();       // soft-delete UPDATE
$user->isDeleted();    // true after success
$user->restore();      // clear deletion timestamp
$user->forceDelete();  // physical DELETE

User::query()->all();                 // active only
User::query()->withDeleted()->all();  // active and deleted
User::query()->onlyDeleted()->all();  // deleted only

$id = 42;
User::find($id); // null if deleted.
$removed = User::query()->onlyDeleted()->find($id);
$page = User::query()->onlyDeleted()->sort('id')->page(1, 20);
```

The last explicit query mode wins. `find()`, `first()`, `count()`, `exists()`,
`page()`, and `cursorPage()` honor that mode. Scope filters and deleted mode
apply to both page count and items. Normal filters and relation-existence
predicates compose with the mode; `orFilter()` cannot bypass the required
deleted-record condition. Calling either mode modifier on a model without
soft deletes throws `LogicException`. See [Scopes](Scopes.md) and
[Pagination](Pagination.md).

Related models follow **their own** default policy. Explicit relation
queries can call `withDeleted()` or `onlyDeleted()`; eager loading uses the
related model's default mode and stays batched:

```php
$visiblePosts = $user->posts; // Cached default relation result.
$deletedPosts = $user->posts()->onlyDeleted()->get();
$allPosts = $user->posts()->withDeleted()->get();
$users = User::query()->withDeleted()->with('posts')->get();
// Deleted users are included; deleted posts still need an explicit query.
```

The Post model must also opt in. Calling the relation method builds a query
without rewriting the cached `$user->posts` property. Many-to-many soft
deletion leaves pivot rows in place. `whereHas()` and `withCount()` use the
related model's default mode unless their callback changes it. See
[Relationships](Relationships.md).

## Delete, restore, and remove permanently

To restore a row hidden from ordinary `find()`, retrieve it with
`onlyDeleted()` first:

```php
$removed = User::query()->onlyDeleted()->find($id);
if ($removed !== null) {
    $removed->restore();
}
```

Lifecycle methods act on one persisted model identified by its original
primary key. They return `false` when no row changes, including repeat
delete or restore or a transition whose database row is gone. A
soft-deleted model stays persisted and retains its identity.
`save()` may update ordinary attributes without restoring it. `refresh()`
reloads the same row even if deleted; a successful refresh discards pending
edits. A physical `forceDelete()` prevents that instance from saving a new row.
`restore()` and `forceDelete()` reject models without soft deletes;
`isDeleted()` returns false for them and for a permanently removed instance.
For a model without soft deletes, ordinary `delete()` remains a physical
delete.

The model clock provides one UTC instant for `deleted_at` and, when enabled,
`updated_at`. `created_at` stays unchanged. Restore clears the deletion column
and updates `updated_at`. Lifecycle writes leave unrelated unsaved changes
pending. A successful write clears only that model's loaded relation cache;
other Model instances may remain stale until reloaded.

A partially selected model can delete or restore if its primary key was
selected. Immediately after a partial read that omits the deletion column,
`isDeleted()` cannot know its state and throws `LogicException`; select it
when displaying that state.
`refresh()` fails if the persisted row no longer exists.

## Schema and behavior boundaries

Soft deletion does not trigger database `ON DELETE` actions or remove unique
constraints: a deleted row can still occupy a unique value. It records a
timestamp, not an audit history. `forceDelete()`
follows normal foreign-key rules. Raw `db()` queries still see all rows, and
`App\Core\Model` keeps its legacy behavior. A caller-owned transaction rollback
cannot automatically restore an in-memory model snapshot; refresh after
rollback when appropriate. Generic global scopes, bulk lifecycle operations,
and configured eager-loading modifiers are not part of this release. For
observer phases and transaction timing, see [Models](Models.md).

## Application-facing Plugins import

Application code may import `App\Plugins\Model`, which inherits the
canonical database Model. See [Plugins](Plugins.md) for the complete
mapping and compatibility rules.
