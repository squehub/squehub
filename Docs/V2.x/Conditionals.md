# Conditionals and Control Flow

SqueHub's `.squehub.php` templates support `@if`, `@unless`, and `@switch` blocks. The template compiler checks their structure and emits ordinary PHP control flow. Conditional expressions are **trusted PHP expressions evaluated at render time**. The compiler does not evaluate them, interpret their meaning, or sandbox template code. Keep application decisions in controllers or services when they become complex.

## If and elseif

```php
@if ($user)
    Welcome, {{ $user->name }}
@elseif ($invited)
    Welcome, invited guest.
@else
    Welcome, guest.
@endif
```

`@if` and `@elseif` accept balanced, multiline expressions, including nested calls, arrays, quoted punctuation, and valid PHP 8.2 expressions:

```php
@if (
    $user !== null
    && in_array($status, ['active', 'pending'], true)
)
    <p>Account available</p>
@endif
```

PHP evaluates each condition when the View renders. It retains normal short-circuit and branch behavior: an unselected branch does not execute its PHP or echoes. A compiled View can therefore render different branches on later requests without recompiling for a changed condition.

## Unless

`@unless` renders its first branch when its expression is false. It accepts `@else`, but `@elseif` belongs to `@if`.

```php
@unless ($maintenance)
    <main>...</main>
@else
    <p>Temporarily unavailable.</p>
@endunless
```

`@unless` expressions follow the same balanced, multiline PHP rules as `@if`.

## Switch

```php
@switch($status)
    @case('active')
        Active
        @break

    @case('pending')
        Pending
        @break

    @default
        Unknown
@endswitch
```

`@switch` and `@case` accept normal trusted PHP expressions, including multiline ones. They use PHP's normal switch comparison and fall-through behavior. SqueHub does not insert a break: adjacent cases may deliberately share a branch, and a case without `@break` continues to the next case. Bare `@break` works inside a switch, including within an `@if` nested in a case. In a nested switch, PHP's normal `break` targets the inner switch. Phase 14D also allows `@break` inside loops; conditional and depth arguments are unsupported. See [Loops and Iteration State](Loops.md).

Between `@switch(...)` and its first `@case` or `@default`, only whitespace is allowed. The compiler consumes that whitespace as PHP syntax, so it produces no HTML. Other content before the first label is a template compile error. After a case or default label, ordinary template content and its whitespace render normally.

## Structure and diagnostics

The compiler checks matching `@endif`, `@endunless`, and `@endswitch` directives, including nested blocks. It reports unmatched or unclosed blocks, mismatched endings, `@elseif` outside an open `@if`, `@else` outside `@if` or `@unless`, duplicate `@else`, `@elseif` after `@else`, `@case` or `@default` outside a switch, duplicate `@default`, and `@break` without an enclosing switch or loop. The compiler also rejects empty expressions and argument syntax on bare directives such as `@else(...)`. Development diagnostics name the logical View and source line. Production HTTP errors use the application's safe exception boundary and do not expose template source or filesystem paths.

Directives can appear inline; they do not need separate lines. Names have lexical boundaries: `@elseB` is a different, unknown directive name, not `@else` followed by `B`. Write `@else B` or place markup after `@else` when literal content starts with a letter. Unknown `@` text, CSS `@media`, and email addresses remain literal. Directives inside raw PHP, `@php` blocks, or protected JavaScript strings do not compile; HTML comments are removed, so directives inside them do not execute. PHP syntax errors inside otherwise balanced expressions remain PHP errors, not template structure errors.

## View data, layouts, and output

Conditions can read the same values as the rest of a View: explicit render data, Application shared values, request providers, composers, and inherited layout or include data. An explicit include override affects that partial subtree without changing its parent. Conditions do not create a new context or change provider lifetime. See [Shared View Context](ViewContext.md) for precedence and isolation rules.

Conditions also work inside a captured section. The page can choose content with `@if` or `@switch` before its parent layout yields that section. The section is captured once per render, so yielding it twice does not rerun its conditions or their side effects. See [Layouts and Sections](Layouts.md#context-control-flow-and-includes) for a page example and inheritance rules.

An asset declaration or `@push` block inside an `@if` branch runs only when that branch executes. Likewise, a partial included conditionally contributes its own scripts and styles only when it renders. The [asset stacks guide](Assets.md) explains ownership and final stack placement; a condition does not create a separate asset registry.

A layout can use the shared or explicit `title` value with a fallback:

```php
@if (isset($title) && $title !== '')
    <title>{{ $title }}</title>
@else
    <title>My Application</title>
@endif
```

`{{ $value }}` remains HTML escaped in every branch. `{!! $trustedHtml !!}` remains raw output and requires trusted or separately sanitized HTML. Conditionals do not replace server-side authorization or input validation.

Phase 14D adds structurally checked loops, bare loop-scoped `@break` and `@continue`, and read-only `$loop` metadata for `@foreach` and `@forelse`. A switch alone does not permit `@continue`; a switch nested inside a loop also blocks bare `@continue` unless another loop is nested inside that switch. See [Loops and Iteration State](Loops.md) and the [roadmap](Roadmap.md). The legacy `@do` form remains compatible.
