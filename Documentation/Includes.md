# Includes and Reusable Partials

An include renders another `.squehub.php` View inside the current render tree. Use a partial for repeated markup that should inherit the page's data. It is still a normal View: its escaped output, composer, sections, loops, and [asset declarations](Assets.md) follow the same rules as the surrounding template.

An enabled Package partial can be named explicitly, for example `@include('Commerce::Partials.Order')`. An unqualified reference inside a Package View remains an ordinary application View reference. `@includeOptional` skips a genuinely missing local View under a valid namespace; it does not hide an unknown or disabled Package namespace. A false `@includeWhen` skips namespace lookup as well as rendering. See [Package and Namespaced Views](PackageViews.md).

## Required includes

Create `Project/Views/Partials/Editor.squehub.php`:

```php
<section class="editor">
    <h2>{{ $title }}</h2>
    <p>{{ $post->summary }}</p>
</section>
```

Then include it from a page:

```php
@include('Partials.Editor')
```

`$title` and `$post` arrive from the current render context. There is no need to forward them in an array. A missing required View fails through the normal View error boundary; it never silently renders as an empty fragment.

Use the second argument only to add or deliberately override data:

```php
@include('Partials.Editor', [
    'mode' => 'compact',
    'title' => 'Quick edit',
])
```

This overlay belongs to `Partials.Editor` and its nested includes. The parent and sibling Views retain their own bindings. Values such as `null`, `false`, `0`, `''`, and `[]` are explicit overrides, not missing values. SqueHub isolates the variable map; it does not deep-clone objects passed within that map. Templates are trusted PHP, not an object sandbox.

The effective precedence in an included View is **inherited context < that View's composer < explicit include data**. An active iterable loop's framework-owned `$loop` and the reserved `$errors` binding cannot be replaced by include data. Outside an active iterable loop, a developer-supplied `loop` value follows ordinary View-data rules. See [Shared View Context](ViewContext.md) and [Loops and Iteration State](Loops.md).

## Optional includes

Use an optional include when absence is expected:

```php
@includeOptional('Partials.Promo')

@includeOptional('Partials.Promo', [
    'placement' => 'sidebar',
])
```

If the logical View is genuinely absent, the directive emits nothing. It does not run that View's composer, template code, nested includes, or assets. If the View exists but its template fails, the error propagates. An unsafe path, outside-root or broken symlink, and a containment rejection are errors rather than optional absence. A partial that appears on a later render is found normally; absence is not cached permanently.

## Conditional includes

Use `@includeWhen` when the decision belongs in the page's presentation logic:

```php
@includeWhen($editing, 'Partials.Editor')

@includeWhen(
    $editing && $user->canEdit(),
    'Partials.Editor',
    ['mode' => 'compact']
)
```

When the condition is false, SqueHub does not evaluate the target or data expression, resolve the View, run its composer, execute its template, or collect its assets. When true, the include is **required** and follows the same missing-View behavior as `@include`. Conditions are trusted PHP evaluated at render time; keep authorization enforcement in middleware or service policy rather than relying on a template condition.

All three directives use a quoted, static logical View name. Their arguments may span lines and contain balanced nested expressions. Include data must evaluate to a string-keyed View-data array with valid template variable names. Malformed directive arguments fail with a source-aware compiler error; invalid runtime data fails without printing the data. Do not build template file paths from request input.

## Nested scope, loops, and layouts

Nested includes inherit the nearest resolved context. For example, when the page has `$mode = 'full'`:

```php
@include('Partials.Card', ['mode' => 'compact'])
<p>{{ $mode }}</p>
```

`Partials.Card` and any nested `Partials.CardActions` see `compact`; the page paragraph still renders `full`. A sibling partial sees the page's original `full` unless it receives its own override. Included View composers run once per actual include rendering under their existing contract; request providers do not rerun for each nested partial.

An include works in a layout, a section, a condition, a loop, or a captured asset block:

```php
@foreach ($posts as $post)
    @include('Partials.PostCard')
@endforeach
```

`Partials.PostCard` receives `$post` and the active read-only `$loop` without forwarding. A nested include keeps that loop context. If a partial starts another iterable loop, its inner `$loop->parent` refers to the outer loop. A section containing an include executes the partial during section capture; repeating `@yield` reuses the captured result.

## Partial-owned assets

A partial may declare its own resources:

```php
@style('/assets/editor.css')
@script('/assets/editor.js')
```

Those resources participate only when the partial actually renders. A false `@includeWhen` or absent `@includeOptional` contributes none. Repeating the partial renders its HTML each time, while direct styles and scripts deduplicate under the [asset-stack rules](Assets.md). Generic `@push` content keeps its normal asset-stack behavior.

## Resolution, cycles, and failures

Includes use the same logical View resolver, root order, realpath containment, and current Package activation as top-level Views and layouts. Valid in-root links may resolve; paths outside approved roots, broken links, and unsafe targets fail. Existing compiled output never authorizes a View whose current source resolution is unsafe or absent.

SqueHub rejects a partial that re-enters its **active include chain**, including through a logical or in-root symlink alias that resolves to the same physical View. Direct and multi-file cycles fail before unbounded recursion. Repeating the same partial after an earlier include has completed is valid, including inside a loop; every occurrence renders its HTML. A failed render clears its include, section, loop, context, and asset state before the next render.

Development errors identify logical Views and source locations where possible. Production errors use the safe HTTP boundary and must not expose physical paths, compiled files, render data, secrets, or outside-root content. An include writes into the current output stream; it does not create an HTTP Response or independently set headers.

## Partials and components

A **partial** inherits View context and accepts a small explicit data overlay. A [component](Components.md) declares props, accepts an independent attribute bag, and receives caller-rendered slots. The component template does not inherit arbitrary caller variables; use a partial when inheritance is what you need. A [Fragment](Fragments.md) instead names an independently selectable boundary in the requested root View. A partial rendered *inside* a selected Fragment keeps its usual inherited context and contributes its own assets; a sibling partial outside that Fragment does not render. Partial templates cannot declare externally selectable Fragments. Includes do not create asynchronous requests or an include-result cache.

Form partials inherit the current framework `$errors` bag and can use `@error('field')`, `old()`, `@csrf`, `@method('PATCH')`, `checked()`, and `selected()`. The helpers resolve current request/Session state when the partial actually renders. An optional or conditionally skipped partial does not issue a CSRF field or read flash data. See [Forms and Validation UX](Forms.md).

Partials may also use `@auth`, `@guest`, `@can`, `@cannot`, and `@session`. These read the current Auth, Authorization, and Session state at render time. A parent branch that skips a partial does not run its checks or collect its assets. A View directive controls presentation only; protect endpoints separately. See [Auth, Guards, Session and Authorization](ViewSecurity.md).

Required missing includes and active include/component cycles report their logical dependency origin and chain. An optional include skips only genuine absence. Partial templates use the same [compiled View lifecycle](CompiledViews.md) as their callers: a changed partial is recompiled from its own current source even when the parent page is unchanged, and warming compiles the partial without executing it. Use `$this->view(...)` to test the actual rendered partial in its calling page, or `$this->fragment(...)` when the selected Fragment owns it; see [View Diagnostics and Testing](ViewDiagnosticsTesting.md).
