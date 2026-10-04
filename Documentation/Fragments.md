# Fragments and Partial Responses

A **Fragment** is an explicitly named, independently renderable boundary inside a `.squehub.php` View. Use it when one page also needs to supply just one region for an application-selected partial response. A normal page render still includes the Fragment body at its declaration position.

Fragments are part of the existing [View renderer](Views.md). They use its context, escaping, components, includes, form state, security presentation, and asset collection. SqueHub does not inspect AJAX headers or choose a response format for the application.

A namespaced root View can expose a local Fragment, for example `View::fragment('Commerce::Orders.Index', 'orders.list')`. The full namespaced View name selects the active Package source or safe application override; `orders.list` remains a Fragment identifier inside that one root View. See [Package and Namespaced Views](PackageViews.md).

| Construct | Purpose |
| --- | --- |
| Partial | Reuse another View file with inherited context. |
| Component | Reuse UI behind declared props, slots, and HTML attributes. |
| Section | Supply content to a layout's `@yield`. |
| Fragment | Give one region of the explicitly requested root View a stable name for independent rendering. |

## Declare a Fragment

Create `Project/Views/Orders/Index.squehub.php`:

```php
@extends('Layouts.App')

@section('content')
    <h1>Orders</h1>

    @fragment('orders.list')
        <div id="orders-list">
            @forelse ($orders as $order)
                @component('OrderCard', ['order' => $order])
                @endcomponent
            @empty
                <p>No orders.</p>
            @endforelse
        </div>

        @script('/assets/orders/list.js', once: 'orders-list')
    @endfragment
@endsection
```

The declaration name is quoted and static. It identifies a region **within `Orders.Index`**, not a file or route. Another root View may also declare `orders.list`. Names are exact and case-sensitive on every platform. Each dot-separated segment may contain ASCII letters, digits, `_`, or `-`; empty segments, controls, slashes, and filesystem traversal are invalid. Duplicate names in one root View fail instead of selecting a first or last declaration.

A Fragment may be at the root of the requested View or directly inside one of that View's sections. Its body may contain conditions, loops, partials, components, slots, forms, resources, and the normal runtime helpers. Declarations themselves are deliberately static: do not place `@fragment` inside a condition, loop, component, slot, asset block, or another Fragment. Keep declarations outside raw PHP control-flow wrappers too; the structural compiler validates SqueHub directives, not arbitrary PHP branches around HTML. Layout, included partial, and component templates do not declare externally selectable Fragments. Use a Partial or Component to reuse UI inside a Fragment.

The compiler checks `@fragment`/`@endfragment` structure and reports mismatches, nesting, duplicate or invalid names, and invalid placement with the logical View and source line in development. `@fragmented` and other longer unknown names remain literal text. Directive-looking text in protected PHP, HTML comment, CSS, and JavaScript contexts follows the existing [scanner rules](TemplateCompiler.md).

## Render the whole page or one Fragment

The established full-page API is unchanged:

```php
use App\Plugins\View;

View::render('Orders.Index', ['orders' => $orders]);
```

`View::render()` writes the complete page through the normal layout. The Fragment body appears where it was declared, after the page's `<h1>` in this example. It executes once, like ordinary section content.

To render only that region, call one explicit operation:

```php
$fragment = View::fragment(
    'Orders.Index',
    'orders.list',
    ['orders' => $orders]
);

$html = $fragment->html();
$scripts = $fragment->stack('scripts');
```

`View::fragment()` returns an immutable `App\Plugins\FragmentRenderResult`. Its `html()` contains only the selected body. It does **not** render the surrounding heading, section siblings, or layout HTML. The Layout's composer and layout-owned template assets do not run. Selection is exact and case-sensitive. A valid but absent requested name raises `App\Plugins\FragmentNotFoundException` without a fabricated template source line; an invalid public name raises `App\Plugins\InvalidFragmentNameException` before lookup and does not echo the name. A missing root View raises `App\Plugins\ViewNotFoundException`. A malformed `@fragment` declaration remains a source-aware `App\Plugins\CompilerException`. These Plugins names are exact aliases of their canonical exception classes. See [View Diagnostics and Testing](ViewDiagnosticsTesting.md).

The selected Fragment executes once. Compilation discovers its boundary without executing sibling PHP, sibling includes or components, other Fragments, or unrelated resource declarations. For example, an exception or expensive query in sibling template code is not evaluated to obtain the selected Fragment. Full and independent renders use the same Fragment-local data rule, so a Fragment should not depend on a PHP local assigned earlier outside its boundary.

## Data and composition

An independent Fragment render resolves the requested **root View's** normal data: Application shared values, request provider values, the root View composer, explicit render data, and framework-managed bindings. Explicit data wins ordinary name conflicts. The root composer runs once for the render; a layout composer does not run because the layout is skipped. Request providers retain their established once-per-request behavior. `$errors`, active `$loop`, and reserved framework names keep their normal protections. See [Shared View Context](ViewContext.md).

Create values needed by the Fragment inside its body, or pass them through root View data. Do not write this:

```php
@php
    $total = count($orders);
@endphp

@fragment('orders.list')
    {{ $total }}
@endfragment
```

Instead, compute `$total` inside the Fragment or pass it as explicit View data. That makes full and independent rendering agree without running unrelated code.

An `@include` inside the selected Fragment inherits its active context and uses normal local overlay/composer rules. A Component still sees only declared props, rendered slots, attributes, and framework bindings; Fragment selection does not open its scope. Slots run in their caller's scope. Loops retain the normal read-only `$loop` metadata and restoration rules. Existing include, layout, and component cycle checks remain in force.

## Assets in a partial render

The result exposes finalized named stacks collected from content that actually participated:

```php
$fragment->html();                 // Rendered body HTML.
$fragment->hasStack('styles');     // Whether declarations contributed.
$fragment->stack('styles');        // Finalized HTML, or '' for a valid empty stack.
$fragment->stack('scripts');
$fragment->stacks();               // Map of participating stack names to HTML.
```

`stacks()` includes custom stack names, not only `styles` and `scripts`. An invalid stack name follows the existing asset validation error; a valid undeclared stack has empty output. Repeated reads return the same finalized HTML without rerunning blocks. Direct styles and scripts, `once` keys, prepend order, nested component ownership, and deduplication follow the [asset-stack rules](Assets.md).

Put a Fragment's required `@style`, `@script`, `@push`, or `@prepend` inside the Fragment or a Partial/Component it renders. Root View external asset registrations made with `View::assets()->for('Orders.Index')` participate because that root View participates. Layout-owned registrations and declarations do not participate when the layout is skipped. Resources in sibling template code or another Fragment are absent. Every top-level Fragment render has independent asset state; a later page or Fragment render starts clean.

The result provides resource markup, not a client-side loader. An endpoint returning only `$fragment->html()` does not automatically deliver its stacks. The application decides whether to include stack markup in an HTML response or send it separately to its own client code. SqueHub does not prescribe a JSON envelope, DOM replacement method, or navigation library.

## Explicit HTTP transport

The View result is **not** an HTTP `Response`. A controller or route chooses how to send it. These independent endpoints are examples; SqueHub creates no Fragment URLs automatically:

```php
use App\Plugins\{JsonResponse, Response, Route, View};

Route::path('/orders')->get(static function (): void {
    View::render('Orders.Index', ['orders' => []]);
});

Route::path('/orders/list')->get(static function (): Response {
    $fragment = View::fragment('Orders.Index', 'orders.list', ['orders' => []]);

    return new Response(
        $fragment->html(),
        200,
        ['Content-Type' => 'text/html; charset=UTF-8']
    );
});

Route::path('/orders/list-data')->get(static function (): JsonResponse {
    $fragment = View::fragment('Orders.Index', 'orders.list', ['orders' => []]);

    return new JsonResponse([
        'html' => $fragment->html(),
        'stacks' => $fragment->stacks(),
    ]);
});
```

The JSON shape above is an application choice, not a framework protocol. SqueHub does not automatically switch to Fragment rendering based on `X-Requested-With`, `Accept`, HTMX/Turbo headers, query parameters, or Fetch. A route can choose full HTML, Fragment HTML, JSON, or another response using normal controller logic. `View::response()` returns a [complete full-page View response](ViewResponses.md), not the selected Fragment; Fragment transport remains an explicit application choice.

## Forms and security state

Fragments read the current request's ordinary `@auth`, `@guest`, `@can`, `@cannot`, and `@session` state at render time. A compiled Fragment does not store an identity, ability decision, or Session value. Unrendered security branches do not run nested components or collect their assets. Presentation conditions never replace route middleware or server-side authorization. See [Auth, Guards, Session and Authorization](ViewSecurity.md).

Forms inside Fragments use the same `$errors`, `@error`, `old()`, `@csrf`, `@method`, `checked()`, and `selected()` behavior as full pages. This supports an explicitly selected form/error region after a validation failure without creating another validation system. Keep CSRF enforcement and Request validation at the normal request boundary. Escape submitted values and error messages with `{{ ... }}`. The `json()`/`@json`, `classes()`, `environment()`, and `debugging()` utilities also evaluate for the current render and Application.

## Failures and boundaries

A failed Fragment render discards partial HTML and releases its output buffers, asset stacks, `once` keys, include/component chains, and selection state. A later render must start clean. Production HTTP errors use the established safe exception boundary; they do not disclose source or cache paths, Session data, identity state, or partly rendered markup. Compiled metadata contains static Fragment names and instructions, never request values. The compiler cache changes with Fragment semantics; values and security decisions remain runtime data.

One `View::fragment()` call selects one Fragment. There is no dynamic Fragment name expression, nested Fragment, multi-Fragment batch response, Fragment route registration, automatic AJAX behavior, or JavaScript dependency. Package Views continue through the existing enabled-Package resolution and containment rules; explicit namespaced roots use [Package and Namespaced Views](PackageViews.md). Test selected HTML and finalized stacks with `$this->fragment('Orders.Index', 'orders.list', $data)` and the same assertions used for a full `$this->view(...)`; see [View Diagnostics and Testing](ViewDiagnosticsTesting.md). Fragment-only rendering uses the same atomic compiled artifacts and source invalidation as a full View, with no separate Fragment cache; see [Compiled Views and Production Lifecycle](CompiledViews.md). The [returnable View response](ViewResponses.md) API covers complete pages only.
