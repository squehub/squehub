# Auth, Guards, Session and Authorization

SqueHub templates can show different markup for authenticated visitors, guests, authorization decisions, and Session values. These directives read the existing Application-owned Auth, Authorization, and Session services when a View renders. They do not create a separate security system or store security state in compiled templates.

> **View directives control presentation only. Protect routes and actions with middleware and server-side Authorization.** Hiding an edit button does not protect its endpoint.

## Authentication and guests

`@auth` asks whether the current default guard has an authenticated identity. `@guest` asks whether that guard has none. Both support an `@else` branch:

```php
@auth
    <p>Welcome, {{ auth()->user()->name }}</p>
@else
    <a href="/login">Log in</a>
@endauth

@guest
    <a href="/register">Create an account</a>
@else
    <a href="/account">Your account</a>
@endguest
```

Without a configured default or request-selected guard, bare `@auth` is false and bare `@guest` is true; the directives do not fabricate a visitor. Configure a default guard in `Config/Auth.php` for browser identity. The `auth()` helper returns the existing AuthManager, so `auth()->user()` resolves the current request identity through that manager **when a guard is configured**. No universal `$user` View variable is added. The existing request identity cache remains owned by Auth; the View layer does not cache an identity.

Pass one quoted, static guard name to inspect another configured guard:

```php
@auth('admin')
    <a href="/admin">Admin dashboard</a>
@else
    <a href="/admin/login">Admin login</a>
@endauth

@guest('admin')
    <a href="/admin/login">Admin login</a>
@endguest
```

Here `admin` is a **guard name**, not a built-in role, ability, or privilege. A visitor can be authenticated on the default guard and a guest on `admin`, or authenticated independently on both. Application PHP can select a configured guard with `auth()->guard('admin')`; the global `auth()` function itself takes no guard argument. An unknown named guard is a configuration error, not a guest result. A request-selected token guard remains subject to the existing AuthManager behavior; templates do not select token guards or parse Bearer headers themselves.

## Authorization decisions

`@can` uses `authorize()->allows(...)`; `@cannot` uses the inverse decision. An ordinary deny renders no markup, or its `@else` branch. Broken rules and policies remain errors rather than being converted to denials.

```php
@can('reports.view')
    <a href="/reports">Reports</a>
@else
    <span>Reports are unavailable.</span>
@endcan

@cannot('reports.view')
    <span>Reports are unavailable.</span>
@else
    <a href="/reports">Reports</a>
@endcannot
```

For a resource, pass the resource expression as the second argument. It is evaluated at render time for that decision:

```php
@foreach ($orders as $order)
    @can('update', $order)
        <a href="/orders/{{ $order->id }}/edit">Edit</a>
    @endcan
@endforeach
```

In this example, `update` is the method on the policy registered for the order class. Global ability names may contain dots, such as `reports.view`; a resource policy ability must be a valid policy method name. SqueHub does not infer permissions from a guard name, a Session key, or a View variable. An explicitly registered ability takes precedence over a same-named [RBAC permission](RBAC.md); otherwise a registered permission can supply that global check. A configured rule denies a guest without invoking application rule code; an unregistered ability or missing policy is still a configuration error. Authorization decisions are evaluated for each check by the existing manager. The compiler does not implement policy discovery, role checks, or decision caching.

Ability names are quoted, static strings. The optional resource is a trusted PHP expression evaluated once for the check; multiline directive arguments follow the normal balanced template scanner. Dynamic ability lookup, role directives, and special superuser behavior are not part of this View API.

Authentication answers **who is signed in**. Authorization answers **what that identity may do**. For example, `@auth('admin')` tests a named authentication channel; `@can('admin.access')` evaluates an application-defined ability. Neither gives `admin` a special built-in meaning.

### Enforce at the route or service boundary

The same ability must be checked when a protected action runs:

```php
use App\Plugins\{RequireAbility, Route};
use Project\Controllers\ReportController;

Route::path('/reports')
    ->get([ReportController::class, 'index'])
    ->through(['auth', RequireAbility::named('reports.view')]);
```

For a resource loaded inside a controller or service, call `authorize()->require('update', $order)` after loading the order and before changing it. `@can('update', $order)` only controls the link; a client can call the action URL without rendering the View. API routes need the same server-side enforcement. Token abilities narrow token access and do not grant an Authorization decision. See [Authentication](Authentication.md), [Authorization](Authorization.md), and [API tokens](ApiTokens.md).

## Session and flash presentation

`@session('status')` renders when the current Session **contains** the literal key. It temporarily exposes that value as `$value` inside the block and restores any earlier `$value` afterward. `@else` is available:

```php
@session('status')
    <div role="status">{{ $value }}</div>
@else
    <p>No status message.</p>
@endsession
```

The presence check uses `session()->has('status')`, not truthiness. A present `null`, `false`, `0`, empty string, or empty array is present; `{{ $value }}` follows normal View escaping and output rules. Without an available Session, the block takes its absent-key path. Session keys are **literal**: `@session('profile.theme')` checks a key whose name includes a dot, not nested `profile` data. For direct reads, use the existing `session()->get('status', $default)` and `session()->has('status')` methods. The global `session()` helper takes no key argument.

Flash values are readable through the same Session methods while active. No separate `@flash` directive or second flash store is introduced:

```php
// In a controller, before a redirect:
session()->flash('status', 'Profile saved');

// In the redirected View:
@session('status')
    <p role="status">{{ $value }}</p>
@endsession
```

The value is readable immediately and on the next request, then expires as the following request begins. Reading it in a View does not consume or extend that lifetime. State-changing Session operations, login, and logout belong in controllers or services, never template directives. Do not place credentials or other secrets in flash messages or View output. See [Sessions](Sessions.md) and [Forms and Validation UX](Forms.md).

## Composition and runtime state

Directives may nest inside normal conditionals, loops, layouts, sections, includes, components, and caller-scope slots. A component can read current framework Auth, Authorization, and Session state without receiving the identity or Session contents as undeclared props. A skipped branch does not render its partial or component and does not collect its scripts, styles, or pushed assets.

They also work inside a selected [Fragment](Fragments.md). `View::fragment()` evaluates the current request's guard, ability, and Session state just as a full page would; compiled Fragment instructions never store a user's identity or decision. A denied branch contributes no nested markup or assets. Rendering only a Fragment skips unrelated sibling checks, but the route still needs normal Auth/Authorization enforcement. A hidden button or omitted Fragment never grants or revokes access.

The compiler emits PHP instructions for these checks. It does not embed an identity, guard result, policy decision, resource, Session value, flash message, or token into a compiled file. Reusing the same compiled View across visitors, requests, and Application instances evaluates the current runtime state. Request-scoped identity caching, credential invalidation after account changes, and flash aging remain owned by Auth, Account Security, and Session. Do not put a Request, identity, Session store, or policy result into Application-wide `View::share()` data; use the existing runtime helpers or a request-scoped provider when explicit View data is useful.

Malformed, mismatched, or unclosed blocks fail compilation with the logical View and source line in development. An explicit named guard must be configured; a missing default guard follows the bare `@auth`/`@guest` behavior above. Unknown abilities and broken policies are application errors. Production HTTP errors use the existing safe exception renderer and should not disclose identity objects, Session contents, credentials, token material, or policy internals.

These templates are trusted application PHP source. Keep `{{ ... }}` for escaped HTML text and quoted attributes; `{!! ... !!}` remains deliberately raw. View presentation is separate from route authentication, Authorization enforcement, CSRF, validation, and rate limiting.

The [View diagnostics and testing guide](ViewDiagnosticsTesting.md) shows direct View tests using the existing `actingAs()` and `guest()` helpers. These drive the real guard and Session state for `@auth`, `@guest`, and related presentation; configure real abilities or policies for `@can`/`@cannot`. A test assertion on hidden markup does not prove server-side access control.
