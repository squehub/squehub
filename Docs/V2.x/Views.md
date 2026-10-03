# Views in SqueHub v2

Application templates live in `Project/Views`. Published package templates live in `Project/PackagesViews`. Both use the existing `.squehub.php` extension and dot-separated logical names: `Home.Welcome` resolves to `Home/Welcome.squehub.php` under the first matching directory. A [SqueHub Kit](Kits.md) may publish an application view to `Project/Views`; it then follows the same resolver and remains on disk when the Kit is disabled. An enabled Package may also expose an explicit name such as `Commerce::Orders.Index`; see [Package and Namespaced Views](PackageViews.md).

For an unqualified name, the resolver searches in this order:

1. `Project/Views`
2. `Project/PackagesViews`
3. `Project/Packages/<PackageName>/Views` for each enabled Package

This preserves the established unqualified lookup. Namespaced `Commerce::Orders.Index` instead requires the active `Commerce` Package, checks `Project/PackagesViews/Commerce/Orders/Index.squehub.php` as an explicit application override, then uses that Package's own `Views/Orders/Index.squehub.php`. A disabled Package's bundled views are absent from resolution and its namespaced override cannot activate it. Directory capitalization is part of the convention and matters on case-sensitive hosting. Application and published view roots retain their first-letter compatibility variants when the application has one unambiguous project root. Do not keep separate `Project/` and `project/` trees in the same application; the [Activation Registry](ActivationRegistry.md) rejects that collision. Managed Package activation uses only the exact `Project/Packages/<PackageName>/` layout; older physical casing variants are not auto-enabled. See [Packages](Packages.md). Each local logical name segment also accepts a first-letter case variation, so `home.welcome` continues to find `Home/Welcome.squehub.php`; Package namespace tokens use exact case on every platform. The repository-root `views`/`Views` directory is no longer searched.

The application-facing rendering API includes `App\Plugins\View::render('Home.Welcome', ['name' => 'Ada'])` and the returnable `View::response('Home.Welcome', ['name' => 'Ada'])`. In an unnamespaced route file, `View` works without a `use` statement. Both delegate to the existing `App\Core\View` implementation and use the same full renderer. `render()` writes the rendered template to the output buffer; the HTTP dispatcher captures that output. `response()` captures HTML without echoing and returns an ordinary HTTP Response with status and headers. See [Returnable View Responses](ViewResponses.md). Existing `@include`, `@extends`, sections, and compiled View reuse continue to work. Compiled artifacts belong to the Application under `Storage/Views`, separate from the [application data cache](Cache.md). See [Compiled Views and Production Lifecycle](CompiledViews.md) for warming, invalidation, and clearing.

`View::findViewFile()` still searches the same directories for raw `.php` views used by older code. A missing `.squehub.php` View now raises a logical missing-View diagnostic, whether selected through full rendering or Fragment rendering. Required includes, layouts, and components also fail with a logical dependency name and source origin where known. See [View Diagnostics and Testing](ViewDiagnosticsTesting.md).

View resolution checks each candidate's canonical filesystem target against its approved View root. A file link whose target remains inside that root can render; a broken link or a link outside the root is unavailable and cannot supply compiled PHP. An unavailable top-level View cannot expose the external target or its path. Successful `View::render()` calls write output during rendering; a failed resolution raises `ViewNotFoundException` without emitting partial content. Phase 14M deliberately replaced the earlier direct-render return of error markup: throwing lets the HTTP exception handler assign status 500 and keep production responses generic. The rejection and containment rules did not change.

The legacy `Mail` class selects its own email templates and layouts, with its existing custom path option. It accepts first-letter case variations in each template path segment. It does not use the general View resolver or `Project/PackagesViews`.

## Shared View Context

Phase 14A adds Application-owned context through `App\Plugins\View::share()`, `provide()`, and `compose()`. Stable shared values, lazy request values, and data for one exact logical View can be registered without repeating them in every controller. Explicit `View::render($name, $data)` data has the highest ordinary precedence; the framework-owned `$errors` bag always wins its reserved name.

Providers run at first View use once per HTTP request, then reset before the next request. Composers run each time their named View renders, including a matching layout or partial. Rendering outside HTTP gives providers a null Request and keeps their result only for that render tree. Register request-dependent Auth, Session, flash, and CSRF presentation through providers, not shared values. See the [Shared View Context guide](ViewContext.md) for class and closure examples, nested include precedence, name validation, and Application isolation.

## Escaped and raw output

`@translate('messages.welcome', ['name' => $name])` reads the current Application's JSON translation catalog and escapes the resulting text. Locale selection is explicit and request-scoped; translated values are never trusted HTML. See [Internationalization](Internationalization.md) for catalogs, fallback, plural rules, and Package overrides.

`{{ $value }}` escapes HTML using UTF-8 and `ENT_QUOTES | ENT_SUBSTITUTE`. It is suitable for HTML text and quoted attribute values. The old `@echo($value)` shorthand now follows the same escaped policy. Null and false render as an empty string; true renders as `1`. Scalars and `Stringable` objects are accepted. Arrays, resources, and other objects cause `ViewException`. Invalid UTF-8 is replaced with the Unicode replacement character.

For data embedded as a JavaScript value, `@json($pageData)` emits JSON encoded with mandatory HTML-dangerous-character protection. For a quoted HTML attribute, use `{{ json($pageData) }}` so normal HTML escaping still surrounds the encoded value. `classes([...])` returns ordinary text for `class="{{ classes([...]) }}"`; it never grants raw-output trust. The [Template Utilities guide](TemplateUtilities.md) explains these distinct output contexts, class composition, environment/debug checks, and the existing `checked()`/`selected()` helpers.

`{!! $trustedHtml !!}` is the explicit raw output syntax. It bypasses XSS protection and must receive only trusted or carefully sanitized HTML. Values are not escaped in storage; output escaping happens at render time. Templates that relied on raw v1 `{{ }}` output should migrate trusted fragments to `{!! !!}`. HTML escaping does not make untrusted URL schemes or JavaScript/CSS contexts safe.

Compiled view filenames include a compiler version, so old raw-output PHP is not reused after this change. The same output rules apply to layouts, includes, application views, published package views, and bundled package views. The legacy `Mail` renderer remains separate.

The framework supplies a reserved `$errors` variable as an `App\Plugins\ErrorBag` on every render, including when empty. Controller, shared, provider, and composer data named `errors` cannot replace it. Use `@error('email') ... @enderror` for a field message, `$errors->all()` for an ordered summary, and `{{ old('email', '') }}` for an escaped old value. `@csrf` emits the configured hidden field at render time; `@method('PATCH')` adds the form method field for a PATCH route. See [Forms and Validation UX](Forms.md) for a complete example.

## Reliable template compilation

The Phase 14B compiler accepts multiline escaped or raw echo expressions and nested directive arguments. Quoted strings may contain commas, parentheses, or text resembling an echo closing delimiter. For example, this include passes a nested expression and an array without splitting at the inner commas or parentheses:

```php
@include(
    'Partials.Card',
    [
        'title' => strtoupper(trim($title)),
        'post' => $post,
        'label' => 'Open (details), now',
    ]
)
```

The same structure works in existing `@extends` and `@yield` arguments where those directives accept arguments. View and section names remain quoted, static names under the existing rules. `{{ ... }}` still escapes HTML, and `{!! ... !!}` still emits raw trusted output. Malformed template constructs identify the logical View and source line in development; production responses use the application's safe error boundary. See [Reliable Template Compiler](TemplateCompiler.md) for the compiler contract and diagnostics.

## Render a view

Create `Project/Views/Home/Welcome.squehub.php`:

```html
<h1>Welcome, {{ $name }}</h1>
<p>Today is @date.</p>
```

Render it from `Project/Routes/Web.php`:

```php
<?php

use App\Plugins\{Route, View};

Route::path('/welcome')->get(function (): void {
    View::render('Home.Welcome', ['name' => 'Ada']);
});
```

`View::render()` echoes the compiled template; it does not return a Response. The HTTP dispatcher captures output from a route or controller. In a controller, call it from a `void` action. To return a full HTML page with HTTP status and headers, use `return View::response('Home.Welcome', ['name' => 'Ada']);`. It returns an `App\Http\Response` without first echoing the page. The complete View is rendered when `View::response()` is called; it is not a deferred producer stream. Its returned response can receive a typed cookie through `withCookie()`, as described in [HTTP Responses](Responses.md). See [Returnable View Responses](ViewResponses.md) for status, header, and once-only rendering examples. Logical names are dot-separated and should match the capitalized directory convention. First-letter variants such as `home.welcome` still resolve for compatibility.

## Frontend entries and dynamic shells

The optional [frontend asset mapper](FrontendAssets.md) adds `@frontend('app')` to the existing View stack system. Configure the named entry in `Config/Frontend.php`, declare it in a `.squehub.php` View, and place `@stack('head')`, `@stack('styles')`, and `@stack('scripts')` in the layout. A native `.mjs` entry under `public/assets/` needs no Node.js or build step. A selected Vite profile instead resolves development modules from its configured loopback server or production modules from the validated build manifest. The declaration uses the same render state as other template-owned assets; it does not make the View a JavaScript template.

Phase 22's frontend View integration passed Windows and user-run native Linux qualification, including real Vite/React/Vue builds; the bounded results are in [release readiness](ReleaseReadiness.md).

A browser frontend that uses Session Auth can render `<meta name="csrf-token" content="{{ csrf_token() }}">` in its backend View and return it with `View::response('Frontend.App', headers: ['Cache-Control' => 'private, no-store'])`. The token is generated for the current Session at render time, including when compiled PHP is reused. Do not publish that shell as static, shared-cacheable `index.html`. See [Frontend Session Auth](FrontendAuth.md) for same-origin Fetch, [SPA routing](SpaRouting.md) for the optional unmatched-navigation policy, and [frontend profiles](FrontendProfiles.md) for generated React, Vue, and native Vite source.

## Layouts and sections

The [Layouts and Sections guide](Layouts.md) covers inheritance, defaults, duplicate sections, nested layouts, and circular-chain safety. A page supplies sections; a layout controls where they appear. A layout can also place template-owned resources through [asset stacks](Assets.md).

A page can mark one region with `@fragment('orders.list') ... @endfragment`. Normal `View::render()` keeps that body in the full layout. `View::fragment('Orders.Index', 'orders.list', $data)` executes only the named body and returns an immutable result with `html()` and finalized named asset stacks. It does not automatically create an HTTP response, choose an AJAX format, or expose a layout section as a Fragment. See [Fragments and Partial Responses](Fragments.md) for placement, data scope, assets, and explicit route examples.

Create `Project/Views/Layouts/Main.squehub.php`:

```html
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'SqueHub')</title>
</head>
<body>
    <header>@include('Partials.Navigation')</header>
    <main>@yield('content')</main>
</body>
</html>
```

Create `Project/Views/Pages/Dashboard.squehub.php`:

```html
@extends('Layouts.Main')

@section('title')Dashboard@endsection

@section('content')
    <h1>Hello, {{ $userName }}</h1>
    <p>Your account is ready.</p>
@endsection
```

Render `View::render('Pages.Dashboard', ['userName' => 'Ada'])`. `@section('name')` captures content up to `@endsection`; `@yield('name')` inserts it in the layout. `@yield('name', 'Default')` uses an escaped fallback only when the section is undefined; an explicitly empty section suppresses it. A trusted PHP expression such as `@yield('name', strtoupper($fallback))` is evaluated lazily at render time and its result is escaped. Captured section markup is already rendered and is not escaped again. One quoted, static `@extends(...)` declaration may appear before or after the sections, outside control flow. The layout inherits the child's resolved data; an optional second argument adds or overrides layout values without forwarding the others. Nested layouts honor the most specific child's sections, and layout and section state is released after the top-level render.

## Template-owned scripts, styles, and stacks

A page, layout, or included partial may declare `@style('/assets/app.css')` or `@script('/assets/app.js')`. These directives collect resources without emitting tags at their declaration position. A layout places them with `@stack('styles')` and `@stack('scripts')`; `@stack('head')` and `@stack('scripts-after')` are useful additional locations. `@push('head') ... @endpush` captures arbitrary rendered markup, while `@prepend('scripts') ... @endprepend` places a block before normal entries. Direct resources deduplicate, and an optional `once: 'key'` can identify repeated resources or blocks within one render. A resource from an included partial is collected only if that partial actually renders. See [Template-owned Scripts, Styles and Asset Stacks](Assets.md) for ordering, external owner registration, escaping, and render isolation.

For a URL mount such as `APP_BASE_PATH=/app`, those direct local asset declarations emit `/app/assets/app.css` and `/app/assets/app.js`. **Use `asset()` for application-owned public assets in handwritten HTML**: `{{ asset('/assets/logo.svg') }}` resolves to `/assets/logo.svg` at root and `/app/assets/logo.svg` under `/app`. The same convention applies to the supplied Welcome View's favicon and logo and to images, CSS, JavaScript, media, and documents. `@style` and `@script` already use the same asset URL resolver, so their direct local paths need no `asset()` wrapper. The View compiler does not rewrite arbitrary literal `href`, `src`, or `action` attributes. Named `route()` calls in templates produce public mounted paths for application routes. See [Public asset URLs](Assets.md#public-asset-urls) and [mounted HTTP paths](Http.md#url-base-path-and-mounted-requests).

## Page titles and layouts

`title` is a normal, unreserved View variable. An application provider's `boot()` method may share a default with `View::share('title', (string) $this->app->config()->get('app.name', 'SqueHub'))`. A route or controller may override it in `View::render()` data. A layout that uses data rather than the section-based `@yield('title', ...)` example above can render:

```html
<head>
    <title>{{ $title }}</title>
    @include('Partials.HeadMeta')
</head>
```

The existing home `/` route can set a title without a controller:

```php
// Project/Routes/Web.php
use App\Plugins\{Route, View};

Route::path('/')
    ->get(static fn () => View::render('Home.Welcome', [
        'title' => 'Welcome to My Site',
    ]))
    ->named('welcome.page');
```

Edit that route instead of registering a duplicate. Changing the `title` string changes the home page title; omitting it uses any shared default. `Config/App.php` reads `APP_NAME`, which an application provider may share as the default title. The explicit render value reaches `Home.Welcome`, the layout, and `Partials.HeadMeta` without repeated forwarding. The head partial can render `{{ $title }}` or receive a local override such as `@include('Partials.HeadMeta', ['title' => 'Preview'])`; that override stays inside the partial subtree. `{{ $title }}` escapes HTML, while `{!! $title !!}` requires trusted content. Runtime title values are merged when rendering and are not embedded in compiled template PHP. There is no separate head manager. See [Shared View Context](ViewContext.md#page-titles-and-layouts) for the complete precedence contract.

## Includes and reusable partials

Create `Project/Views/Partials/Navigation.squehub.php`:

```html
<nav><a href="{{ route('dashboard') }}">Dashboard</a></nav>
```

Data passed to a View belongs to that render tree. Layouts, sections, includes, and nested includes inherit it automatically. Include a partial using `@include('Partials.Navigation')`; it receives the current view's variables without manual forwarding. `@include('Partials.Greeting', ['name' => $userName])` adds or overrides `name` for that partial and its descendants while retaining other inherited values. It does not change parent or sibling Views. An included View's composer may replace an inherited value, including top-level explicit data, within that subtree; an explicit include argument wins over that composer. Layout composers can fill missing keys but do not replace the child's fully resolved context. See [Includes and Reusable Partials](Includes.md) for the full contract, [Shared View Context](ViewContext.md#layouts-includes-and-local-data) for a nested example, and [Layouts and Sections](Layouts.md) for inheritance rules.

`@include('Partials.Editor')` requires the partial. `@includeOptional('Partials.Promo')` emits nothing only when the View is genuinely absent. `@includeWhen($editing, 'Partials.Editor', ['mode' => 'compact'])` renders a required partial only when the condition is true; a false condition does not evaluate include data or run the partial's composer or assets. Existing-but-failing templates and unsafe or broken links still fail. Includes and layouts with missing required files throw an exception. A missing top-level View now raises a logical diagnostic and reaches the safe HTTP error boundary rather than returning markup. Inside an active `@foreach` or `@forelse`, the inherited `$loop` context remains authoritative even if an include argument or partial composer supplies a value named `loop`.

The compiler accepts quoted, static logical names in all three include directives, `@extends`, `@section`, `@yield`, and component invocations. Section names may include dots, as in `page.actions`. Direct and alias-based active include cycles fail safely; a repeated include after its first render completes is valid. Components use their own explicit prop interface rather than inheriting arbitrary caller data. Choose trusted logical names when rendering must vary; do not build template paths directly from untrusted request input.

Source-aware compiler and dependency errors identify the logical View, source line where known, and active logical cycle chain without publishing physical or compiled-cache paths. Application tests can call `$this->view('Pages.Dashboard', $data)` or `$this->fragment('Pages.Dashboard', 'body', $data)` through the real renderer and assert output and finalized stacks. See [View Diagnostics and Testing](ViewDiagnosticsTesting.md) for the exact testing API and production privacy boundary.

## Components, props, and slots

Use a paired component when reusable markup needs an explicit interface:

```php
@component('Alert', ['type' => 'success'])
    Saved successfully.
@endcomponent
```

`Project/Views/Components/Alert.squehub.php` declares required and defaulted props with `@props([...])`. Body content becomes the already-rendered default `$slot`; `@slot('actions') ... @endslot` supplies a named slot. A third invocation argument supplies an independent HTML attribute map through `$attributes`. Slot bodies use caller scope, while the component template sees only declared props and justified framework bindings such as `$errors` and active `$loop`. Component-owned assets participate through the same stacks as page and partial assets. See [Components, Props and Slots](Components.md) for syntax, escaping, nesting, and safety rules.

## Conditionals, loops, and date shortcuts

```html
<ul>
    @forelse ($users as $user)
        <li>{{ $loop->iteration }}. {{ $user->name }}</li>
    @empty
        <li>No users yet.</li>
    @endforelse
</ul>
```

Phase 14C validates `@if`/`@elseif`/`@else`/`@endif` structure and adds `@unless`/`@endunless` (with optional `@else`) and `@switch`/`@case`/`@default`/`@break`/`@endswitch`. Conditions accept balanced, multiline trusted PHP expressions evaluated at render time. Switch cases use normal PHP fall-through; add bare `@break` where execution should stop. Malformed or mismatched control directives produce a compiler error with the logical View and source line in development. See [Conditionals and Control Flow](Conditionals.md) for syntax, nesting, diagnostics, and a conditional layout-title example.

Phase 14D supports structurally checked `@foreach`/`@endforeach`, `@forelse`/`@empty`/`@endforelse`, `@for`/`@endfor`, and `@while`/`@endwhile`, plus bare loop-scoped `@break` and `@continue`. `@foreach` and `@forelse` expose read-only `$loop` metadata; `@for` and `@while` do not create complete iteration metadata. The legacy `@do`/`@enddo` and `@php`/`@endphp` forms remain available. See [Loops and Iteration State](Loops.md) for syntax, iterable preparation, scope, and diagnostics. Keep complex decisions in controllers or services. `@year`, `@month`, `@date`, and `@time` use PHP's current date/time; `@datetime('Y-m-d')` uses a trusted literal PHP date format. Pass formatted application time explicitly when timezone precision matters. `.squehub.php` files are executable PHP source, so never permit untrusted users to upload them as templates.

## Browser forms and notices

An application may map an ordinary submitted form to an optional typed constructor with `Request::validatedAs()` after the existing CSRF and Validator checks. `@csrf`, `@error`, `$errors`, `old()`, escaped output, and redirect-back behavior are unchanged; no template compiler directive or typed-form engine is added. See [Typed application data](ApplicationData.md#browser-form-csrf-errors-and-old-input) for a complete route, service, form, success redirect, and failure flow.

```html
<form method="POST" action="{{ route('profile.update') }}">
    @csrf
    @method('PATCH')
    <label>Email <input name="email" value="{{ old('email', $user->email ?? '') }}"></label>
    @error('email')
        <p role="alert">{{ $message }}</p>
    @enderror
    <input type="hidden" name="updates" value="0">
    <input type="checkbox" name="updates" value="1" {{ checked((string) old('updates', '0') === '1') }}>
    <button type="submit">Save</button>
</form>
```

`@csrf` emits the current session token at **render time**, even when compiled PHP is reused. The reserved `$errors` bag always exists. Browser validation can flash filtered `old()` input and errors for one later request. `old()` returns its stored value unescaped, so render it with `{{ }}`. `checked()` and `selected()` emit only fixed HTML attribute names from deliberate boolean conditions; no form builder or automatic model binding is involved. See [Forms](Forms.md), [Validation](Validation.md), [Sessions](Sessions.md), and [CSRF](Csrf.md).

Give the profile update route the name `profile.update` before rendering this form. Under `/app`, `route('profile.update')` supplies an `/app/...` action; a literal root-relative `/profile` would leave the mount. The same rule applies to links and image sources: use `route()` for a named application route and `asset()` for a local public asset rather than expecting the compiler to alter literal HTML.

The historical `App\Core\Notification` flash helper is separate from deliverable `App\Plugins\Notification` objects. Existing templates can use `@hasNotification('success') ... @endhasNotification` and `@notification('success')`. The former exposes `$message` inside its block; write `{{ $message }}` to escape it. These directives consume the legacy flash value according to that helper's existing behavior; they do not send Mail or Queue work.

## Auth, authorization, and Session presentation

`@auth` and `@guest` branch on the current Auth guard; `@auth('admin')` and `@guest('admin')` name a configured guard, never an implicit role. `@can('reports.view')` and `@cannot('reports.view')` use the existing Authorization manager; a resource policy check can use `@can('update', $order)`. `@session('status')` checks whether a literal Session key is present, exposes `$value` only inside its block, and can present a flashed value without consuming it. All five block directives support `@else`. These decisions are made when the template renders, so compiled PHP can be reused across visitors and requests.

Environment and debug presentation can use ordinary conditionals such as `@if (environment('local'))` and `@if (debugging())`. Both read the current Application at render time; they do not expose environment variables or change debug policy. See [Template Utilities](TemplateUtilities.md).

View directives hide or show markup. Routes and services still need Auth middleware and server-side `authorize()->require(...)` checks. A skipped branch does not render nested partials/components or collect their assets. See [Auth, Guards, Session and Authorization](ViewSecurity.md) for examples and the exact boundary.

## Security and compiled cache

```html
<p>{{ $userSuppliedName }}</p>
<div>{!! $trustedHtmlFragment !!}</div>
```

`{{ }}` and `@echo(...)` HTML-escape scalars and `Stringable` values. `{!! !!}` is raw output for trusted or separately sanitized markup. HTML escaping alone does not validate URLs or make JavaScript/CSS contexts safe. Never put passwords, tokens, or other secrets into a view or error message.

Compiled View artifacts are under the Application's `Storage/Views` directory and include a compiler format version, full logical View name, source bytes, and compilation context in their framed identity. Changing a template selects a new artifact without an mtime-dependent clear; damaged derived files are rebuilt safely from current source where possible. `php squehub view:cache` optionally warms current Views without rendering, and `php squehub view:clear` removes owned compiled artifacts. `php squehub cache:clear` remains for application data only. Keep runtime files writable where used, protected from web access, and out of source control. A cached artifact never bypasses current source containment or Package activation checks. Unqualified precedence remains the order above; namespaced Package Views use their namespace-specific application override before Package source. The historical Mail renderer has separate template paths and does not use this resolver. See [Compiled Views and Production Lifecycle](CompiledViews.md).

## Application-facing Plugins import

Application code may import `App\Plugins\View` and type provider/composer callbacks with `App\Plugins\ViewContext`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
