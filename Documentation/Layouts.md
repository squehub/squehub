# Layouts and Sections

Layouts and Sections are part of SqueHub's core View renderer. A page defines content with `@section`; a layout places it with `@yield`. Templates remain trusted `.squehub.php` files selected by dot-separated logical View names. See [Views](Views.md) for resolution, output escaping, and the `View::render()` API.

An enabled Package may supply a layout by explicit name: `@extends('Commerce::Layouts.App')`. The same inheritance, section precedence, cycle checks, and source containment apply. Unqualified layout names keep their ordinary application lookup. See [Package and Namespaced Views](PackageViews.md).

## A page and its layout

Create `Project/Views/Layouts/App.squehub.php`:

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <title>@yield('title', 'My App')</title>
</head>
<body>
    @yield('content')
</body>
</html>
```

Create `Project/Views/Pages/Dashboard.squehub.php`:

```php
@extends('Layouts.App')

@section('title')
    Dashboard
@endsection

@section('content')
    <h1>{{ $title }}</h1>
@endsection
```

Render the page with `View::render('Pages.Dashboard', ['title' => 'Dashboard'])`, or return `View::response('Pages.Dashboard', ['title' => 'Dashboard'])` from a controller. Both use the same page and layout renderer; the response path captures the final HTML without echoing it. See [Returnable View Responses](ViewResponses.md). The explicit data reaches the page and its layout without being forwarded again. `@extends` is a template-level declaration: it may appear before or after the page's sections, and one template may declare only one parent layout. It must be outside control-flow blocks. The parent name is a quoted, static logical View name, resolved through the ordinary safe View resolver. The existing optional data argument can override values for the parent layout, for example `@extends('Layouts.App', ['theme' => 'dark'])`; other inherited values remain available.

A page or intermediate layout with `@extends` evaluates its section bodies and PHP, but output outside its sections is discarded. Put content that should reach the final page in a section. The final layout, which has no `@extends`, controls the surrounding HTML structure and places sections with `@yield`.

Use `@section('name') ... @endsection` to define a section. Names are quoted and static; simple names such as `title` and `content`, as well as dotted names such as `page.actions`, are supported. Section content renders and is captured once in the current render tree. A layout may yield the captured content more than once without rerunning the section body. The resulting HTML is emitted as already-rendered content, so markup within a section is not escaped a second time.

The compiler rejects a duplicate section name in the **same template**, a nested section declaration, an unmatched `@endsection`, or an unclosed section. It reports the logical View and source line in development. A child and parent may both define a section with the same name; that is an inheritance override. The public declaration is the block form; `@section('title', 'Dashboard')` and `@parent` are not supported.

## Defaults and titles

`@yield('sidebar')` emits nothing when `sidebar` is undefined. `@yield('sidebar', 'No sidebar')` uses the fallback only when the section is undefined. An explicitly empty section is still defined and suppresses the fallback:

```php
@section('sidebar')
@endsection
```

A fallback may be a trusted PHP expression, such as `@yield('title', $title ?? 'My App')`. SqueHub evaluates it at render time **only if** the section is undefined, then HTML-escapes its value with the same policy as `{{ ... }}`. Captured section content is already rendered and is not escaped again. HTML escaping is suitable for text and quoted attributes; it does not make a value safe for a URL scheme, JavaScript, or CSS context.

A title section and a normal `$title` View variable are independent. A layout can use `<title>@yield('title', 'My App')</title>`, with a page supplying `@section('title') ... @endsection`. It may instead use `<title>{{ $title }}</title>` when a route or controller passes `title`. With `@yield('title', $title ?? 'My App')`, a defined title section takes precedence; the context value is a fallback only. An empty title section still suppresses that fallback. See [Shared View Context](ViewContext.md#page-titles-and-layouts) for data precedence.

## Nested layouts and section precedence

A layout may itself extend another layout. For example, a page extends `Layouts.Admin`, which extends `Layouts.Base`.

`Project/Views/Pages/Users.squehub.php`:

```php
@extends('Layouts.Admin')

@section('title')
    Users
@endsection

@section('content')
    <h1>Users</h1>
@endsection
```

`Project/Views/Layouts/Admin.squehub.php`:

```php
@extends('Layouts.Base')

@section('title')
    Administration
@endsection

@section('body')
    <aside>Admin navigation</aside>
    <main>@yield('content')</main>
@endsection
```

`Project/Views/Layouts/Base.squehub.php`:

```php
<!DOCTYPE html>
<html>
<head><title>@yield('title', 'My App')</title></head>
<body>@yield('body')</body>
</html>
```

The most specific child section wins. Here, the page supplies `title` and `content`; the Admin layout supplies `body`. Its `body` can yield the page's `content` because child sections are available before the parent section renders. Admin places its wrapper inside `body` because loose output from an extending layout would be discarded. If the page omits `title`, Admin's title fills it. If both omit `title`, Base's `@yield` fallback is used. Ancestors fill missing sections without replacing sections from descendants. The final layout determines output order, regardless of the order in which the page declares sections.

## Context, control flow, and includes

The whole layout chain shares the current render tree's data. Explicit render data, Application shared values, request providers, and each selected View's composer follow the established [Shared View Context](ViewContext.md) precedence. A layout composer may fill missing data without replacing the child's resolved values. A request provider still runs once per request. Sections and ordinary View variables are separate: a section named `title` does not overwrite `$title`.

Conditionals, loops, and existing includes work inside sections:

```php
@section('content')
    @if ($showUsers)
        @forelse ($users as $user)
            <p>{{ $loop->iteration }}. {{ $user->name }}</p>
        @empty
            <p>No users.</p>
        @endforelse
    @endif

    @include('Partials.Footer')
@endsection
```

The section uses render-time data and the normal [conditional](Conditionals.md), [loop](Loops.md), and [include](Includes.md) rules. `$loop` is restored when its iterable loop ends; it does not leak into a later section, ancestor layout, or separate render. Required, optional, and conditional includes work in both sections and layouts. A false conditional include contributes no content or partial-owned assets.

An included partial shares the current render tree's section registry. It may declare a section with `@section`/`@endsection`; the including page or layout can then yield it. The same-template duplicate rule still applies inside each file. If **different** templates at the same layout depth define the same section, the later declaration in render order wins. A section from a more specific child keeps precedence over any ancestor declaration, including one made by an ancestor's partial. An included partial cannot declare its own parent layout: `@extends` in an included View is rejected. A [component](Components.md) can render inside a section or layout but does not declare its own parent layout. A section captures a component once, so repeating `@yield` reuses that captured result.

## Resolution, cycles, and render lifetime

Each parent layout resolves through the same approved View roots, case compatibility, and canonical path containment rules as any View. Missing or outside-root layouts fail through the established View error boundary. SqueHub detects a circular chain, including a self-reference or an alias to the same resolved file, and fails safely instead of recursing indefinitely. Development errors identify logical Views without exposing physical paths; production HTTP errors do not disclose template source, compiled paths, or render data. A failed chain does not emit a partial page.

Section values and layout-chain state belong to one top-level render. Separate top-level renders, requests, and Application instances do not share them. A failed render releases framework-managed state, so a later render starts clean. Compiled templates retain rendering instructions, not captured section content or request values. Reusing a compiled page or layout still resolves current data and sections at render time.

## Fragments inside sections

A requested page may declare `@fragment('orders.list') ... @endfragment` directly inside `@section('content')`. A full `View::render()` includes it normally when the layout yields `content`. `View::fragment('Orders.Index', 'orders.list', $data)` independently executes only that Fragment body: sibling section markup, layout HTML, the layout composer, and layout-owned template assets do not participate. The selected root View's composer and explicit data still apply. A section remains a layout-composition construct and is not selectable merely because it has a name. Layout templates themselves cannot declare externally selectable Fragments. See [Fragments and Partial Responses](Fragments.md).

## Adding resources to a layout

A layout may place `@stack('head')` and `@stack('styles')` in `<head>`, then `@stack('scripts')` and `@stack('scripts-after')` near the end of `<body>`. The page can declare `@style('/assets/dashboard.css')` and `@script('/assets/dashboard.js')` alongside its sections; declarations emit nothing in place. Layouts, pages, included partials, and components own only the resources that participate in that render. See [Template-owned Scripts, Styles and Asset Stacks](Assets.md) for ordering, deduplication, `@push`/`@prepend`, external owner registration, and stack placement.

`@auth`, `@guest`, `@can`, `@cannot`, and `@session` may wrap sections or render inside a page/layout; their checks use the current runtime state. A skipped branch contributes no rendered markup or assets. See [Auth, Guards, Session and Authorization](ViewSecurity.md). A layout is compiled and invalidated from its own source; changing it updates the next render without clearing the page's artifact. `php squehub view:cache` can warm layouts without executing their runtime expressions. See [Compiled Views and Production Lifecycle](CompiledViews.md).

The [View diagnostics and testing guide](ViewDiagnosticsTesting.md) shows how missing parent layouts and circular layout chains identify logical names and the declaring source line. Test the complete layout output with `$this->view(...)`, including section precedence and stack output; the helper uses the same renderer as a request.
