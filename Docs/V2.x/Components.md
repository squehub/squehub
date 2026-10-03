# Components, Props and Slots

A **partial** reuses surrounding View context and can receive a local data overlay. A **component** declares an interface: its template receives only its declared props, rendered slots, a separate HTML attribute bag, and justified framework bindings. This keeps a reusable interface clear without creating component classes or a second renderer.

Components are `.squehub.php` templates under the existing approved View roots. `@component('Card')` resolves the logical View `Components.Card`, normally `Project/Views/Components/Card.squehub.php`. `@component('Forms.Input')` resolves `Components.Forms.Input`. An enabled Package component may use `@component('Commerce::OrderCard')`; this resolves `Commerce::Components.OrderCard` through that Package's View namespace and optional application override. The same props, slots, and attributes contract applies. See [Package and Namespaced Views](PackageViews.md).

## Invoke a component

Use a paired invocation even when the component has no body:

```php
@component('Alert', ['type' => 'success'])
    Saved successfully.
@endcomponent

@component('Chart', ['report' => $report])
@endcomponent
```

The first argument is a quoted, static logical component name. The optional second argument is a props map; the optional third is a separate HTML attributes map. Expressions are evaluated once at the invocation, during rendering. A false surrounding `@if` does not evaluate them or render the component.

## Declare props

In `Project/Views/Components/Alert.squehub.php`:

```php
@props([
    'type',
    'dismissible' => false,
])

<div class="alert alert-{{ $type }}">
    {!! $slot->toHtml() !!}
</div>
```

A numeric entry declares a required prop. A named entry provides a default. The declaration is component-template-only, appears at most once at its top level, and must precede meaningful output. A component with no props may omit it. Missing required props, unknown supplied props, duplicate or invalid declarations, and reserved prop names fail clearly. Passing `null`, `false`, `0`, `''`, or `[]` explicitly keeps that value instead of selecting a default. Props are not silently converted or inferred from HTML attributes.

The component template does **not** inherit arbitrary caller values or ordinary shared View Context and composer values. A parent `$post` or `$secret` is unavailable unless declared and supplied as a prop. Framework-managed `$errors` and an active iterable `$loop` remain available. Neither can be replaced by a prop. A component may still use normal application helpers and services; it is trusted application PHP.

The [Forms and Validation UX](Forms.md) helpers also work here. A component can call `old()`, use `@error('email')` with its framework `$errors` bag, and render `{{ checked($condition) }}` or `{{ selected($condition) }}`. Values such as a current Model field must still be supplied as declared props. A form-related slot evaluates in the caller's scope, so its own `@error` block sees the caller's current ErrorBag binding without opening arbitrary caller locals to the component template.

## Default and named slots

Body content outside named slots becomes the default `$slot`. Named slots use a static quoted name:

```php
@component('Card', ['title' => 'Profile'])
    <p>{{ $user->name }}</p>

    @slot('footer')
        <button type="button">Close</button>
    @endslot
@endcomponent
```

The component receives an immutable default slot, even if empty, and a read-only `$slots` collection. Indentation around named slots alone counts as an empty default slot; otherwise slot whitespace is preserved as rendered by the caller. Its template can render them with:

```php
@props(['title'])

<article>
    <h2>{{ $title }}</h2>
    <div>{!! $slot->toHtml() !!}</div>

    @if ($slots->has('footer'))
        <footer>{!! $slots->get('footer')->toHtml() !!}</footer>
    @endif
</article>
```

`$slots->has('footer')` tests presence; `get('footer')` returns that slot when present; `all()` exposes a read-only snapshot. Duplicate named slots in one invocation, a slot outside a component, an unmatched `@endslot`, or an unclosed slot are structural errors. Named-slot content does not also appear in the default slot.

**Slots render in the caller's scope.** The body above can use `$user` even though the Card template cannot see `$user` unless it is a declared prop. Slot bodies run once during invocation. Calling `toHtml()` twice reuses already-rendered HTML; it does not rerun the slot's PHP. The slot object represents rendered template output, so emit `toHtml()` as trusted captured markup. Continue to use escaped `{{ ... }}` for untrusted values *inside* the slot body.

Conditions, loops, includes, and nested components work in slots under their normal rules. A slot inside a caller's `@foreach` sees the caller's `$loop`. The component template also receives the active framework `$loop`; an inner iterable loop in that template can use `$loop->parent`.

## HTML attributes

Pass HTML attributes separately from props:

```php
@component(
    'Button',
    ['label' => 'Save'],
    [
        'type' => 'submit',
        'class' => 'button-large',
        'data-action' => 'save',
    ]
)
@endcomponent
```

The component sees these through immutable `$attributes`:

```php
@props(['label'])

<button {!! $attributes->merge([
    'class' => 'button',
])->toHtml() !!}>
    {{ $label }}
</button>
```

`merge()` returns a new bag. Invocation attributes override same-named component defaults; Phase 14H does not concatenate CSS classes automatically. The bag provides `all()`, `has()`, `get()`, `only()`, `except()`, `merge()`, and `toHtml()`. It validates attribute names and escapes rendered values. Boolean `true` renders a bare attribute; `false` and `null` omit it. Strings, numbers, and `Stringable` values render as escaped quoted values. Unsupported arrays or objects fail rather than being emitted unsafely. Keep attribute values in `$attributes`; they do not become undeclared props.

Use `classes()` to build an explicit conditional class value. It returns plain text, which still belongs in escaped output or the escaping attribute bag:

```php
@props(['active' => false])

<button class="{{ classes(['button', 'button-active' => $active]) }}"
    {!! $attributes->except(['class'])->toHtml() !!}>
    {!! $slot->toHtml() !!}
</button>
```

The example deliberately excludes a caller-supplied `class` from the bag. If the component should accept that class, compose it explicitly before rendering; `merge()` does not combine class strings for you. See [Template Utilities](TemplateUtilities.md) for supported input shapes and errors. `@json($config)` can encode a declared prop for a script or attribute, but it does not turn a component prop into a global variable.

## Nested components and partials

Components can nest through their slot bodies. Each invocation owns its own props, slots, and attributes. A component can include a partial; the partial inherits that component's **resolved** local context, not the original caller's unrelated variables. A partial can invoke a component, but that component still receives only its declared props and framework bindings. Use [Includes and Reusable Partials](Includes.md) when inheritance is the desired behavior.

Components can render inside layouts, sections, conditions, loops, and captured asset blocks. A component does not declare its own parent layout or create an HTTP Response. A section containing a component captures it once; repeating a `@yield` reuses the captured output. These rules use the existing View render tree and compiler rather than a separate component lifecycle.

A [Fragment](Fragments.md) may contain a component, including its default/named slots and component-owned resources. Independent Fragment rendering invokes only components reached by that selected body. The component still receives declared props rather than arbitrary Fragment data; its slots keep their caller scope. Component templates cannot declare externally selectable Fragments, and the Fragment result is not an HTTP Response.

## Component-owned assets

A component owns the resources it declares:

```php
@props(['report'])

@style('/assets/components/chart.css', once: 'chart-style')
@script('/assets/components/chart.js', once: 'chart-runtime')

<div class="chart">{!! $slot->toHtml() !!}</div>
```

If `Chart` renders many times in a loop, direct resources and identical `once` declarations follow the existing [asset deduplication rules](Assets.md), so each qualifying stylesheet or script appears once in its stack. The component owner is `Components.Chart`. External registration can use `View::assets()->for('Components.Chart')`; it activates only if that component renders. Component participation is recorded before slot descendants so layout, page, component, and nested owner resources retain deterministic order. Assets written directly in caller slot content remain owned by the caller.

## Resolution, cycles, and failure safety

Components resolve through the same current, contained View roots as pages, layouts, and includes. An absent or disabled Package component, broken link, unsafe target, or outside-root symlink cannot execute through a stale compiled file. SqueHub tracks active canonical physical View identities, detecting component-to-component and mixed component/include cycles, including through aliases. Repeating a component after an earlier invocation completed is valid.

Compilation errors identify the logical View and source line in development. Missing props, unknown props, unsafe resolution, and cycles fail without printing caller props, slot content, secret values, or physical paths. Failed render state is discarded before a later render. Props, slots, and attributes are runtime values; compiled template PHP does not contain their request-specific values.

Phase 14H adds template components only. Phase 14I supplies the shared [form helpers](Forms.md), but no built-in form component library. Phase 14J [Auth, Authorization, and Session directives](ViewSecurity.md) work in component templates through the current Application/runtime context; they do not inject identities or all Session data as props. A caller-scope slot can use the same directives. Component templates participate in [compiled View warming and safe runtime invalidation](CompiledViews.md) under their normal `Components.*` logical names; warming does not execute props, slots, or render code. Component classes or lifecycle methods, typed prop schemas, scoped slots, `<x-...>` syntax, component aliases, and component-declared Fragments remain outside the current API. Explicit Package components use [Package and Namespaced Views](PackageViews.md). [View Diagnostics and Testing](ViewDiagnosticsTesting.md) covers missing component origins, logical cycle chains, and real-renderer component tests.
