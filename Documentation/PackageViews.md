# Package and Namespaced Views

A Package can own Views without competing for an application's ordinary logical names. The Package's canonical name is its View namespace:

```php
View::render('Dashboard.Index');          // ordinary application View
View::render('Commerce::Orders.Index');   // Commerce Package View
```

`Package and Namespaced Views` is part of the core [View](Views.md) and [Package](Packages.md) systems. It uses the same template language, renderer, diagnostics, and [compiled lifecycle](CompiledViews.md) as ordinary Views.

## Enable a Package and provide Views

An enabled, valid Package named `Commerce` contributes the exact `Commerce` namespace. Its source templates use the existing Package directory:

```text
Project/Packages/Commerce/
├── Commerce.php
└── Views/
    ├── Orders/Index.squehub.php
    ├── Partials/Order.squehub.php
    ├── Layouts/App.squehub.php
    └── Components/OrderCard.squehub.php
```

The Package entry and activation state remain authoritative. Copying a Package directory, leaving it disabled, or creating `Project/PackagesViews/Commerce/` does not register the namespace. A disabled or removed Package cannot render `Commerce::Orders.Index`, even if an application override or an older compiled artifact remains. Re-enabling a valid Package makes the namespace available to a fresh Application. Separate Applications in one PHP process retain their own activation and namespace state.

There is no namespace alias or last-Package-wins lookup. `Commerce` is an exact logical identifier, so `commerce::Orders.Index` does not select `Commerce`. Package validation requires a canonical, capitalized PHP identifier and rejects case-fold Package collisions during discovery. The local `Orders.Index` portion retains the ordinary View resolver's platform-qualified file lookup: its segments accept the established first-letter variants; Windows filesystem casing and Linux filesystem casing may still differ for other changes in case. Keep source directory and file casing canonical for portable applications.

The `::` delimiter appears exactly once, between the Package name and a nonempty local logical View path. Use dot-separated local segments without a file extension. Empty names, another `::`, path separators, control characters, and traversal are invalid. A namespaced View always requires a currently registered Package namespace.

## Application overrides

An application may customize an active Package View in `Project/PackagesViews/<Namespace>/`:

```text
Project/PackagesViews/Commerce/Orders/Index.squehub.php
```

For `Commerce::Orders.Index`, SqueHub first uses that override when it is safely present, then falls back to `Project/Packages/Commerce/Views/Orders/Index.squehub.php` when the override is absent. `Project/Views/Orders/Index.squehub.php` is an ordinary `Orders.Index` View and does not override the namespaced identity. An override whose path is present but broken or unsafe fails; SqueHub does not silently use the Package source. Application overrides are application-owned customizations and should be reviewed when Package source changes.

The existing unqualified lookup is retained for compatibility: `Project/Views`, flat `Project/PackagesViews`, then enabled bundled Package View roots. An unqualified reference inside a Package View follows that same ordinary lookup. It never becomes relative to the Package:

```php
@include('Partials.Row')           // ordinary unqualified View
@include('Commerce::Partials.Row') // Commerce Package View
```

Two Packages may both provide `Orders.Index`; `Commerce::Orders.Index`, `Accounting::Orders.Index`, and ordinary `Orders.Index` are three independent identities.

## Directives and rendering

Namespaced logical names work wherever a static View name is accepted:

```php
@extends('Commerce::Layouts.App')

@include('Commerce::Partials.Order', ['order' => $order])
@includeOptional('Commerce::Partials.Promo')
@includeWhen($showOrder, 'Commerce::Partials.Order')

@component('Commerce::OrderCard', ['order' => $order])
@endcomponent
```

The component shorthand `Commerce::OrderCard` selects `Commerce::Components.OrderCard`, matching the ordinary component prefix. Component props, slots, attributes, and asset ownership otherwise retain their current rules. A missing local View under a valid namespace may be skipped by `@includeOptional`; an unknown, disabled, or invalid namespace is a configuration error and is not skipped. A false `@includeWhen` condition does not resolve the namespace or source, run a composer, or collect assets.

Cross-Package references are explicit. Cycles across Includes, Components, Layouts, ordinary Views, and namespaced Views are detected by active physical source identity. Diagnostics use logical names and a caller line where known, including names such as `Commerce::Partials.Order`; public errors do not expose physical paths.

## Context, assets, and Fragments

View composers and external assets use the full logical name as an exact owner:

```php
View::compose('Commerce::Orders.Index', function ($context): array {
    return ['title' => 'Orders'];
});

View::assets()->for('Commerce::Orders.Index')->script('/commerce/orders.js');
```

These registrations do not also apply to `Orders.Index` or `Accounting::Orders.Index`. A rendered Package View participates in normal template-owned asset collection and deduplication. Its disabled Package cannot contribute rendered assets.

The Fragment name stays local to its selected root View:

```php
$result = View::fragment('Commerce::Orders.Index', 'orders.list');
```

Application tests may use `$this->view('Commerce::Orders.Index')` and `$this->fragment('Commerce::Orders.Index', 'orders.list')`; both use the real renderer and diagnostic boundary.

A controller may return the complete active Package View with `View::response('Commerce::Orders.Index', ['orders' => $orders])`. This uses the same current override or Package source, activation check, and compiled lifecycle as `View::render()`; a disabled namespace cannot be revived by a response or an older artifact. See [Returnable View Responses](ViewResponses.md).

## Source safety and compiled Views

Namespacing changes logical resolution, not filesystem trust. Every selected Package source and application override passes the normal View root-containment checks. A safe in-root link can resolve; a broken link or an outside-root symlink/reparse path is unsafe and cannot execute an older compiled artifact. The same source check runs again before compiled output executes. An override appearing, disappearing, or changing content selects the current safe source. An unsafe override blocks fallback.

Fresh Application discovery validates the installed Package tree before activation. A broken or escaping link anywhere in that tree marks the Package broken and blocks enabled boot. A currently running Application also rechecks its selected View source before rendering, so a source changed into an unsafe link cannot use previously compiled output. Managed Package install, upgrade, and removal retain their stricter source-tree rules for linked files.

Compiled artifacts remain under the Application's `Storage/Views`. The full namespaced logical identity and current source participate in artifact selection. `php squehub view:cache` warms effective Views from enabled Packages and safe application overrides without rendering templates; `php squehub view:clear` clears only derived View artifacts. Neither command changes Package activation. See [Compiled Views and Production Lifecycle](CompiledViews.md).

The Application's existing contribution registry records the Package namespace claim and the selected View's safe provenance. `package:inspect` reads a prior verified snapshot without executing Package PHP; `package:verify` deliberately refreshes that snapshot under its existing trusted verification rules. See [Contributions and provenance](Contributions.md).
