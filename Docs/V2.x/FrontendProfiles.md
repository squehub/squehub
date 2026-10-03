# Frontend profiles and Vite builds

SqueHub starts as a PHP application. The default `Config/Frontend.php` selects `adapter: none`, so installing and serving a normal application requires no Node.js, npm, Vite, React, or Vue. The optional profile system publishes understandable application source under `Project/Frontend`, an ordinary backend route in `Project/Routes/Frontend.php`, and a backend View in `Project/Views/Frontend/App.squehub.php`. It is a reviewed application composition, not another Package runtime or hidden installer.

## Choose and review a profile

The shipped choices are `vite` (native browser modules), `react`, and `vue`. They all use the same Vite build adapter. Setup remains focused on `.env`, key, and database configuration; profile commands own frontend source changes.

```bash
php squehub profile:inspect
php squehub profile:apply vite --preview
php squehub profile:apply react --spa --preview
php squehub profile:apply vue --yes
```

`--preview` prints the shared ChangePlan without writing. It lists every `CREATE` and `MODIFY`, the selected adapter, source paths, route, View, configuration fingerprints, and warnings. A conflicting file or already selected profile blocks application. `--yes` applies after review in a noninteractive shell; otherwise an interactive terminal asks for confirmation. A plan is rechecked under a local lock before publishing, so a changed file makes it stale. No npm package is installed by profile application.

Applying edits the canonical `Config/Frontend.php` selection: `adapter` becomes `vite`, `entries.app` points to `Src/Main.js` or React's `Src/Main.jsx`, and the development entry becomes enabled. `--spa` additionally selects the existing bounded browser-navigation fallback for `Frontend.App` under `/frontend`; without it, `/frontend` is an ordinary backend route and other routes behave normally. Profiles do not register a generic Route fallback. The backend remains authoritative for API, auth, CSRF, and application routes.

The generated `Project/Frontend/Profile.json` stores format/version, profile name, owned file hashes, and Config before/after hashes. It stores neither secrets nor original Config bytes. It is an ownership marker, not a Package or Kit manifest. The profile writes no migrations or database state. Unknown or developer-owned files are not overwritten.

## Install local tooling and develop

Run npm only after deliberately selecting a profile:

```bash
cd Project/Frontend
npm install
cd ../..
php squehub frontend:status
php squehub dev --frontend
```

`Project/Frontend/.gitignore` ignores `node_modules/`. The profile's package.json declares only the chosen client library and Vite/plugin dependencies. The PHP server remains the browser document origin. Its View declares `@frontend('app')`, which populates `@stack('head')`, `@stack('styles')`, and `@stack('scripts')` with the selected entry. In local development the module and HMR client come from the explicitly selected loopback Vite URL. `dev --frontend` supervises Vite and PHP together; `--frontend-port=5174` requests a specific free loopback port. Node is never probed during ordinary PHP request boot, and Dev never edits `.env` to publish its chosen port.

The generated shell includes the existing CSRF token, the configured CSRF header name, and a mount-aware public base in escaped meta tags. `Src/Api.js` exports `squehubFetch('/api/...')`, which uses same-origin credentials, prefixes the configured application mount, and adds the current configured CSRF header to unsafe methods. It does not hardcode the default header name. This is a convenience wrapper around standard Fetch, not an auth provider or token store. Session Auth remains on the backend; do not place session credentials or access tokens in localStorage. The generated Vite `/api` loopback proxy is an optional convenience when a developer deliberately opens the Vite origin. The normal backend-origin shell and Fetch flow do not depend on that proxy. It is not a production proxy or a trusted forwarded-host source. [SPA fallback](SpaRouting.md) handles eligible unmatched browser navigation when selected.

## Build and deploy

```bash
php squehub frontend:build
php squehub frontend:status --json
```

The build command invokes the selected local Vite executable with direct arguments, then requires `public/assets/build/.vite/manifest.json` and every configured entry/output to be valid physical files inside the public build root. Vite builds a JavaScript entry rather than a static `index.html`. The generated config uses relative asset URLs so hashed module chunks can work below an explicit SqueHub base path. Production uses the manifest and never contacts a development server. If a selected build is absent or inconsistent, frontend asset resolution fails rather than silently serving a development URL. Deploy `public/assets/build` with the matching PHP source/configuration. `frontend:status` distinguishes selected adapter, local Node availability, local Vite installation, dev reachability only when `--probe` is requested, and production build availability; it prints no filesystem path or secret.

An application that does not select Vite can continue to use the [native asset mapper](FrontendAssets.md) and browser modules without Node. No build adapter is selected implicitly by the presence of package.json or `node_modules`.

## Remove a profile

```bash
php squehub profile:remove react --preview
php squehub profile:remove react --yes
```

Removal is deliberately destructive and checks exact owned file and Config hashes. It refuses to delete modified or missing owned files and never removes unknown files, npm's `node_modules`, or application data. It restores the selected Config keys and removes the generated route, View, source, and ownership marker. Inspect and resolve a conflict yourself before retrying; the framework will not guess whether a modified file is safe to discard. Existing Package/Kit state, migrations, database records, and uploads remain untouched.

## Operational boundaries

`php squehub doctor` reports frontend tooling/build state only when Vite is selected. The PHP-only default is skipped and makes no Node or network probe. A selected adapter can work in development without a production build, but production deployment needs verified built files. Once those files exist, production Doctor does not require local Node or Vite packages. If you run Vite separately rather than through `dev --frontend`, configure the explicit loopback `SQUEHUB_FRONTEND_DEV_URL` for that local process; production ignores it. `npm install`, dependency auditing, and lockfile review remain deliberate developer actions. This feature does not add SSR, a JavaScript router, a production Node server, a CDN, or an automatic asset upload. See [frontend assets](FrontendAssets.md), [SqueHub Dev](Dev.md), [Views](Views.md), and [Deployment](Deployment.md).

## Native Linux/WSL qualification

Phase 22's user-run native Linux qualification **passed** on a confirmed case-sensitive filesystem with PHP 8.5.4, Composer 2.9.5, `/usr/bin/node` 22.22.1, `/usr/bin/npm` 9.2.0, and `process.platform=linux`. All 17 focused files passed (**103 tests, 693 assertions, zero failures/errors**); the final Linux suite passed **2,813 tests, 20,249 assertions, 27 skips, zero failures/errors**. Real Vite, React, and Vue production builds and a supervised HMR/proxy smoke passed. The disposable copies and owned processes were removed. This section retains the procedure for repeatable checks; the dated evidence and platform limits are in [release readiness](ReleaseReadiness.md).

Run this opt-in check on the Linux filesystem, such as `/tmp` inside WSL, rather than under `/mnt/d`. It uses a disposable copy and never reads the development checkout's `.env`, `vendor`, root `Storage`, or Git state. Install PHP, Composer, Node.js/npm, and `rsync` in the Linux environment first. Do not point the copied application at a live database.

```bash
set -euo pipefail
unset SQUEHUB_TEST_MYSQL_ENABLED  # Keep live-MySQL opt-in tests disabled.
source_root=/mnt/d/Projects/Squehub/on_dev/squehub-v2
scratch=$(mktemp -d /tmp/squehub-phase22.XXXXXXXX)
test -f "$source_root/squehub" && test -d "$scratch"
touch "$scratch/.case-probe"
test ! -e "$scratch/.CASE-PROBE"   # Confirm this target is case-sensitive.
rm -- "$scratch/.case-probe"
mkdir -p "$scratch/base"
rsync -a \
  --exclude='/.git/' --exclude='/vendor/' --exclude='/.env' \
  --exclude='/Storage/' --exclude='/public/assets/build/' \
  --exclude='node_modules/' \
  "$source_root/" "$scratch/base/"
cd "$scratch/base"
php -v
composer --version
if command -v node >/dev/null; then node --version; else echo 'Node unavailable'; fi
if command -v npm >/dev/null; then npm --version; else echo 'npm unavailable'; fi
composer install --no-interaction --prefer-dist
cp .example.env .env
php squehub key:generate
```

Paste the printed key into `APP_KEY` in the **copied** `.env`. Set `DB_CONNECTION=sqlite` and `DB_SQLITE_DATABASE=:memory:` there, then confirm the copy no longer has the example environment values. `key:generate` prints a key; it does not edit `.env`. The focused files below exercise asset mapping/builds, profile changes, SPA fallback, session/CSRF behavior, development supervision, and base-path hosting. Run each file separately because passing several paths to this PHPUnit entry point may execute only the first.

On a new target without Node/npm, run the PHP-only focused and full suites and record that the real frontend-build check could not be repeated on that target. The completed Phase 22 Windows/Linux qualification above remains a historical result; it does not qualify an untested target.

```bash
(
  set -e
  for test_file in \
    Tests/Unit/AssetManifestTest.php \
    Tests/Unit/AssetRegistryTest.php \
    Tests/Unit/AssetRenderStateTest.php \
    Tests/Unit/FrontendBuildTest.php \
    Tests/Unit/UrlBasePathTest.php \
    Tests/Integration/FrontendAssetTest.php \
    Tests/Integration/AssetHttpTest.php \
    Tests/Integration/FrontendProfileTest.php \
    Tests/Integration/SpaFallbackHttpTest.php \
    Tests/Integration/FrontendSessionCookieHttpTest.php \
    Tests/Integration/FrontendSessionAuthHttpTest.php \
    Tests/Integration/BasePathAssetHttpTest.php \
    Tests/Integration/BasePathBrowserTest.php \
    Tests/Integration/BasePathDevelopmentServerTest.php \
    Tests/Integration/DevCliTest.php \
    Tests/Integration/DevProcessTest.php \
    Tests/Integration/DevSupervisorTest.php
  do
    php vendor/bin/phpunit "$test_file"
  done
)
```

Build each profile in its own copy so npm state from one client library cannot affect another. The React case also reviews the optional scoped SPA selection. `--preview` writes nothing; `--yes` applies the reviewed plan.

```bash
(
  set -e
  for profile in vite react vue; do
    case_root="$scratch/$profile"
    mkdir -p "$case_root"
    rsync -a --exclude='/Storage/' --exclude='/public/assets/build/' \
      "$scratch/base/" "$case_root/"
    cd "$case_root"
    spa=()
    if [ "$profile" = react ]; then spa=(--spa); fi
    php squehub profile:apply "$profile" "${spa[@]}" --preview
    php squehub profile:apply "$profile" "${spa[@]}" --yes
    (cd Project/Frontend && npm install)
    php squehub frontend:build
    php squehub frontend:status --json
    test -f public/assets/build/.vite/manifest.json
  done
)
cd "$scratch/base"
composer test
```

For an optional bounded HMR/proxy smoke check, add this route only to the disposable Vite copy:

```bash
cat > "$scratch/vite/Project/Routes/Phase22Probe.php" <<'PHP'
<?php
use App\Plugins\Route;
Route::path('/api/phase22-probe')->get(static fn (): array => ['phase22' => 'ok']);
PHP
```

Run a bounded supervised session and probe both origins (choose other free ports if needed):

```bash
(
  set -e
  cd "$scratch/vite"
  timeout --signal=TERM --kill-after=5s 30s php squehub dev --frontend \
    --host=127.0.0.1 --port=18080 --frontend-port=15173 \
    > "$scratch/dev-smoke.log" 2>&1 &
  session_pid=$!
  trap 'kill -TERM "$session_pid" 2>/dev/null || true; wait "$session_pid" 2>/dev/null || true' EXIT
  for attempt in $(seq 1 20); do
    if curl -fsS --max-time 1 http://127.0.0.1:18080/frontend \
        -o "$scratch/shell.html" 2>/dev/null \
      && curl -fsS --max-time 1 http://127.0.0.1:15173/@vite/client \
        -o /dev/null 2>/dev/null; then break; fi
    sleep 1
  done
  grep -F '/@vite/client' "$scratch/shell.html"
  curl -fsS --max-time 3 http://127.0.0.1:15173/api/phase22-probe \
    | grep -F '"phase22":"ok"'
)
```

The proxy response must contain `"phase22":"ok"`; a 404 alone is not proxy proof. The cleanup trap stops the supervised session before the temporary files are removed. Inspect `dev-smoke.log` in the temporary directory if startup fails. A timeout exit status of 124 means the session reached its time limit, not that HMR or the proxy passed.

Remove only this confirmed temporary directory after the processes stop:

```bash
if [[ "$scratch" =~ ^/tmp/squehub-phase22\.[A-Za-z0-9]{8}$ \
  && -d "$scratch/base" ]]; then
  rm -rf -- "$scratch"
else
  echo 'Unexpected qualification path; inspect before cleanup.' >&2
fi
```

Record actual test totals, PHP/Node versions, filesystem case check, build results, and smoke results. A Windows run or a copy under `/mnt/d` does not establish native case-sensitive Linux behavior.
