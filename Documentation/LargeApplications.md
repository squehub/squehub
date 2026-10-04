# Large applications through Packages

SqueHub keeps ordinary application code in `Project/`. **Packages are optional.** A route, controller, model, and view can remain in their familiar directories for the entire life of a small application:

```text
Project/
├── Controllers/
├── Models/
├── Routes/
└── Views/
```

When a capability needs its own routes, services, data model, and maintainers, move that capability into a Package. The application remains the composition root; it does not become a Package.

```text
Project/
├── Controllers/
├── Models/
├── Routes/
├── Views/
└── Packages/
    ├── Accounts/
    ├── Orders/
    ├── Payments/
    ├── Inventory/
    └── Reporting/
```

These directories are capabilities, not mandatory architectural layers. SqueHub uses `Project/Packages/<PackageName>/` for reusable runtime capabilities and `Project/Kits/<KitName>/` for broader application composition. It does not require `Modules/`, `Domains/`, a workspace manifest, or a team owner. **Packages build capabilities. Kits build solutions.** See [SqueHub Kits](Kits.md) for their separate lifecycle.

## Choose a boundary

Keep code in ordinary `Project/` directories while its routes and services are easy to navigate together. Introduce a Package when a capability has a useful public API, independent configuration, or declared dependencies on other capabilities. Extract incrementally:

```text
Project/Controllers/OrdersController.php
Project/Models/Order.php
             ↓
Project/Packages/Orders/
├── Orders.php
├── Controllers/OrdersController.php
├── Models/Order.php
└── Routes/Web.php
```

There is no automatic extraction command. Review namespace references, configuration, route names, and tests as part of the move. Existing root routes and Package routes can run in the same Application.

## Public entry and implementation details

`Project/Packages/Orders/Orders.php` is the framework-recognized Package entry, bootstrap, and deliberate public API. Its `Packages\Orders\Orders` class extends `App\Plugins\ServiceProvider`. The compatible `Project\Packages\Orders\Orders` namespace is also supported. The entry can expose an intentional method or register a service contract through the Container. Classes under `Controllers/`, `Models/`, `Services/`, `Middleware/`, and `Utils/` are implementation details unless the Package author explicitly supports them as a public contract.

```php
<?php

namespace Packages\Orders;

use App\Plugins\ServiceProvider;

final class Orders extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->config();
        if (!$config->has('packages.Orders.retention_days')) {
            $config->set('packages.Orders.retention_days', 90);
        }
    }
}
```

Only the Package-named entry file is required. Add other directories only when the capability needs them. `Project/Packages/Orders/Tests/` is a reasonable development location, but Package activation neither discovers nor runs those tests. Installing or enabling a Package does not execute its Migrations or Seeders.

Package names use exact, portable PascalCase: `Orders`, `Inventory`, `CustomerAccounts`. Directory, entry filename, metadata identity, and namespace must agree. `Payments` and `payments` are conflicting identities, including on filesystems that permit both. Packages remain peers directly under `Project/Packages/`; dependencies express composition instead of nested Package directories.

## Declare dependencies

Declare a runtime requirement in the Package's `composer.json`, rather than relying on incidental PHP load order or a reference to another Package namespace:

```json
{
  "name": "example/commerce",
  "version": "1.0.0",
  "extra": {
    "squehub": {
      "name": "Commerce",
      "requires": ["Orders", "Payments", "Inventory"],
      "owner": "commerce-platform"
    }
  }
}
```

The `owner` label is optional static metadata. It can identify a team or maintainer group; it grants no authorization, changes no boot order, and is available without executing Package code. Labels are bounded, valid UTF-8 text made of letters, numbers, internal spaces, dots, `_`, and `-`. Do not put a URL, email address, credential, or secret in a label. Omit the field for an unowned or small Package.

Consider this graph:

```text
Commerce → Orders → Customers
         → Payments → Customers
         → Inventory
```

`Customers` registers and boots once, before `Orders` and `Payments`; `Commerce` boots after its requirements. SqueHub resolves enabled Packages in deterministic dependency order. The graph is Application-owned and read from installed Package metadata and activation state. Missing, disabled, or broken requirements prevent activation. A dependency is never silently enabled. Cycles are rejected. Disabling or removing a shared requirement is blocked while enabled dependents still need it. An installed Package is not automatically enabled.

Use the existing lifecycle flow to review changes:

```bash
php squehub package:list
php squehub package:inspect Customers
php squehub package:enable Customers --preview
php squehub package:enable Customers --yes
php squehub package:enable Orders --yes
php squehub package:verify Orders
```

Enable requirements first. `package:inspect` reports dependency and reverse-dependency information without including Package PHP; `package:verify` is deliberate trusted execution that refreshes the Package contribution snapshot. Lifecycle plans remain reviewable and do not cascade activation or removal through the graph. No separate `package:graph` command is needed for dependency inspection: structured Package inspection supplies the dependency information, and `package:list` stays concise at scale.

## Configuration belongs to one Package

Use `packages.<PackageName>.*` for Package defaults, such as `packages.Orders.retention_days`. The framework rejects a Package hook that sets another Package's namespace or an unrelated root key. It also rejects replacement of an existing application value; check `has()` before providing a default. Application configuration may override a Package default, and the contribution registry records supported default provenance without storing values. Do not add a second Package configuration store or read another Package's private settings as an undeclared integration contract.

## Routes, middleware, views, and services

Enabled Package route files use the ordinary Router. Choose distinct paths and names; a convention such as `orders.index` or `payments.checkout` makes ownership clear, but SqueHub does not silently prefix names or URLs. Middleware aliases such as `orders.admin` and `payments.verified` likewise reduce collisions without changing existing aliases. The current route and middleware collision rules remain authoritative. Package provenance records route method/path/name, handler metadata, middleware aliases, and owner where registration context is known.

Package services use the existing Container. Register an intentional public service contract or expose a method on the Package entry when another Package must call it. The consuming Package declares the providing Package in `requires`. Events can decouple a suitable interaction, but ordinary service contracts are valid too. One Package must not boot, enable, disable, or remove another from its own runtime hook. Do not treat every PHP class in a Package as a stable public API.

Bundled Package views keep the current view resolution and provenance rules alongside `Project/Views` and `Project/PackagesViews`. [Namespaced Package views](PackageViews.md) use the same activation and containment checks. No Package command discovery, Package Migration/Seeder runner, asset publication, or general class index is implied by this structure. See [Packages](Packages.md) and [Contributions](Contributions.md) for the exact supported categories.

## Test a multi-Package application

Extend `App\Plugins\TestCase` for integration tests. Write fixture Packages into its disposable `TestApplication`, explicitly enable them through the normal Package lifecycle before the first request, and send requests through the real Kernel. Assert Package routes, root routes, disabled Package absence, shared services, and contribution owners. Keep fixture Package class names distinct across tests: PHP cannot unload a class when a temporary root is removed.

Use separate tests for an application with **zero Packages**, a mixed root-and-Package application, shared/diamond requirements, a deep chain, cycles, and broken dependencies. The Application and Package graph are scoped to one fixture; no Package state or provenance should leak to the next fixture. `package:list` and `package:inspect` statically read metadata, so they remain useful even if an unrelated disabled Package is broken. A broken enabled Package remains a safe boot failure.

When several Applications live in one PHP process, use each Application's request, route-loading, or Scheduler-loading entry point. Those operations select the owning Application for static developer shortcuts such as `Route`, `View`, `cache()`, and `session()`. A bare static shortcut called outside an Application operation has no explicit Application identity and uses the most recently selected context; use that Application's Container directly when writing multi-Application orchestration code.

`Project/Packages/<PackageName>/Tests/` may hold capability-specific tests in a project that configures PHPUnit to run them. Runtime Package loading does not discover or require that directory. See [Testing](Testing.md) for disposable fixture setup and real-Kernel request examples.

## Operational boundaries

Static Package inspection does not include entry PHP, route files, controllers, jobs, Scheduler tasks, Migrations, or Seeders. It is not a security audit of executable Package code. Review source before enabling or verifying a third-party Package. Team labels and dependency names are metadata, not privileges. Keep secrets out of structural names and keep the canonical `Project/Activation.json` outside the public document root. Existing `Project/Packages/State.json` files remain private legacy metadata.

Packages use normal PHP, filesystem, and database hosting. A dependency graph does not require Redis, workers, Node.js, or a separate registry service. A fresh Application owns its own Package state, graph, routes, services, configuration, and provenance. Future tooling can consume the structured manager and contribution APIs without parsing console output.

**Start simple. Introduce Package boundaries when the application earns them.**
