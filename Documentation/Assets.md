# Template-owned Scripts, Styles and Asset Stacks

Templates declare the resources they need; layouts choose where those resources appear. This is part of SqueHub's core [View renderer](Views.md), using the same `.squehub.php` templates, logical View names, layouts, and includes. A declaration belongs to the page, layout, or included partial that actually executes it.

A full-page `View::response()` uses this same render and asset collection path. Its Response body contains the finalized layout HTML, including reached `@stack` output; resource declarations do not become HTTP headers or run again when the response is sent. See [Returnable View Responses](ViewResponses.md).

## Public asset URLs

**Use `asset()` for application-owned public assets. SqueHub automatically applies the selected Application's active `APP_BASE_PATH`.** Write the asset reference from the application's public URL root, such as `/assets/images/logo.png`, and keep that reference the same across deployments. In a `.squehub.php` View, use the helper in handwritten HTML:

```php
<link rel="icon"
      href="{{ asset('/assets/default/favicon/squehub-icon.png') }}"
      type="image/png">
<img src="{{ asset('/assets/default/img/squehub-icon.png') }}"
     alt="SqueHub logo" width="180" height="203">

<link rel="stylesheet" href="{{ asset('/assets/css/app.css') }}">
<script src="{{ asset('/assets/js/app.js') }}"></script>
<video src="{{ asset('/assets/media/intro.mp4') }}" controls></video>
<audio src="{{ asset('/assets/media/theme.mp3') }}" controls></audio>
<a href="{{ asset('/assets/files/manual.pdf') }}">Download manual</a>
```

The favicon and logo calls are the same ones used by the supplied `Project/Views/Home/Welcome.squehub.php`. For an ordinary public path, the helper builds a URL without looking up or copying a file. A deliberately selected logical build key can resolve through the verified production manifest, and a `PackageName::relative/file` reference resolves only while that Package is active. Those are explicit mappings; the ordinary public path contract remains unchanged. See [Frontend Assets and Modules](FrontendAssets.md) for those forms. The web server serves ordinary paths from `public/`.

Deploy each selected frontend build with its matching public files and manifest. Validate the resulting asset URLs on the target host.

For the unchanged call `asset('/assets/default/img/squehub-icon.png')`:

| `APP_BASE_PATH` | Result |
| --- | --- |
| Empty (root deployment) | `/assets/default/img/squehub-icon.png` |
| `/squehub-v2` | `/squehub-v2/assets/default/img/squehub-icon.png` |
| `/clients/acme` | `/clients/acme/assets/default/img/squehub-icon.png` |

`asset()` is the developer-facing URL resolver for handwritten local asset references. A named application route belongs in `route()`, not `asset()`. The View compiler does not rewrite a literal `<img src="/assets/...">` or other HTML attribute for a mounted deployment. The same Application-owned mapper now serves `asset()`, direct `@style`/`@script`, and externally registered View resources, so a selected production build mapping is consistent across these surfaces.

## Start with a layout

`Project/Views/Layouts/App.squehub.php`:

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <title>@yield('title', 'My App')</title>
    @stack('head')
    @stack('styles')
</head>
<body>
    @yield('content')
    @stack('scripts')
    @stack('scripts-after')
</body>
</html>
```

`Project/Views/Dashboard/Index.squehub.php`:

```php
@extends('Layouts.App')

@style('/assets/css/dashboard.css')
@script('/assets/js/dashboard.js')

@section('content')
    <h1>Dashboard</h1>
@endsection
```

Render the page with `View::render('Dashboard.Index')`. `@style` registers a stylesheet in `styles`; `@script` registers a script in `scripts`. Neither emits markup where it is declared. The layout's `@stack` locations place the resulting `<link rel="stylesheet" href="...">` and `<script src="..."></script>` tags. `head` and `scripts-after` are ordinary named stacks for additional content; use only the stacks a layout needs. An undefined stack emits nothing. If the final layout does not contain a requested stack, its entries are not inserted elsewhere.

With `APP_BASE_PATH=/app`, the application-owned root paths above render as `/app/assets/css/dashboard.css` and `/app/assets/js/dashboard.js`. Direct `@style`/`@script` declarations and externally registered View resources use the same `UrlBasePath` asset URL resolution as `asset()`; do not wrap a direct declaration in `asset()`. This applies to ordinary Project assets, Package resources, and Kit-published public assets when their URLs live under the application's public mount. An external HTTPS asset URL remains external. The asset stack does not serve files or copy Package assets; the web server must map the mounted public URL to a file under the deployed `public/` directory. See [subdirectory deployment](Deployment.md#subdirectory-mounts-and-shared-hosting).

Prefer a leading slash for an application-public-root input: `asset('/assets/app.css')`. The existing helper also accepts relative `asset('assets/app.css')`: at the root mount it keeps that browser-relative form, while under `/app` it produces `/app/assets/app.css`. An already mounted `/app/assets/app.css` is not prefixed again. Query strings and fragments stay attached to the public URL: under `/app`, `asset('/assets/app.css?v=42')` returns `/app/assets/app.css?v=42`, and `asset('/assets/icons.svg#logo')` returns `/app/assets/icons.svg#logo`. Absolute `http://` and `https://` URLs with a host, protocol-relative URLs with a host such as `//cdn.example.com/app.css`, `data:` and `blob:` URLs, and standalone query or fragment references are returned without a mount prefix. Malformed hostless forms such as `https:foo` and `//?asset=app.css` are rejected. Pass only application-authored, trusted external values; URL mounting does not validate a CDN or fetch its content.

Local references must have safe URL path segments. The resolver rejects empty values, control characters, malformed percent escapes, traversal segments (including encoded forms), encoded path separators, and backslash ambiguity. Unsupported schemes such as `javascript:` or `file:`, and Windows drive paths, are not asset references. A leading slash denotes a **public URL path**: `asset('/etc/passwd')` would name `/app/etc/passwd` under `/app`; it never reads the host's `/etc/passwd`. Use `asset()` with public references to files intentionally exposed by the application, not with server filesystem paths or untrusted URL values.

The shipped favicon manifest uses icon paths relative to its own manifest URL. If a page links the manifest through `asset('/assets/default/favicon/site.webmanifest')`, the browser can resolve its icons within the same root or mounted asset directory. A root-relative icon path inside the manifest would bypass a subdirectory mount.

Stack output is finalized after the render tree has collected its resources. A stack in `<head>` can therefore include a page or partial declaration encountered later while composing the body. A layout's own declaration also reaches a stack that appears earlier in its source. Repeating `@stack('styles')` emits the same finalized content at each location without executing declarations again.

## Owners, order, and deduplication

An included View owns its declarations:

```php
@include('Partials.Editor')
```

`Project/Views/Partials/Editor.squehub.php` can declare `@style('/assets/editor.css')` and `@script('/assets/editor.js')`. These are collected only when `Partials.Editor` actually renders. An absent `@includeOptional('Partials.Editor')`, a false `@includeWhen($showEditor, 'Partials.Editor')`, or a conditional branch that does not execute contributes nothing. The same applies to a denied `@can` or guest `@auth` branch around a component/partial: its assets are not collected. This is presentation behavior; protect the corresponding endpoint separately. Rendering the partial twice does not emit the same direct stylesheet or script twice, although its HTML executes twice. See [Includes and Reusable Partials](Includes.md) for include scope, failure, and cycle rules, and [Auth, Guards, Session and Authorization](ViewSecurity.md) for View security presentation.

The same ownership rule applies to [components](Components.md), layouts, and enabled Package Views. A disabled Package does not render a View or contribute its boot-time registrations. Section bodies execute when captured, so a declaration inside a captured section is collected even if the layout never yields that section. Collection follows execution; it does not predict which sections will later be displayed.

Namespaced Package owners use their full logical identity, such as `Commerce::Orders.Index`. `View::assets()->for('Commerce::Orders.Index')->script('/commerce/orders.js')` belongs only to that View; an ordinary or another Package's `Orders.Index` does not inherit it. The regular deduplication and ordering rules still apply. See [Package and Namespaced Views](PackageViews.md).

Resource declarations may be conditional on the selected Application environment. For example, choose a minified script in `@if (environment('production'))` and a development script in `@else`. The condition is evaluated at render time. `debugging()` similarly reflects the selected Application's current debug setting; neither helper exposes configuration secrets. See [Template Utilities](TemplateUtilities.md) for both helpers.

Within a stack, normal entries follow a deterministic owner order: outermost base layout, intermediate layouts, selected page, then included and component descendants in stable first-participation order. A component reserves its owner position before rendering nested slot descendants. Declarations within one owner retain their order. Assets registered externally for an owner come before that owner's template declarations. If several owners declare the same direct stylesheet or script, its earliest owner in this order determines the emitted position.

Direct `@style` and `@script` resources are deduplicated by resource kind, URL, and target stack. A page and its partial may both declare `@style('/assets/shared.css')`; the `styles` stack emits one link. Different query strings such as `/assets/app.js?v=1` and `/assets/app.js?v=2` remain distinct. Styles and scripts are separate resource kinds.

For example, `Components.Chart` may declare `@style('/assets/chart.css', once: 'chart-style')`. Rendering Chart repeatedly in a loop still emits that resource once. `View::assets()->for('Components.Chart')->script('/assets/chart.js')` activates only when Chart participates. Assets declared directly in a component's caller-scope slot remain owned by the caller template; a nested component or partial in that slot owns its own declarations.

## Push and prepend rendered blocks

For structured script data, use `@json($config)` rather than hand-building JavaScript literals:

```php
@push('scripts')
    <script>window.pageConfig = @json($config);</script>
@endpush
```

The JSON encoder escapes HTML-significant characters, including a closing script tag in string data. It is available in normal `<script>` code, while directive-looking text inside JavaScript strings, comments, and template literals remains literal. See [Template Utilities](TemplateUtilities.md) for the encoder contract and failure behavior.

Use `@push` for markup that needs more than a plain stylesheet or script URL:

```php
@push('head')
    <link rel="preconnect" href="https://example.test">
@endpush
```

Use `@prepend` to place a block before normal entries in that stack:

```php
@prepend('scripts')
    <script src="{{ asset('/assets/polyfill.js') }}"></script>
@endprepend
```

`@push` and `@prepend` capture ordinary trusted markup. They do not scan or rewrite literal HTML attributes, so use the `asset()` helper inside handwritten tags when a root-relative local URL should follow the application mount. The helper returns `/assets/polyfill.js` at root and `/app/assets/polyfill.js` under `/app`.

Prepend blocks retain their registration order, followed by normal entries in owner order. A block body executes and is captured once at its declaration site; each `@stack` output reuses that rendered result. Conditions, loops, includes, escaped `{{ ... }}`, and explicit raw `{!! ... !!}` work inside a block under their normal template rules. Arbitrary push and prepend bodies are **not** automatically deduplicated, even if their markup is identical.

To deduplicate a repeated declaration deliberately, give it a `once` key:

```php
@script('/assets/chart.js', once: 'chart-runtime')

@push('head', once: 'analytics-preconnect')
    <link rel="preconnect" href="https://example.test">
@endpush
```

`@style`, `@script`, `@push`, and `@prepend` accept a one-time key. Identical declarations using the same key emit once in one top-level render; using that key for conflicting content is an error. The same key is available again in a later render or request. Direct resource deduplication also works without a `once` key; a key supplies explicit identity for repeated resources or blocks.

Stack names in `@stack`, `@push`, and `@prepend` are quoted static names, such as `head`, `scripts-after`, or `vendor.scripts`. Blocks must close with their matching `@endpush` or `@endprepend`; nested push/prepend captures are invalid. Structural errors report the logical View and source line in development.

## Register assets outside a template

Application setup or an enabled Package may associate resources with a logical View:

```php
use App\Plugins\View;

View::assets()
    ->for('Dashboard.Index')
    ->style('/assets/css/dashboard.css')
    ->script('/assets/js/dashboard.js');
```

For a resource shared by several possible owners:

```php
View::assets()
    ->for(['Reports.Index', 'Reports.Show'])
    ->script('/assets/js/reports.js');
```

External direct declarations may also use a one-time key, such as `->script('/assets/js/chart.js', once: 'chart-runtime')`.

Registration belongs to the selected Application. It does not mean emission: `Dashboard.Index` must participate in the current render tree for its registration to activate. Multiple-owner registration activates when any named owner renders and still emits once. Use the exact logical View spelling used by `View::render()`, `@include`, or a component owner such as `Components.Chart`; ownership identifiers are not filesystem paths. An application-wide resource belongs naturally in its base layout, rather than in an unowned global registration.

The external registry is Application configuration. Each render snapshots its registrations when it begins, so a registration added during a render first appears in the next render. Collected resources, owner participation, pushes, and `once` keys belong to one top-level render. Separate renders in one request, later requests, and other Application instances start with independent collection state. A failed render releases that state. [Shared View Context](ViewContext.md) data remains separate; ordinary context values can supply runtime asset URL expressions without being embedded in compiled PHP.

## Resources in independently rendered Fragments

`@fragment('orders.list') ... @endfragment` is transparent during a full page render. During `View::fragment('Orders.Index', 'orders.list', $data)`, only the selected body and the Partials/Components it actually renders contribute template-declared resources. A sibling Fragment, surrounding page declaration, or skipped Layout contributes none. Root-owned external registrations such as `View::assets()->for('Orders.Index')` participate because the requested root View participates; Layout-owned registrations do not. Keep resources needed independently inside the Fragment or one of its rendered descendants. See [Fragments and Partial Responses](Fragments.md).

The immutable Fragment result exposes finalized named stacks with `stack('styles')`, `stack('scripts')`, and `stacks()` for custom names. Deduplication, `once` conflicts, prepend order, and nested ownership remain the ordinary rules above. Reading a stack twice does not rerun declarations. The application decides how to transport the Fragment HTML and stack markup; SqueHub supplies no automatic client-side loader or AJAX response protocol.

## Safety and frontend boundary

`@style($themeCss)` and `@script($dashboardScript)` accept trusted runtime PHP expressions. Each declaration evaluates its expression when executed, not when compiled. URLs must be nonempty strings or `Stringable` values without control characters. SqueHub HTML-escapes the URL when inserting it into `href` or `src`; this prevents an attribute value from breaking the generated markup. It does not fetch the resource or turn an arbitrary URL into a local file. Absolute external URLs remain unchanged; application-owned root-relative paths receive the configured base path once. Asset paths are URLs, not source-file lookups. The `asset()` helper likewise builds a public mount-aware path from an application-owned local path, not an external CDN URL.

Push/prepend blocks are normal trusted template output. `{{ $value }}` inside a block remains escaped, `{!! $trusted !!}` remains raw, and the captured HTML is not escaped again when the stack emits it. Keep untrusted values in the escaped form. Production errors use the normal safe HTTP boundary and do not publish template source, render data, or physical paths.

The existing `@style`, `@script`, `@push`, and `@stack` surface still composes resource references at render time and requires no Node.js. `@script` continues to emit a classic script. The optional [`@frontend` entry](FrontendAssets.md) uses the same render-local stacks for native ES modules or a selected Vite build; no compiler rewrites literal `<head>` or `<body>` HTML. [Components](Components.md) use this ownership model: a component's declarations belong to `Components.<Name>` and activate only when that component renders. Assets written directly in a caller-scope slot remain owned by the caller.

Application tests can inspect the same finalized named stacks for a full View or selected Fragment with `ViewTestResult::stack()`, `assertStackContains()`, and `assertStackMissing()`. The test helper does not run a second asset collector. See [View Diagnostics and Testing](ViewDiagnosticsTesting.md). [Compiled View warming](CompiledViews.md) stores resource directives as instructions only; no stack is filled or external asset registration activated until a real render participates.
