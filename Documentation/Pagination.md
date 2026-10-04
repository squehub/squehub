# Offset and cursor pagination

An explicitly documented API operation can use `Contract::operation()->paginatedResponse(200, ContractSchema::ref('User'))` from the [Application Contract](ApplicationContract.md). It describes the existing ResourceCollection Page envelope and its `data`/`meta` fields; it does not change `page()` queries or convert offset pagination into cursor pagination.

Modern model pages honor [named scopes](Scopes.md) and [soft-delete query
modes](SoftDeletes.md). Their count and items queries use the same filters.
Raw table pages have no model-specific filters.

Use `page($page, $perPage)` on a table query or modern model query:

```php
$rows = db('users')->filter('status', 'active')->sort('id')->page(2, 20);
$users = User::query()->with(['posts', 'roles'])->sort('id')->page(2, 20);
```

Both return `App\Plugins\Page` (the same class as `App\Database\Pagination\Page`). A table page's `items()` is a
plain list of associative rows; a modern model page's `items()` is a
`ModelCollection`. The model page hydrates with the normal ModelQuery path
and eagerly loads only its returned parents.

```php
$page->items();
$page->page();
$page->perPage();
$page->total();
$page->pages();
$page->from();
$page->to();
$page->hasNext();
$page->hasPrevious();
$page->nextPage();
$page->previousPage();
```

`toArray()` and JSON use these fields: `items`, `page`, `per_page`, `total`,
`pages`, `from`, `to`, `has_next`, and `has_previous`. Model items pass through
Model serialization, including casts and hidden-field rules. No SQL, bindings,
connections, request parameters, or URLs appear in the result.

Page and size must be positive integers. Arithmetic overflow raises an
exception. An empty dataset has zero pages, null `from`/`to`, and no next or
previous page. A requested page beyond the end remains unchanged, returns
empty items, and retains the true total and page count.

Pagination clones the builder, performs one unbounded `COUNT(*)` over its
filters, then one sorted `LIMIT`/`OFFSET` data query. The original query is
unchanged. `filter`, `orFilter`, `filterIn`, `filterNull`, selection, and sorting
are supported. A query already using `limit()` or `skip()` is rejected.
`distinct`, grouping/having, and joins are rejected because their row-count
meaning can differ from a simple table count. This also applies to modern
ModelQuery pagination through its underlying table query.

Model `has()` and nested relation-existence predicates remain part of both
the total-count and data queries. `withCount('comments')` adds correlated
relation-count columns **only** to the data SELECT; pagination's separate
total query does not calculate those per-item projections. The returned
`ModelCollection` exposes each `comments_count` as a read-only query value.
Related soft deletes, pivot filters, and explicit count callbacks follow
the [relation-count contract](Relationships.md#count-related-rows-without-loading-them).

Nested eager loading adds bounded relation queries after the parent page
is fetched. Eager relation limits still apply to each batch, not separately
to each parent. Relation-query `page()`, URL generation, and HTML controls
are not part of this API.

## Forward cursor pagination

Cursor pages traverse large ordered result sets without a growing OFFSET
or an automatic total-count query. A cursor is an opaque continuation token,
not an authorization token or encrypted data. Treat it as untrusted input
and avoid exposing sensitive sort values through cursor pages.

```php
$first = User::query()
    ->filter('status', 'active')
    ->sort('created_at', 'desc')
    ->with(['posts.author'])
    ->cursorPage(50);

$next = User::query()
    ->filter('status', 'active')
    ->sort('created_at', 'desc')
    ->with(['posts.author'])
    ->cursorPage(50, after: $first->nextCursor());

$raw = db('users')->sort('id')->cursorPage(50, key: 'id');

$withCounts = Post::query()
    ->withCount('comments')
    ->sort('created_at', 'desc')
    ->cursorPage(50);
```

`ModelQuery::cursorPage()` uses its Model primary key as the unique
tie-breaker; raw `QueryBuilder::cursorPage()` takes a unique key column,
defaulting to `id`. The query must select its ordering columns and key.
Only simple, unbounded table queries are accepted: no join, grouping,
having, distinct, existing `limit()`/`skip()`, or row lock. Sorting uses
declared, non-null scalar columns; the unique key is appended as an ascending
tie-breaker when needed. This is forward traversal, not random page jumps
or reverse pagination. The same filters and sort must be used for each page;
the cursor rejects a changed query shape. Applications must ensure the
chosen key is actually unique in their schema.

`App\Plugins\CursorPage` offers `items()`, `perPage()`, `nextCursor()`,
`hasMore()`, `toArray()`, and `toJson()`. Raw items are associative rows;
Model items are a `ModelCollection`. `toArray()` contains `items`,
`per_page`, `next_cursor`, and `has_more`; it has no total or page number.
The implementation reads at most one extra row to establish `hasMore()`.
Model eager loading runs only for the returned models. Malformed, oversized,
or mismatched cursors fail before executing a continuation query.

The token carries bounded encoded order values plus a checksum and query
fingerprint. The checksum detects accidental corruption; it is **not** a
signature. Do not use a cursor as proof of identity or permission. Under a
stable dataset, unique-key ordering prevents duplicate rows. Inserts,
deletes, or changes to sort values between requests can change traversal
membership; a cursor is not a snapshot transaction across requests.

For bounded batch processing, `QueryBuilder::chunk($size, $callback,
key: 'id')` and `ModelQuery::chunk($size, $callback)` reuse forward keyset
pages. The callback receives a row batch or `ModelCollection`; returning
`false` stops. These helpers return the number of batches delivered.

## Application-facing Plugins import

Application code may import `App\Plugins\Page` and `App\Plugins\CursorPage`.
These are exact aliases of the canonical result classes. See
[Plugins](Plugins.md) for the complete mapping and compatibility rules.
