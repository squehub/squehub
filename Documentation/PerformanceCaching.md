# Framework performance caches

SqueHub can avoid repeatedly evaluating base configuration and cacheable route declarations. These are **derived runtime artifacts**, separate from application data Cache and compiled Views. The normal source loaders remain available when an artifact is absent or stale.

| Command | Effect |
| --- | --- |
| `php squehub config:cache --preview` | Review a shared Change Plan without publishing a cache artifact. |
| `php squehub config:cache` | Build the private base `Config/*.php` snapshot. |
| `php squehub config:clear` | Remove only the base configuration artifact. |
| `php squehub route:cache` | Validate route files, then cache their declarations when all sources are cacheable. |
| `php squehub route:clear` | Remove only the route artifact. |
| `php squehub view:cache` | Precompile current Views without rendering them. |
| `php squehub view:clear` | Remove SqueHub-owned compiled View artifacts. |
| `php squehub cache:clear` | Clear the selected **application data** Cache. |

The first four commands use `Storage/Cache/Framework/` under the selected Application root. They do not place an artifact in `public/`, and the framework cache files may contain sensitive resolved configuration. Protect private Storage and exclude it from source archives and public serving. Command output reports counts or a safe error; it does not dump cached values or environment-derived fingerprints. `config:clear` and `route:clear` are recovery operations: they can start without consuming a corrupt configuration artifact.

## Configuration cache

`config:cache` evaluates the array-returning top-level `Config/*.php` files once and stores the base repository values in a versioned, checksummed private JSON artifact. It does **not** cache Package provider contributions; enabled Package hooks run on every normal boot. A cache hit skips base configuration PHP execution.

The `--preview` option uses the shared [Change Plan](ReviewableChanges.md): it hashes Config source files and the existing private artifact without evaluating them or creating Storage. Its restricted CLI bootstrap loads environment values but does not execute Config PHP or boot providers. The explicit build rechecks the manager-owned plan and artifact under the publication lock; if source, effective environment, or artifact changed since planning, it stops rather than overwriting changed state. It does not print resolved values or the environment-derived identity. Preview cannot prove that later executable Config code has no side effects; the ordinary build deliberately evaluates that code.

The artifact identity covers its framework format/build, source files, `.env`, and the effective environment values read through literal `$environment->get()` or `$environment->boolean()` keys. A changed source, `.env`, `APP_ENV`, or relevant shell override makes the artifact stale, so a normal Application boot uses the ordinary loader. If configuration reads environment data through dynamic keys, `getenv()`, or direct environment superglobals, normal boot still works but an explicit cache build is rejected. Keep cacheable Config files declarative and access environment values through the supplied `$environment` object with literal key names.

Corrupt active configuration data is an operational error, not a fresh cache miss. Run `php squehub config:clear` and investigate the private Storage location, then rebuild when the source is valid. Clearing the artifact never clears application data Cache, sessions, or Views. Configuration cache status exposes only active/stale/corrupt/unsupported state and counts, not values.

## Route cache

`route:cache` first checks **all** route sources without executing them. A source containing arbitrary PHP or an unserializable route action, including a route action Closure, fails the build with its logical source and line. The command does not silently omit that route. A group callback may be accepted only when the source can be safely represented as declarations. The shipped welcome route currently uses a Closure action, so a new checkout's `route:cache` is expected to report it as uncacheable until an application replaces that action with a cacheable handler.

A successful build preserves the ordered route declarations, names, groups, middleware, constraints, optional segments, host conditions, bindings, contracts, Package and Kit ownership, and mount-aware URL behavior supported by the route registry. A valid cache replays declarations without re-executing route registration files. Its source and activation fingerprint prevents stale routes after a route edit, Package/Kit state change, or deployment rollback. An absent or stale artifact uses normal route loading; a corrupt active artifact fails visibly until `route:clear` removes it.

Route caching does not invoke a controller and does not turn uncached routing off. For example, a Closure route can still be served and listed normally after a failed cache build. `route:list` and [SqueHub Studio](Studio.md) inspect safe metadata from the registered RouteRegistry whether its declarations came from a current cache or normal source loading. Route **inspectability** does not depend on route **cacheability**: the shipped Closure-backed welcome route can appear as `GET /`, `welcome.page`, `Closure` without serializing or invoking its handler. A Package can contribute routes through the existing lifecycle; no new route syntax is required.

## Build, deployment, and recovery

Builds use a private lock and a temporary artifact, validate it, then replace the active artifact. A failed build must leave the previous artifact intact. The runtime checks source identity again before using it, so an older artifact cannot silently represent a newer source tree. Store and deploy source, `.env`, Package/Kit activation state, and runtime artifacts with the same Application root; rebuild or clear after deploying when appropriate. Keep the web document root at `public/`.

For a deployment whose routes are cacheable:

```bash
php squehub config:cache
php squehub route:cache
php squehub view:cache
php squehub doctor
```

If `route:cache` reports an uncacheable source, leave the route artifact absent and use ordinary route loading. If an existing stale artifact is present, the runtime detects the mismatch and loads current sources. If an artifact is corrupt, clear that specific artifact and inspect Storage permissions or interrupted deployment steps.

There is currently no `php squehub optimize` or `optimize:clear` command. The individual caches avoid real work, but a single aggregate command would need a validated all-or-nothing activation across configuration, routes, and Views. A cosmetic sequence could leave a partially switched deployment if a later build fails. Use the explicit commands and check each result. See [Configuration](Configuration.md), [Routing](Routing.md), [Compiled Views](CompiledViews.md), and [Deployment](Deployment.md).

These caches are a correctness-preserving startup optimization, not a published throughput claim. Source manifests still require bounded filesystem checks. Measure representative requests and worker restarts in the deployment environment before claiming a performance gain.
