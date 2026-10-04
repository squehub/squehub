# Template Utilities

Template Utilities are a small part of SqueHub's core [View renderer](Views.md). They help with output that is repetitive or easy to get wrong while keeping ordinary PHP expressions and the existing escaped `{{ ... }}` syntax. They introduce no separate service, configuration, frontend dependency, or CLI command.

| API | Use |
| --- | --- |
| `json($value)` | Encode one value as JSON with mandatory HTML-dangerous character protection. |
| `@json($value)` | Emit that encoded JSON directly in a JavaScript or JSON text position. |
| `classes($entries)` | Compose a plain class-attribute value from ordered, conditional entries. |
| `environment('local', 'testing')` | Check the selected Application's environment against one or more names. |
| `debugging()` | Read the selected Application's current debug boolean. |
| `checked($condition)`, `selected($condition)` | Fixed boolean HTML attribute fragments. |

SqueHub prefers a normal `@if` or PHP expression over a new directive for every presentation condition. These helpers do not change routing, authorization, Session state, or template escaping.

## JSON for scripts and HTML attributes

Use `@json` for a JavaScript value in a trusted `.squehub.php` template:

```php
<script>
    const pageData = @json($pageData);
</script>
```

The directive evaluates `$pageData` once **when the View renders**, calls the same encoder as `json()`, and emits the JSON result without a second HTML-entity escape. It is deliberately narrow raw JSON output, not a shortcut for arbitrary HTML. A skipped conditional branch does not evaluate the expression:

```php
@if ($showChart)
    <script>const chart = @json(buildChartData());</script>
@endif
```

The encoder requires `JSON_THROW_ON_ERROR`, `JSON_HEX_TAG`, `JSON_HEX_AMP`, `JSON_HEX_APOS`, and `JSON_HEX_QUOT`. It also preserves readable Unicode and slashes. The tag flags encode `<` and `>` as JSON escapes, so a value such as `</script><script>alert(1)</script>` cannot close the surrounding script element. Quotes and ampersands remain valid JSON data. Unicode text such as `Ẹ káàbọ̀`, `こんにちは`, `مرحبا`, and emoji remains valid JSON. Normal PHP JSON behavior applies to scalars, arrays, `JsonSerializable` values, and supported objects; SqueHub does not add an implicit Model serializer.

For a **quoted HTML attribute**, keep ordinary View escaping around the JSON helper:

```php
<div data-config="{{ json($pageData) }}"></div>
```

The JSON helper encodes the data; `{{ ... }}` escapes the resulting text for the HTML attribute. `@json` is intended for JavaScript values and JSON text, not for every HTML position. Neither form is a URL sanitizer or a general HTML sanitizer. Continue using `{{ ... }}` for untrusted HTML text and quoted attribute values, and use `{!! ... !!}` only for deliberately trusted markup.

Encoding a recursive structure, unsupported value, invalid UTF-8, `INF`, or `NAN` raises a JSON error instead of silently outputting `null` or partial data. The production HTTP error boundary remains generic and does not print the value that failed encoding. Do not place secrets in page data: safe encoding prevents markup breakout but does not make delivered data private.

`@json(...)` accepts the compiler's balanced, multiline expression syntax:

```php
@json(
    [
        'user' => $userData,
        'settings' => $settings,
    ]
)
```

A bare `@json`, empty `@json()`, or unmatched delimiter is a compiler error with the logical View and source line. Directive-looking text in PHP strings/comments, protected JavaScript/CSS strings, and HTML comments retains the existing [scanner rules](TemplateCompiler.md). `@jsonData` and `@jsonify` remain ordinary unknown text. The compiler stores the call, never a previous request's JSON result.

## Compose HTML classes

Use `classes()` inside a normally escaped attribute:

```php
<div class="{{ classes([
    'alert',
    'alert-error' => $hasError,
    'alert-dismissible' => $dismissible,
    $additionalClasses,
]) }}">
    {{ $message }}
</div>
```

The helper accepts an array. A numeric-key string or `Stringable` value is included after trimming outer whitespace; numeric-key `null` or `false` is omitted. A string key is included when its value is `true`; `false` or `null` omits it. Nested **numeric-key** arrays are flattened. Other associative values are invalid. Entries keep encounter order, and an identical whole entry is emitted once at its first position. It does **not** split or rewrite internal whitespace in a developer's class string. It joins included entries with one space and returns ordinary plain text, including `''` for an empty result.

Pass deliberate booleans for named conditions. Unsupported values, such as resources and non-`Stringable` objects, fail clearly rather than becoming `Array` or an object name. An error does not dump the invalid value. `classes()` composes application-supplied CSS names; it does not validate or sanitize an application's CSS vocabulary. HTML containment comes from `{{ ... }}`. For example, a class value containing `" onmouseover="secret` remains inside the quoted attribute when rendered through escaped output.

The helper works in [components](Components.md) without changing `ComponentAttributeBag`:

```php
@props(['featured' => false])

<article class="{{ classes([
    'card',
    'card-featured' => $featured,
]) }}" {!! $attributes->except(['class'])->toHtml() !!}>
    {!! $slot->toHtml() !!}
</article>
```

If the component needs caller and default classes in **one** attribute, explicitly compose them before passing them to `$attributes->merge(['class' => classes([...])])`. `merge()` retains its existing override semantics and does not concatenate classes automatically. The attribute bag still validates names and escapes values when its HTML is rendered.

## Checked and selected state

The form helpers supply `checked()` and `selected()`. They require a boolean and return only the fixed `checked` or `selected` fragment, or `''` when false:

```php
<input type="checkbox" {{ checked($enabled) }}>
<option value="ng" {{ selected($country === 'ng') }}>Nigeria</option>
```

The helpers do not compare form values, bind Models, or replace [validation and old input](Forms.md). Normalize browser input and make the condition explicit. No parallel `@checked`, `@selected`, or family of new boolean-attribute directives is introduced.

## Environment and debug presentation

The environment and debug helpers read the **currently selected Application**. They do not read `$_ENV` in a template, reparse `.env`, hold process-global state, or expose arbitrary configuration:

```php
@if (environment('local', 'testing'))
    <small>Preview environment</small>
@endif

@if (debugging())
    <small>Debug mode is enabled</small>
@endif
```

`environment(...$names)` returns `true` when the Application's environment name strictly matches one supplied name. It requires at least one name. `debugging()` returns the Application's current debug boolean. The existing `Application::environment()` returns the actual name when application code needs it; these helpers only answer presentation questions. They do not enable debug mode or expose the environment file, Session, secrets, or diagnostics.

The same template can render in a local Application with debug enabled and a production Application with debug disabled without recompilation or cross-Application leakage. A missing Application context fails through the established runtime boundary rather than inventing a value. `environment()` and `debugging()` belong in ordinary conditionals; there are no `@env`, `@debug`, `@dump`, or `@dd` directives.

Presentation is not a deployment or security policy. Do not rely on a hidden link, button, or debug label to protect an endpoint. Keep route middleware, server-side authorization, and production debug configuration authoritative. See [Application](Application.md), [Configuration](Configuration.md), and [Auth, Guards, Session and Authorization](ViewSecurity.md).

## Composition, assets, and render lifetime

Utilities are ordinary runtime calls in pages, [partials](Includes.md), [layouts](Layouts.md), [components](Components.md), slots, loops, and conditional branches. A component receives only its declared props and framework bindings; calling a helper does not inject arbitrary caller variables. For script data in a component, explicitly pass a JSON-encodable prop and use `@json($config)` there.

The same utilities work in an independently rendered [Fragment](Fragments.md). `@json($config)` safely emits current JSON inside that selected body, `classes()` still requires escaped `{{ ... }}` for an HTML attribute, and `environment()`/`debugging()` read the selected Application at render time. A compiled Fragment does not retain a prior request's JSON, class conditions, or debug state. Fragment selection adds no new output-escaping mode.

An environment condition can control template-owned assets without changing their owner or ordering:

```php
@if (environment('local'))
    @script('/assets/dev-tools.js')
@endif
```

The asset is collected only when that branch executes. A `@push('scripts')` block may contain `@json($config)` inside its script markup; its normal capture and render lifecycle applies. See [Template-owned Scripts, Styles and Asset Stacks](Assets.md).

The compiler caches PHP instructions while JSON data, classes, form state, environment, and debug state are evaluated for each render and Application. No generated value is reused from a previous request. `view:cache` precompiles instructions without evaluating these utilities. Test escaped output through the real `$this->view(...)` or `$this->fragment(...)` helper and `assertSeeEscaped()`; see [View Diagnostics and Testing](ViewDiagnosticsTesting.md). Package namespace resolution uses the same [compiled View lifecycle](CompiledViews.md), and [returnable View responses](ViewResponses.md) evaluate utilities during their own render.
