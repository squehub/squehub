# Shared View Context

SqueHub provides Application-owned values for `.squehub.php` templates. Register a stable value once, derive request data lazily, or prepare data for one named View. Ordinary rendering still uses `App\Plugins\View::render()` and explicit render data. See [Views and layouts](Views.md) for template syntax and escaping.

Composers for Package Views use the exact namespaced logical identity: `View::compose('Commerce::Orders.Index', ...)` applies to that View, not ordinary `Orders.Index` or another Package's `Orders.Index`. Shared values and request providers retain their Application scope. See [Package and Namespaced Views](PackageViews.md).

## Choose the right layer

| Layer | Registration | Lifetime | Suitable for |
| --- | --- | --- | --- |
| Shared value | `View::share($name, $value)` | Owning Application; one snapshot per render tree | Stable site name or navigation configuration |
| Provider | `View::provide($callableOrClass)` | Evaluated lazily once per HTTP request; once per render tree without a request | Current request path, authenticated viewer, Session or flash presentation |
| Composer | `View::compose($view, $callableOrClass)` | Evaluated for each render of that exact logical View | Page-specific headings or presentation data |
| Explicit data | `View::render($view, $data)` | That View; inherited by descendants, where a local composer or explicit directive argument may override it | Controller-selected record, search result, or local override |

For a top-level View, precedence is **explicit render data → its composer → provider → shared value**. For an included View, precedence is **its explicit include data → its composer → inherited parent values → provider → shared value**. A layout inherits the child's fully resolved context as authoritative data; its composer can add a missing key but cannot replace a value already resolved by the child. A later `share()` deliberately replaces an earlier shared value. Two providers returning the same name, or two composers for the same View returning the same name, fail instead of silently depending on registration order. An include composer may replace an inherited top-level value only for that included subtree; an explicit argument on the include wins there.

An active `@foreach` or `@forelse` is a narrow exception for the `loop` name: SqueHub's read-only `$loop` context remains authoritative in an included View even when include data or that View's composer supplies a `loop` key. Outside an active iterable loop, `loop` remains ordinary developer View data; when a loop ends, the prior value is restored. See [Loops and Iteration State](Loops.md).

## Share stable Application values

```php
use App\Plugins\View;

View::share('siteName', 'Example Shop');
```

```html
<title>{{ $siteName }}</title>
```

Register shared values while configuring the Application, such as in a service provider's `boot()` method. The static `View` gateway delegates to the currently selected Application; each Application has its own registrations. A render tree takes a snapshot of shared values, so replacing one during that render takes effect on the next render tree. Avoid storing a Request, Session, authenticated identity, flash value, CSRF token, or mutable per-request object in a shared value.

For a reusable registration, an application provider can use the same Application-owned manager directly:

```php
namespace Project\Providers;

use App\Plugins\ServiceProvider;

final class ViewDataProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->views()->share('siteName', 'Example Shop');
        $this->app->views()->share(
            'title',
            (string) $this->app->config()->get('app.name', 'SqueHub')
        );
    }
}
```

Register that provider with the Application as described in [Application lifecycle](Application.md). The manager and the `View` gateway use the same registrations; framework internals do not depend on `App\Plugins`.

## Provide request data lazily

```php
use App\Plugins\{View, ViewContext};

View::provide(static function (ViewContext $context): array {
    return [
        'currentPath' => $context->request()?->path() ?? '',
    ];
});
```

The provider receives a read-only `ViewContext`. `application()` returns the owning Application; `request()` returns the current Request during HTTP handling and `null` outside a request; `view()` is `null` for a global provider. A provider must return an array of named values. Registration does not execute it. A request that never renders a View never invokes it.

The first View render in an HTTP request evaluates the registered providers and reuses their values for subsequent Views in that request. The Kernel releases those results and the Request after handling, including error paths. The next request evaluates them again, even if the same Request object or Application instance is reused. During CLI or another render without an HTTP request, providers receive `request() === null`; their result is shared only across the current outer render tree. Code that needs Auth, Session, flash, or CSRF state must handle the absent-request case deliberately. No Request or identity is automatically injected into a shared value.

A class provider is resolved through the owning Application's Container only when its output is needed:

```php
namespace Project\Views;

use App\Plugins\ViewContext;

final class NavigationContext
{
    public function provide(ViewContext $context): array
    {
        return [
            'navigationLabel' => $context->request()?->path() ?? 'Console',
        ];
    }
}
```

```php
use App\Plugins\View;
use Project\Views\NavigationContext;

View::provide(NavigationContext::class);
```

The Container can inject the provider class's constructor dependencies. A provider exception stops rendering and follows the normal application error boundary; it does not leave a successful result cached for later requests.

An enabled Package service provider may register the same context API during its normal boot. A disabled Package does not boot merely to contribute View data. Keep registrations with the Application or Package that owns them.

## Compose one named View

```php
use App\Plugins\{View, ViewContext};

View::compose('Pages.Dashboard', static function (ViewContext $context): array {
    return ['pageTitle' => 'Dashboard'];
});

View::render('Pages.Dashboard', ['userName' => 'Ada']);
```

Composer names are dot-separated logical View names and match **exactly**, including case. A composer for `Pages.Dashboard` does not also match `pages.dashboard`, `Layouts.Main`, or another View. It receives the same read-only `ViewContext`, with `view()` set to the selected name. A class composer is resolved lazily through the Container and defines `compose(ViewContext $context): array`. A composer runs for each matching render, including a matching partial or layout. It does not run merely because a route or provider was registered.

Explicit data wins if it uses the same key:

```php
View::render('Pages.Dashboard', ['pageTitle' => 'Account']);
```

Here the template receives `Account`, even though its composer returned `Dashboard`.

An independently rendered [Fragment](Fragments.md) remains part of its requested root View. `View::fragment('Pages.Dashboard', 'summary', $data)` uses that View's shared values, current request provider results, `Pages.Dashboard` composer, explicit `$data`, and framework bindings with the same precedence. Its root composer runs once for the render; an unused Layout or sibling Partial composer does not run. A Fragment should create its own locals or receive them as data instead of relying on PHP variables assigned by sibling template code. The request provider retains its existing once-per-request lifetime; Fragment rendering does not establish a second context registry.

## Page titles and layouts

`title` is an ordinary, unreserved View variable. Sharing it supplies a default for ordinary pages; an explicit `title` passed to `View::render()` overrides that default for the selected page. `Project/Views/Layouts/Main.squehub.php` can use the resulting value directly in its HTML head:

```html
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('Partials.HeadMeta')
</head>
<body>
    @yield('content')
</body>
</html>
```

The `ViewDataProvider` example above shares `app.name` as the default title. `Config/App.php` reads that value from `APP_NAME`; application configuration may choose another default. The existing home `/` route can choose its own title directly:

```php
// Project/Routes/Web.php
use App\Plugins\{Route, View};

Route::path('/')
    ->get(static fn () => View::render('Home.Welcome', [
        'title' => 'Welcome to My Site',
    ]))
    ->named('welcome.page');
```

`Project/Views/Home/Welcome.squehub.php`:

```html
@extends('Layouts.Main')

@section('content')
    <h1>{{ $title }}</h1>
@endsection
```

Edit the existing `/` route rather than registering a duplicate. Change the `title` string to set the home page title. If you omit it, the shared default applies. The route's explicit title reaches the page, its layout, and an included head partial without passing it again. `Project/Views/Partials/HeadMeta.squehub.php` can use `{{ $title }}` directly. For a partial-only override, use `@include('Partials.HeadMeta', ['title' => 'Preview'])`; the partial and its descendants see `Preview`, while the layout's own `<title>` and sibling partials keep the page title.

`Project/Views/Partials/HeadMeta.squehub.php`:

```html
<meta name="description" content="{{ $title }}">
```

Use `{{ $title }}` for escaped HTML text and quoted attributes; do not emit untrusted titles with `{!! !!}`. Title values are resolved at render time. Compiled template PHP contains the rendering instructions, not the runtime title value. SqueHub does not rewrite `<head>` automatically: the page title can be normal View data, and the template owns its `<head>` markup. A layout may explicitly place [asset stacks](Assets.md) in that markup. A page may instead define a `title` section for a layout to yield; that section does not overwrite the ordinary `$title` variable. See [Layouts and Sections](Layouts.md#defaults-and-titles).

## Layouts, includes, and local data

Data passed to a View belongs to that render tree. Layouts, sections, includes, and nested includes inherit it automatically. [Includes and Reusable Partials](Includes.md) documents required, optional, and lazy conditional inclusion, plus subtree-scoped explicit overlays. [Layouts and Sections](Layouts.md) describes nested layout chains and section precedence; these remain separate from ordinary View-data precedence.

Template asset declarations can use those inherited values as runtime URL expressions, such as `@style($themeCss)`. The [asset registry](Assets.md) for external owner declarations is Application-scoped, while resources collected from participating Views are isolated to one top-level render. Asset ownership and stack order do not change the View-data precedence described here.

For example, a controller can pass a Post and page title once:

```php
View::render('Posts.Show', [
    'post' => $post,
    'title' => 'Post details',
]);
```

`Project/Views/Posts/Show.squehub.php`:

```html
@extends('Layouts.Main')

@section('content')
    <h1>{{ $title }}</h1>
    @include('Posts.Partials.Meta', ['label' => 'Published'])
@endsection
```

`Project/Views/Layouts/Main.squehub.php`:

```html
<title>{{ $title }}</title>
<header>Post #{{ $post->id }}</header>
<main>@yield('content')</main>
```

`Project/Views/Posts/Partials/Meta.squehub.php`:

```html
<p>{{ $title }} — {{ $label }}: {{ $post->status }}</p>
@include('Posts.Partials.Author')
```

`Project/Views/Posts/Partials/Author.squehub.php`:

```html
<small>{{ $post->author_name }} · {{ $label }}</small>
```

The nested Author partial receives `$post` and `$label` without either variable being forwarded again. Explicit include data adds `label` to the inherited values or overrides its value for that included subtree. It does not change the parent View or a sibling include. The same inheritance applies through layout sections and nested includes.

Layouts and included partials also receive the shared and request provider layers through the same context manager. Their own exact-name composers run when those Views render. An absent optional partial or false conditional include runs no partial composer, and a nested include does not rerun a request provider. An included View's composer may replace inherited ordinary View data, including a value passed explicitly to the top-level View; an explicit argument on that include overrides its composer. An active iterable loop's `$loop` context remains authoritative as described above. A layout receives the child's resolved context intact: its composer may supply missing keys, while the inherited child values and an explicit layout argument stay authoritative. These changes stay within the nested subtree; the parent and sibling Views retain their own values. The outer render clears its section and context state afterward.

Use the established syntax:

```html
<h1>{{ $pageTitle }}</h1>
@include('Partials.Navigation')
@include('Partials.Greeting', ['name' => $userName])
```

## Name and output safety

Shared, provider, and composer keys must be safe PHP variable names. `errors`, `GLOBALS`, `this`, and names beginning with `__squehub_` are reserved. `loop` remains a valid context key outside an active iterable loop; within one, the framework's `$loop` context takes precedence. Invalid names, non-array provider/composer results, and same-layer duplicate names fail clearly. The framework supplies the current request's `$errors` ErrorBag itself; explicit data named `errors` cannot replace it.

Context values are merged at render time, not baked into compiled template PHP. Context values follow normal View output rules. `{{ $value }}` escapes supported text for HTML; `{!! $value !!}` emits raw output and requires trusted content. Shared View Context does not sanitize URLs, JavaScript, CSS, or raw HTML and is not an authorization boundary. Do not place credentials, tokens, or other secrets into context merely for convenience. Keep authorization checks in application services and middleware, and use view data only for presentation.

The [Template Utilities](TemplateUtilities.md) helpers also run at render time. `environment('production')` and `debugging()` read the selected Application rather than a shared View key or compiled value. `classes()` composes class text, and `@json($data)` encodes structured data without adding it to global View context. Components retain their declared-prop boundary even when they call these helpers.

## Current boundary

These four data layers apply to ordinary Views, layouts, and partials. [Components](Components.md) use a narrower template scope with declared props, slots, attributes, and justified framework bindings such as `$errors` and an active `$loop`. Ordinary shared values and View composers do not silently become undeclared component props. [Form helpers](Forms.md) read old input, errors, and CSRF state from the current request and Session at render time; [View security directives](ViewSecurity.md) likewise evaluate current Auth, Authorization, and Session state. [Compiled Views](CompiledViews.md) preserve these runtime distinctions across reuse and `view:cache` warming. Automatic global Auth or Session variables, wildcard composers, and dynamic component names remain outside the current API. [Returnable View responses](ViewResponses.md) use this same render-time context.
