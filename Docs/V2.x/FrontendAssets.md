# Frontend Assets and Native Modules

SqueHub remains PHP-first. An ordinary application can use `asset()`, Views, CSS, and browser JavaScript without Node, npm, Vite, React, or Vue. Phase 22 adds an optional logical asset map and one module entry declaration to the existing View stacks. It does not change how application routes, Session Auth, CSRF, or API tokens work.

Phase 22A, 22B, and 22J passed Windows and user-run native Linux qualification, including real production manifests. The exact platform totals and remaining deployment gates are in [release readiness](ReleaseReadiness.md).

## One asset URL policy

The selected Application owns the asset mapper. Existing calls keep their established public URL meaning:

```php
asset('/assets/css/app.css');
```

At the root mount, this returns `/assets/css/app.css`; with `APP_BASE_PATH=/app`, it returns `/app/assets/css/app.css`. It does not check whether that ordinary public file exists. `@style('/assets/css/app.css')`, `@script('/assets/js/app.js')`, and `View::assets()->for(...)->script(...)` use the same mapper before their final View stacks are emitted. Literal HTML attributes are not rewritten; use `asset()` in handwritten markup.

An explicit logical reference can instead resolve to a selected production build output. For example, if the Vite manifest maps `Src/Main.jsx` to `assets/main.a91d3.js`, then in production:

```php
asset('Src/Main.jsx'); // /assets/build/assets/main.a91d3.js
```

The active `APP_BASE_PATH` is applied exactly once. An unknown relative reference retains ordinary `asset()` behavior. A declared frontend entry missing from its selected production manifest is an error; it does not fall back to a dev-server URL. No file modification timestamp is used as the immutable build identity.

## Zero-build native ES modules

Place a module under the public asset tree, such as `public/assets/js/app.mjs`. Configure `Config/Frontend.php`:

```php
'adapter' => 'none',
'entries' => ['app' => '/assets/js/app.mjs'],
'imports' => [
    '@app/' => '/assets/js/',
],
```

The import-map aliases are explicit. A prefix alias ending in `/` must map to a URL prefix ending in `/`. Local references receive the Application mount. External import-map targets, where deliberately configured, must be HTTPS URLs with a host. SqueHub encodes the map as JSON with HTML-significant characters escaped, rejects malformed or colliding aliases, and does not interpret PHP objects as JavaScript. A native entry must be an existing physical `.js` or `.mjs` file under `public/assets/`, or an active Package module reference. This path needs no Node or build step.

Declare the module in a `.squehub.php` View:

```php
<html>
<head>
    @stack('head')
    @stack('styles')
</head>
<body>
    <main>@yield('content')</main>
    @frontend('app')
    @stack('scripts')
</body>
</html>
```

`@frontend('app')` is a declaration, not immediate output. The import map reaches `head` before the module tag reaches `scripts`, even when the declaration appears later in a child View. A repeated declaration of the same entry emits the same generated resource once per top-level render. It does not create a second template engine. Existing `@script` still emits a classic script. A Fragment can inspect its finalized named stacks just like other View assets.

## Optional Vite entries

A reviewed frontend profile can select Vite using the same `Config/Frontend.php`:

```php
'adapter' => 'vite',
'source' => 'Project/Frontend',
'entries' => ['app' => 'Src/Main.jsx'],
'build' => [
    'directory' => 'public/assets/build',
    'manifest' => '.vite/manifest.json',
],
'development' => [
    'enabled' => true,
    'url' => 'http://127.0.0.1:5173',
],
```

`Project/Frontend` contains optional source and Node dependencies; it is not a second PHP application root. The Vite build writes browser assets under `public/assets/build` and a manifest at `public/assets/build/.vite/manifest.json`. The backend View remains the page shell. It can render a session-bound CSRF meta tag, normal SqueHub HTML, and `@frontend('app')`; a static Vite `index.html` is not required.

In the `development` or `local` environment, an explicitly enabled Vite entry emits the configured loopback Vite client and source module. The URL must be a loopback HTTP origin; an incoming request's `Host` header never chooses it. Merely booting PHP or rendering an ordinary View does not launch or probe Node. The configured development URL can be replaced for a single `php squehub dev --frontend` child process without writing it to `.env`.

Outside explicitly enabled development, the entry uses the production manifest. Its CSS and static import dependencies reach the existing `styles` and `head` stacks, and the hashed JavaScript entry reaches `scripts`. Production HTML must contain no `@vite/client`, localhost asset URL, or HMR endpoint. A missing manifest, malformed entry, absent build file, unsafe path, or link escaping the build root fails with a safe frontend error. This makes a stale manifest from another deployment visible instead of silently referencing missing output.

## Production manifest boundary

The reader accepts a bounded Vite manifest object and validates every selected output beneath the configured `public/assets` build directory. Paths are filenames, not arbitrary URLs or filesystem commands. It rejects traversal, drive and UNC paths, control characters, PHP-executable output paths, invalid casing collisions, linked/reparsed files, missing imports, and missing CSS or JavaScript output. It reads manifest bytes and fingerprints their contents; a changed manifest is reparsed, and output presence is rechecked. No PHP source or build command executes while resolving a production asset.

The manifest supplies deploy-time file identity, not a guarantee of globally atomic deployment. Deploy the manifest and its referenced files together. A rollback must restore the corresponding files as well as the manifest. The manifest contains public build metadata and must not contain credentials.

## Package and Kit assets

An active Package can expose a validated asset from `Project/Packages/<Name>/Assets/` through a logical reference:

```php
asset('Shop::js/main.mjs');
```

This maps to `/assets/Packages/Shop/js/main.mjs` with a content-derived version query and the current base path. The Package asset source resolves only while the Package is active, checks physical containment and supported extensions, and does not copy Package source into `public/`. A disabled Package cannot obtain this logical URL or serve its private asset through the framework route. An ordinary literal `asset('/assets/...')` only constructs a URL and does not prove a Package is active; requests for the reserved `/assets/Packages/` path are checked at the HTTP boundary, including when a caller writes the URL directly.

Import maps may name one active Package module file: `'imports' => ['@shop/main' => 'Shop::js/main.mjs']`. Package directory-prefix mappings such as `'@shop/' => 'Shop::js/'` are rejected. The Package source contract validates physical files and does not publish a directory tree as an unchecked import-map prefix.

Kits follow their established reviewed publication rules: declared Kit assets are copied to `public/assets/Kits/<KitName>/`. After publication they are ordinary public files, and disabling a Kit does not delete them. Removing Kit-owned files is a separate reviewed lifecycle action. See [Kits](Kits.md) and [Packages](Packages.md).

## Boundaries

- The default `adapter=none` has no Node, npm, or manifest dependency.
- `asset()` remains a URL resolver for ordinary public references; it is not a general filesystem reader.
- Native modules and import maps do not compile, bundle, or minify code.
- Vite is selected explicitly and uses its normal source/build toolchain. No frontend tooling runs during ordinary PHP bootstrap.
- Module and import-map URLs are escaped/encoded before entering HTML. `@push` remains trusted application markup and is not automatically rewritten.
- Built assets and the manifest must be deployed together. Neither asset mapping nor View rendering deploys files.
