# Loops and Iteration State

SqueHub's `.squehub.php` templates support `@foreach`, `@forelse`, `@for`, and `@while`. The template compiler checks directive structure and manages iteration state; PHP evaluates trusted loop expressions and executes the resulting control flow at render time. Keep complex data preparation in controllers or services. See [Views and layouts](Views.md) for rendering and escaping rules and [Conditionals and Control Flow](Conditionals.md) for conditionals and switches.

## Iterate values and keys

```php
@foreach ($users as $user)
    {{ $loop->iteration }}. {{ $user->name }}
@endforeach
```

The key/value form retains the iterable's original keys. `$loop->index` is the zero-based *position*, independent of the key: keys `10` and `40` have loop indexes `0` and `1`.

```php
@foreach ($settings as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
```

Expressions and the `as` clause may span lines and contain nested PHP calls, arrays, and quoted text. The iterable expression is evaluated exactly once per loop execution. Arrays, `Traversable` objects, iterators, iterator aggregates, and generators can be used. Arrays are counted directly; `Traversable` values are buffered into a finite sequence so complete iteration metadata describes the items that actually render. Buffering preserves yielded keys. A generator is consumed once, not replayed after counting. Complete `$loop` metadata therefore requires a finite iterable, and non-countable `Traversable` values may be held in memory for the duration of rendering.

By-reference targets such as `@foreach ($items as &$item)` are rejected because preparing the iteration sequence cannot promise PHP's reference semantics. A key or value target named `$loop` or beginning with `$__squehub_` is also rejected: those names belong to iteration state and compiler internals. Malformed `@foreach` clauses, including a missing top-level `as`, produce a compiler error.

## Render an empty branch

```php
@forelse ($users as $user)
    {{ $user->name }}
@empty
    No users.
@endforelse
```

`@forelse` uses the same iterable and key/value rules as `@foreach`. It evaluates the iterable expression once. A non-empty iterable renders its body and never renders `@empty`, even if the body uses `@break` or `@continue`. An empty iterable, including a generator that yields nothing, renders the `@empty` branch once. `@empty` takes no arguments and may appear only once inside its matching `@forelse`.

## `$loop` metadata

Within each `@foreach` or non-empty `@forelse` iteration, SqueHub supplies a read-only `$loop` object:

| Property | Meaning |
| --- | --- |
| `$loop->index` | Zero-based position; first item is `0`. |
| `$loop->iteration` | One-based position; first item is `1`. |
| `$loop->remaining` | Items after the current one: `count - iteration`. |
| `$loop->count` | Total number of items in this iterable. |
| `$loop->first` | `true` when `index === 0`. |
| `$loop->last` | `true` when `iteration === count`. |
| `$loop->even` | `true` when the one-based iteration is even. |
| `$loop->odd` | `true` when the one-based iteration is odd. |
| `$loop->depth` | Iterable nesting depth, starting at `1`. |
| `$loop->parent` | The current enclosing iterable loop context, or `null` at the top level. |

For three items, the first iteration has `index = 0`, `iteration = 1`, `remaining = 2`, `first = true`, and `odd = true`. The third has `index = 2`, `iteration = 3`, `remaining = 0`, and `last = true`. Properties cannot be assigned in a template.

Nested iterable loops expose their current parent iteration. Deeper nesting can follow `$loop->parent->parent`:

```php
@foreach ($groups as $group)
    @foreach ($group->users as $user)
        Group {{ $loop->parent->iteration }}
        User {{ $loop->iteration }}
    @endforeach
@endforeach
```

SqueHub owns `$loop` inside an active iterable loop. Nested loops restore the outer loop context when they finish. After the outer loop ends, an existing developer-supplied `$loop` value is restored; if no value existed, it is undefined again. An [included partial](Includes.md) in an active loop receives the current context without explicit forwarding. Its nested includes retain the active `$loop`. Include data or a partial composer named `loop` cannot replace that inherited loop context inside the active iterable loop. A nested iterable loop in the partial sees it through `$loop->parent`. A [component](Components.md) invoked inside that loop receives the active framework `$loop` without gaining unrelated caller variables; its caller-scope slot body can also use `$loop`.

## For and while

`@for` and `@while` use normal PHP runtime behavior, including multiline expressions:

```php
@for ($i = 0; $i < 10; $i++)
    {{ $i }}
@endfor

@while ($queue->hasItems())
    ...
@endwhile
```

SqueHub does not fabricate complete `$loop` metadata for these forms: an arbitrary `@for` or `@while` loop has no reliable total count or last iteration. They do not create a new `$loop` context. PHP evaluates a `@while` condition each iteration and the `@for` clauses according to normal PHP semantics.

## Break and continue

Bare `@break` ends the nearest active PHP loop or switch. Bare `@continue` advances the nearest active loop when the nearest enclosing loop or switch is a loop. Both work through nested conditionals:

```php
@foreach ($users as $user)
    @if (!$user->active)
        @continue
    @endif
    {{ $user->name }}
    @if ($user->last)
        @break
    @endif
@endforeach
```

`@break` outside a loop or switch and `@continue` outside a loop are compiler errors. `@continue` with a switch as its nearest enclosing loop or switch is rejected, including a switch nested inside a loop, because PHP would target the switch rather than clearly advance the enclosing loop. The public directive forms are bare `@break` and `@continue`; depth and condition arguments are unsupported.

## Structure and rendering

Opening and closing directives must match. The compiler rejects unmatched or unclosed `@endforeach`, `@endforelse`, `@endfor`, and `@endwhile`, misplaced or duplicate `@empty`, and empty loop expressions where detectable. Development errors identify the logical View and source line. Production HTTP errors use the application's safe exception boundary and do not reveal template source or filesystem paths.

Iteration values and metadata are prepared at render time. Reusing a compiled template with different data produces fresh counts and contexts; no iterable contents are baked into its compiled PHP. `{{ ... }}` still escapes HTML, and `{!! ... !!}` still requires trusted or separately sanitized content. Templates are trusted application code, not a sandbox for user-supplied PHP.

Loops can run inside `@section('content') ... @endsection`; the captured content is then available to the page's layout. `$loop` follows the same nesting and restoration rules there: after its iterable loop ends, it does not leak into another section or ancestor layout. See [Layouts and Sections](Layouts.md#context-control-flow-and-includes) for a `@forelse` example inside a section.

Asset declarations may also execute inside a loop. Repeating `@script('/assets/widget.js')` produces one direct script resource through normal deduplication; `@script('/assets/widget.js', once: 'widget-runtime')` supplies an explicit render-local key. A pushed block executes on each reached iteration unless an explicit `once` key deduplicates identical captured content. See [Template-owned Scripts, Styles and Asset Stacks](Assets.md) for stack ordering and conflict rules.
