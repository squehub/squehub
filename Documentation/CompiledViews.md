# Compiled Views and Production Lifecycle

SqueHub compiles `.squehub.php` templates into PHP and reuses the result for later renders. Compilation is an internal performance step: [Views](Views.md), [Layouts](Layouts.md), [Includes](Includes.md), [Components](Components.md), and [Fragments](Fragments.md) keep the same rendering and escaping behavior whether an artifact already exists or is created during the request. Source Views remain authoritative. A cached artifact never bypasses the current source resolution and containment checks.

## Runtime lifecycle

For each View that participates in a render, SqueHub resolves its current source within an approved View root, reads it, and derives a deterministic fingerprint from the compiler format, logical View name, source bytes, and compilation context. Runtime data, request values, Auth and Session state, component props, slots, and CSRF tokens are **not** part of that fingerprint. They are evaluated when the compiled instructions run.

The fingerprint selects an immutable artifact under the selected Application's `Storage/Views` directory. A valid artifact is reused. Otherwise SqueHub compiles the current source in memory and publishes a complete artifact through a same-directory temporary file. Per-artifact coordination keeps cooperating local processes from observing a partial PHP file. Damaged or missing derived artifacts are rebuilt from current safe source where possible, with bounded recovery; structural source compilation errors still report the logical View and original source line. SqueHub does not execute a cached View when its source has disappeared or become unsafe, including an outside-root symlink target.

Changing source content selects a new fingerprint even if the file size or modification time stays the same. No manual clear or PHP restart is required after an ordinary template edit. Old content-addressed files may remain on disk until `view:clear`; they cannot be selected by changed source. The same lifecycle applies to a full View, a [returnable View response](ViewResponses.md), selected Fragment, Layout, Include, or Component. Changing a nested template therefore updates its output without rewriting the parent template. A compiled artifact holds template instructions; it never stores a Response, its status or headers, or request data.

## Warm Views before traffic

```bash
php squehub view:cache
```

The command discovers supported `.squehub.php` sources through the current View roots: `Project/Views`, `Project/PackagesViews`, and currently enabled Package View contributions. It also warms effective [namespaced Package Views](PackageViews.md), selecting safe application overrides before Package source and avoiding a second warm of a shadowed Package source. It compiles discovered sources without rendering them, reports **Compiled**, **Reused**, and **Failed** counts, and exits unsuccessfully if any View fails compilation. A second run reuses valid artifacts. Disabled Package namespaces and contributions are not activated by warming.

```text
Compiled Views
Compiled: 12
Reused:   0
Failed:   0
```

Counts depend on the application's current Views. `php squehub view:cache --help` and `php squehub view:clear --help` show the command descriptions without creating the compiled directory.

Precompilation does not execute template `@php` blocks, composers, request providers, Auth, Session, database queries, Mail, or other rendering side effects. It checks compiler structure, not whether runtime controller data is present or arbitrary PHP expressions will succeed when rendered. Review reported logical View errors before deployment.

`view:cache` is optional. A normal request safely compiles a missing artifact on demand. Shared hosting needs PHP 8.2+, ordinary filesystem access, and writable protected runtime Storage for compiled Views; rendering needs no Redis, database, Node build, worker, or OPcache service. CLI access is useful for warming but is not required for normal web rendering.

## Clear only compiled Views

```bash
php squehub view:clear
```

This removes SqueHub-owned compiled View artifacts from the selected Application's compiled-View storage. It is safe to run when there is nothing to remove. The next render compiles on demand, or `view:cache` can warm the current sources again. Per-artifact lock files remain so a concurrent worker cannot end up locking a second inode for the same artifact; only recognized abandoned temporary files old enough to be safe are removed. Clearing is a maintenance operation for an owned cache, not a required step after each source change.

```text
Compiled Views cleared.
Removed: 12
```

`view:clear` is separate from `php squehub cache:clear`: the latter clears the configured [application data cache](Cache.md) namespace. Neither command should remove Sessions, uploads, logs, Queue state, or unrelated Storage files. A clear failure produces an unsuccessful command result rather than a false success message.

## OPcache and concurrent requests

OPcache is optional. New source content selects a new artifact filename, so an ordinary edit does not need a global OPcache reset. When an existing artifact must be recovered or cleared, SqueHub uses targeted invalidation when the OPcache API is available. It does not call `opcache_reset()` for compiled Views. If an enabled OPcache cannot safely invalidate a replaced artifact, the operation fails instead of serving potentially stale bytecode.

Artifacts are derived and content-addressed. Independent workers may compile the same fingerprint concurrently, but the final file is published atomically and validated before execution. This protects cooperating processes on a local filesystem; it is not a claim about arbitrary network filesystem locking. Temporary files from an interrupted compile are never selected as executable artifacts.

## Deployment and diagnosis

A deployment may optionally warm Views after installing the new source and dependencies:

```bash
composer install --no-dev --optimize-autoloader
php squehub view:cache
```

Normal rendering remains available if the warm command was not run. `view:clear` is an explicit maintenance step when removing accumulated old artifacts or investigating a damaged cache. Ensure the PHP process can create `Storage/Views` and write within it. Protect this runtime directory from direct web access and source control. Compiled artifacts are disposable; back up the authoritative `.squehub.php` source rather than treating cache files as application data.

If the source itself is invalid, SqueHub reports a source-aware `CompilerException`. Missing or unsafe root Views, unavailable Fragments, and unexpected runtime errors retain the [View Diagnostics and Testing](ViewDiagnosticsTesting.md) contracts. A recoverable damaged artifact is rebuilt transparently. Production HTTP error pages do not publish physical cache paths, artifact hashes, generated PHP, or request secrets.

See [CLI](Cli.md) for command help, [Deployment](Deployment.md) for runtime storage guidance, [Performance](Performance.md) for performance boundaries, and [Testing](Testing.md) for direct View assertions.
