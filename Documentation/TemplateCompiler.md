# Reliable Template Compiler

SqueHub renders trusted application templates with the `.squehub.php` extension. Views are selected by dot-separated logical names, such as `Pages.Posts.Show`, through the normal [View resolver](Views.md). Application templates live in `Project/Views`; published package templates live in `Project/PackagesViews`. Use `App\Plugins\View::render()` to render them. The compiler is internal framework infrastructure, not a separate application API or a sandbox for user-supplied templates.

The compiler makes existing syntax predictable when expressions span lines or contain nested PHP calls, arrays, strings, and closures. It scans template constructs, matches their delimiters, passes recognized directives to their handlers, and emits PHP for the normal View renderer. PHP evaluates the trusted expressions at render time. Ordinary HTML markup, CSS, JavaScript, and unknown `@` text remain literal template content, subject to the existing HTML-comment behavior below.

## Output and trusted expressions

```php
<h1>{{ $title }}</h1>
<div>{!! $trustedHtml !!}</div>
```

`{{ ... }}` escapes HTML with the existing UTF-8 `ENT_QUOTES | ENT_SUBSTITUTE` policy. It handles text and quoted HTML attribute values; invalid UTF-8 is replaced. The legacy `@echo(...)` shortcut uses the same escaping policy. HTML escaping does not validate URL schemes or make JavaScript and CSS contexts safe.

`{!! ... !!}` deliberately emits raw output. Only pass trusted or separately sanitized HTML to it. Templates and PHP expressions are trusted application code; do not allow untrusted users to upload or edit `.squehub.php` files.

Both echo forms accept multiline expressions. Closing-delimiter text inside a quoted PHP string is data, not the end of an echo:

```php
{{
    json_encode([
        'closing' => '}}',
        'title' => strtoupper($title),
    ])
}}
```

Use raw output only when the resulting HTML is intentionally trusted. `{{ }}` and `{!! !!}` retain their separate escaping behavior regardless of line breaks or nesting.

```php
{!!
    implode('', [
        '<strong>',
        $trustedHtml,
        '</strong>',
    ])
!!}
```

## Directives and balanced arguments

The compiler recognizes layout, section, include, asset, and CSRF directives:

| Directive | Current behavior |
| --- | --- |
| `@extends('Layouts.Main')` | Declare one parent layout for the template, before or after its sections and outside control flow. An optional data argument can override values for that layout. |
| `@section('content')` and `@endsection` | Capture a named section once for the render tree. Same-template duplicates, nested or unclosed sections, and unmatched endings are compiler errors. |
| `@fragment('orders.list') ... @endfragment` | Name an independently selectable region of the requested root View. A normal page render emits its body in place; `View::fragment()` executes only that body. Names and placement are checked at compile time. |
| `@yield('content')` | Emit a captured section, or lazily evaluate and escape an optional fallback when the section is undefined. An explicitly empty section suppresses the fallback. |
| `@include('Partials.Card')` | Render a partial with inherited View data; an optional data array overrides values in that partial subtree. |
| `@includeOptional('Partials.Promo')` | Render an existing partial; emit nothing if the logical View is genuinely absent. An optional data array overlays its subtree. |
| `@includeWhen($show, 'Partials.Card')` | Render a required partial when the condition is true. A false condition does not evaluate the target or data arguments. |
| `@component('Card', $props, $attributes) ... @endcomponent` | Capture caller-scope slots, then render `Components.Card` with its declared props and separate attributes. The last two arguments are optional. |
| `@props(['title', 'variant' => 'default'])` | Declare required and defaulted props once at the top level of a component template. |
| `@slot('footer') ... @endslot` | Capture a named caller-scope slot within the nearest component invocation. |
| `@style('/assets/app.css')` and `@script('/assets/app.js')` | Collect a direct resource for the executing template, with an optional `once:` key. The URL may be a trusted runtime expression. |
| `@stack('styles')` | Place a named stack after all resources in the render tree have been collected. |
| `@push('head') ... @endpush` and `@prepend('scripts') ... @endprepend` | Capture a rendered block for a named stack; `once:` may deduplicate repeated blocks. |
| `@csrf` | Emit the current session's hidden CSRF field at render time. |
| `@method('PATCH')` | Emit a validated hidden `_method` form field at render time; only PUT, PATCH, and DELETE are supported. |
| `@error('field') ... @enderror` | Conditionally expose the first ErrorBag message as scoped `$message`, then restore a previous binding. |
| `@json($value)` | Emit safely encoded JSON for an inline JavaScript value or JSON text position; it is a single runtime expression, not a general raw-HTML escape hatch. |

Directive names must be recognized; View, section, and stack names remain quoted and static. Section names can include dots, as in `page.actions`. The include variants share one safe View resolver. For example, a partial can receive an expression with nested calls, arrays, and quoted punctuation:

```php
@include(
    'Partials.Card',
    [
        'title' => strtoupper(trim($title)),
        'post' => $post,
        'options' => ['label' => 'Open (details), now'],
    ]
)
```

The scanner matches the outer `)` while tracking nested `()`, `[]`, `{}`, quoted strings, escaped quotes, and PHP heredoc/nowdoc boundaries. A comma inside a string or nested array does not split top-level directive arguments. It does not evaluate the expression or impose a separate PHP expression grammar. Existing layout and include data inheritance, composer precedence, and explicit subtree overrides are described in [Shared View Context](ViewContext.md).

`@yield('heading', strtoupper($fallback))` evaluates its default at render time **only when** the section is absent, then HTML-escapes the result. The same argument can span several lines. Already-rendered section content is emitted without a second escape pass. The [Layouts and Sections guide](Layouts.md) covers nested layout resolution, child section precedence, and circular chains.

The scanner validates [conditionals](Conditionals.md), [loops](Loops.md), [layouts and sections](Layouts.md), [asset stacks](Assets.md), [includes](Includes.md), [components](Components.md), [form helpers](Forms.md), and [Auth, Authorization, and Session presentation](ViewSecurity.md). It also supports a balanced runtime `@json(...)` expression and plain-PHP [Template Utilities](TemplateUtilities.md). Trusted expressions run at render time; each structural directive retains source-aware diagnostics.

## Fragment compilation

The compiler maps a root View's static `@fragment('name') ... @endfragment` boundaries into an exact-name renderer map. Normal page output invokes a Fragment at its declaration position; `View::fragment()` selects just one compiled body without running sibling template code. Declaration is allowed at root level or directly inside a root section, never in a Partial, Layout, Component, conditional, loop, asset block, slot, or another Fragment. The compiler rejects duplicate names, unmatched or unclosed endings, nesting, invalid names, and invalid placement with the logical View and source line. Fragment names are case-sensitive logical identifiers, not filesystem paths. The [Fragment guide](Fragments.md) covers result data and assets.

Directive boundaries and protected contexts still apply: `@fragmented` is not `@fragment`, and directive-looking text in PHP, HTML comments, CSS, or protected JavaScript text is not compiled. Both LF and CRLF source diagnostics retain their established line behavior. Runtime data, Auth, Session, error messages, and asset results are absent from the compiled Fragment map.

## Literal text and PHP blocks

Only recognized SqueHub constructs compile. Unknown text such as `@media`, `@example`, and an email address containing `@` remains literal. Ordinary CSS and JavaScript are retained. Existing direct PHP blocks (`<?php ... ?>` and `<?= ... ?>`) remain executable PHP; directive-looking text inside their PHP strings or comments is not a SqueHub directive. A `{{ ... }}` expression in an HTML attribute still compiles and follows the same escaping rule.

HTML comments are removed during compilation as before, so directives and raw PHP inside them do not execute, even when a comment spans PHP lexer blocks. Their newline positions remain available for source-line diagnostics. Other template text unrelated to a recognized construct retains its spacing and line endings. LF and CRLF templates use the same syntax rules.

## Compiler errors and runtime data

The [View Diagnostics and Testing guide](ViewDiagnosticsTesting.md) describes structured `CompilerException` fields, logical dependency origins and cycle chains, runtime exception attribution, and production privacy. The compiler reports the original logical View and source line for structural errors; an arbitrary exception from trusted PHP preserves its cause but does not claim a mapped original source line. Do not treat a line in generated cache PHP as a `.squehub.php` source line.

An unmatched, unclosed, or directly nested `@error` block fails at compilation with the same logical View and line reporting as other structural directives. The field expression is trusted PHP evaluated only when the template renders. `@error` reads the current `$errors` bag, scopes its first message to `$message`, and restores a previous `$message` after the block. `@method` validates its runtime value through the same PUT/PATCH/DELETE allowlist used by the Request. Neither directive stores a visitor's state in compiled PHP.

An unterminated echo, unclosed directive argument list, malformed quoted argument, malformed include arity or static target, duplicate `@extends`, invalid section structure, mismatched asset block, unmatched component/slot ending, duplicate named slot, or invalid `@props` placement is a template compilation error. Development diagnostics identify the logical View and source line, so the source can be fixed without searching a compiled cache file. Production HTTP errors follow the application's safe exception boundary and do not expose absolute source or compiled-cache paths or template source. The compiler reports template structure; PHP still reports errors in otherwise valid PHP expressions when they execute. Circular layout, include, and component dependencies are detected during rendering through the normal View resolver.

Compilation runs from template source to normal PHP code without `eval()` or reparsing emitted PHP as template source. It emits code, not the values in the current render context. Shared values, request providers, composers, title, Auth and Session data, flash and old input, `$errors`, CSRF tokens, and Template Utility results are resolved during rendering. The compiled lifecycle separately frames that version, logical View name, source bytes, and compilation context in the artifact identity. The Application's `Storage/Views` directory holds immutable compiled artifacts. Content changes select a new artifact even with unchanged mtime or size; valid artifacts are reused, while recoverable damaged artifacts are rebuilt from safe source. `php squehub view:cache` precompiles without rendering and `view:clear` removes owned artifacts. `cache:clear` remains for application data. See [Compiled Views and Production Lifecycle](CompiledViews.md) for atomic publication and OPcache behavior.

Components are template-defined; there is no public component-class or form-builder DSL. `@auth`, `@guest`, `@can`, `@cannot`, and `@session` blocks accept `@else`, detect mismatched endings, and preserve source-aware diagnostics. Their conditions use current Auth, Authorization, and Session state at render time. Package View namespaces and [returnable View responses](ViewResponses.md) use the same renderer. A public custom-directive registration API remains outside the current scope.
