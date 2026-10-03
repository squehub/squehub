# Contributions and provenance

SqueHub records which application or enabled Package registered selected runtime behavior. Provenance helps answer where a route, middleware alias, view, configuration default, service binding, or Scheduler definition came from. It does not change registration, dispatch, view selection, or Package activation.

This is development working-tree functionality, not a promise about the published SqueHub release. See [Packages](Packages.md) for installation, dependencies, and activation.

## Owners and active registrations

An owner has a type and name, such as `package:Weather` or `application:Project`. Framework registrations can be marked `framework:SqueHub` when their origin is known. A Kit-owned `Project/Routes/` file can attribute route registrations to `kit:Ecommerce` by consulting saved file ownership. That is **artifact provenance**: the route file still loads as ordinary Project code, including after Kit disable, and the Kit entry PHP does not run during request boot. Other Kit-published files do not automatically create Kit runtime registrations. See [SqueHub Kits](Kits.md).

A Package may also declare an optional static team label in `composer.json` as `extra.squehub.owner`. That label appears in Package listing and inspection without executing PHP. It is distinct from the registry's `package:<Name>` ownership identity: it never grants privileges or changes contribution ownership.

The Application owns one contribution registry. During each enabled Package's entry, `register()`, and `boot()`, SqueHub supplies that Package as the current owner. Package route files and Scheduler definition files receive the same context while they load. Nested contexts restore the previous owner even if a hook throws. Separate Application instances have separate registries. Registrations without reliable context are left unattributed.

The registry describes **observed effective registrations**. A disabled Package has no runtime registrations. A copied but disabled Package directory does not make its routes or views active. Legacy route replacement records the effective route owner; it does not present the replaced route as still active.

## Currently recorded categories

| Category | Recorded metadata | Boundary |
| --- | --- | --- |
| Route | Method, path, optional name, controller class reference, middleware alias order, API version and contract operation ID when declared | Route files and hook registrations are observed while loaded. A controller class reference is metadata; SqueHub does not invoke or autoload it to inspect ownership. |
| Middleware | Alias and registered class name | Alias registration is observed; middleware is not instantiated for inspection. |
| View namespace | Enabled Package's exact canonical namespace and Package owner | A namespace claim is recorded before the Package's entry hooks. Duplicate or case-fold collisions fail rather than selecting whichever Package loaded last. |
| View | Selected template, safe relative source/root, and a known lower-priority template it overrides | Unqualified roots retain their established precedence. A namespaced View records its Package namespace and whether the selected source is an application override or Package source. Resolving provenance does not render the template. See [Package and Namespaced Views](PackageViews.md). |
| Config | Effective Package-owned key and its `package_default` layer; `overridden` when an observed default is later replaced | Configuration **values are never recorded**. A Package may add only its own `packages.<Name>.*` defaults. If application configuration already supplies a key and the Package skips `set()`, SqueHub cannot infer that skipped default's contributor. |
| Service | Container binding/alias identifier and binding kind | The service object, closure, concrete/factory target, and constructor data are never serialized; inspection does not resolve the service. |
| Scheduler | Validated task name, safe schedule description and mode | Definition files load only during Scheduler operations or explicit Package verification. Inspection never executes a scheduled task. |

Event/listener registration provenance, general class indexing, Package commands, Package Migration/Seeder runners, and asset publication are not claimed here. A route can identify its declared controller class, but that is not a complete ownership index for all PHP classes.

Internally, tooling can query `$app->contributions()->all()`, `byOwner(...)`, `byType(...)`, and `ownerOf($type, $identifier)`. Each result is structured metadata with a safe application-relative source, such as `Project/Packages/Weather/Routes/web.php`. This registry records the current Application's observed behavior; it is not a static Package manifest.

For a large application, choose distinct route names such as `orders.index` and middleware aliases such as `orders.admin`. These are conventions, not automatic prefixes. Existing route and alias collision checks still decide whether registration is valid. A Package that needs another Package's public service declares it in `extra.squehub.requires`; provenance does not infer or authorize that dependency. See [Large applications](LargeApplications.md).

## Static inspection and trusted verification

```bash
php squehub package:inspect Weather
php squehub package:inspect Weather --type=route
php squehub package:verify Weather
```

`package:inspect` reads the Package descriptor, [Activation Registry](ActivationRegistry.md) state, file fingerprints, and last saved contribution snapshot. It reports whether the Package was explicitly enabled, its declared dependencies, and installed Package and Kit dependents. The reverse-dependency list does not imply those components are active or establish why the Package was originally enabled. Inspection does **not** include the Package entry, call hooks, load route or Scheduler PHP, render a view, or run a controller, middleware, listener, job, Migration, or Seeder. It remains usable for a broken Package where descriptor/state files can be read. A missing snapshot reports `unavailable`.

`package:verify` is deliberate trusted execution. The Package must be enabled. Verification boots the enabled Packages in their normal order, loads route and Scheduler definition files, validates Scheduler definitions, and indexes available views without rendering them. It does not dispatch an HTTP request or execute a scheduled task. Review Package source before running this command. It refreshes only the requested Package's contribution snapshot; it does not enable a Package or alter its source ownership. An enabled dependency may execute during the verification boot, but its snapshot is not automatically refreshed.

The last verified snapshot is stored in the Package's private Activation Registry record with sorted contribution records and a digest of the Package source fingerprints. There is no second active Package registry. The snapshot has bounded size and stable ordering and contains no timestamps, absolute paths, closures, service objects, or configuration values.

| Inspection result | Meaning |
| --- | --- |
| `unavailable` | This Package has no valid verified snapshot yet. |
| `current` | Recorded source fingerprints match the current Package tree. For an enabled Package, the records describe its last verified registrations. Changes outside that tree, such as environment or application configuration, can change conditional registrations without making this digest stale. |
| `stale` | Source changed, or the Package can no longer activate safely. Reinspect the change, then run `package:verify` deliberately after repair. |

A disabled Package may retain its historical snapshot, but inspection labels those records **not confirmed active**. Source freshness alone never implies activation. A managed upgrade changes Package source; verify the upgraded Package explicitly before treating its previous contribution snapshot as current.

## Privacy and operational limits

Provenance records structural identifiers and allowlisted metadata, not config values, request or response bodies, credentials, session IDs, authorization headers, token material, or service instances. Do not put secrets into route names, service IDs, filenames, or other structural identifiers. Activation metadata remains private application metadata and should be protected with the rest of the application source; the public document root remains `public/`. Contribution provenance describes observed registrations; activation describes lifecycle intent and requirements. Neither duplicates the other's record.

Static inspection is safe from **Package PHP execution**. It is not a security audit of Package source. Trusted verification and normal boot execute enabled Package code, and that code can have its ordinary application side effects. A snapshot reports what was observed during verification, not a proof that every possible conditional registration was exercised.

`explain:request` and `explain:service` are deferred. The current route and service registry is populated by executing application and Package boot code, so those commands could not yet promise side-effect-free explanation. Later tooling can consume this same structured provenance model without parsing CLI text. Phase 13D [reviewable changes](ReviewableChanges.md) use Package ownership and safe contribution metadata where reliable; they do not execute Package PHP to discover more provenance. Kit file ownership is tracked through its lifecycle. The normal Project route loader can label a Kit-owned route file `kit:<Name>`, while that file's ordinary Project loading remains the source of effective registrations. Phase 19F [Studio](Studio.md) projects safe metadata from those effective registered routes, including available owner provenance; normal route registration may evaluate route-source PHP, but Studio does not invoke route handlers. Phase 21E extends the shared Change Plan with bounded provenance and review metadata. The optional [Agent/MCP integration](AgentAndAI.md) reads existing route registration or validated cache metadata without loading route-source PHP merely to complete an inventory; detailed request or service explanations remain deferred.

**Packages build capabilities. Kits build solutions.** The workflow remains **Inspect → Plan → Apply deliberately → Verify.**
