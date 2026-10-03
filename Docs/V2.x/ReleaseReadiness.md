# SqueHub v2 release readiness

## Phase 27 public documentation — local implementation complete

Phase 27A–27D is **complete for the local Windows/XAMPP documentation portal**. The current v2 site uses a reviewed responsive docs layout and separate historical `/docs/v1.x` and current-development `/docs/v2.x` catalogs; `/docs` redirects to v2. The current local catalog contains **18 v1 articles and 122 public v2 guide files plus a curated v2 home**. The Phase 28 local rerun of `VerifyPortal.py` reported **141 catalog pages, 1,401 checked internal links, zero issues**, and **142 local documentation HTTP pages**; its Phase 27 predecessor recorded 140 pages, 1,312 links, and 141 HTTP pages. Representative desktop/mobile, search, version switching, theme, and docs 404 flows were inspected in Phase 27. The root landing is served by `/`; there is no intended physical `/index.html` entry point. See the [internal Phase 27 report](Phase27PublicDocs.md) for the complete v1 route table, v2 source coverage, design, and build/stage/deploy/verify workflow.

The Phase 27C documentation check used the live `php squehub list --raw` registry (**72 commands**) against **73 distinct command tokens** found in public guides. The only non-registry tokens were the working `h` alias for `help` and explicitly unavailable `optimize` references. Forty-six selected configuration names resolved to `.example.env`, current `Config/*.php`, or the two documented opt-in test variables. Twenty representative guide/source/test signature checks covered installation, routing, controllers, Views, validation, Database/Schema/Models, Auth, API, Queue, Scheduler, Redis, Storage, Packages, frontend profiles, Locks, idempotency, Agent, and deployment. The portal verifier checks content structure, local links, route responses, and selected exposed-path protections. These checks do not run every example against every optional backend, and they do not replace a clean release-candidate regression.

For a current Windows working-tree regression, direct `php vendor/bin/phpunit --stop-on-failure` with a temporary `PHPRC` copy of XAMPP `php.ini` omitting a duplicate OpenSSL extension directive passed **2,955 tests, 22,781 assertions, 87 skips, zero failures, and zero errors** in **05:52.719**. The unmodified `composer test` invocation hit its 300-second timeout after the duplicate OpenSSL startup warning disrupted exact-output subprocess tests; no global `php.ini` change was made. This pass is local source validation, not a clean artifact or Linux/backend run. `composer validate --strict` and Python/JavaScript portal syntax checks also passed.

This local portal does not establish a public `squehub.com` deployment, a reproducible clean source artifact, a published v2 Composer installation, Linux Apache or cPanel qualification, or release approval. Production publication and artifact/installation proof remain Phase 28 tasks. The historical Phase 26 backend results below retain their own environments and limits; they were not rerun as Phase 27 backend qualification.

## Phase 26 stabilization — complete

Phase 26A–26E is **complete in the recorded Windows and native Linux qualification profiles**. The pre-change Windows PHP 8.2.12 / PDO SQLite 3.39.2 baseline passed **2,946 tests, 22,117 assertions, 90 skips, zero failures, zero errors, and zero risky tests**. The final Windows `composer test` passed **2,955 tests, 22,755 assertions, 90 skips, zero failures, zero errors, and zero risky tests**. The user-run native Linux qualification used PHP 8.5.4, SQLite 3.46.1, and a confirmed case-sensitive filesystem. Focused stabilization passed **45 tests and 1,292 assertions**, with zero failures and errors.

The [public API audit](PublicApiAudit.md) classifies the Plugins, helper, CLI, Config, routing, HTTP/API, ORM, and Package/Kit surface; path-first routing and the v1 compatibility boundary are explicit. The first-party security regression matrix passed **1,122 tests, 9,317 assertions, 42 skips, zero failures, and zero errors** after fixing a legacy Mail template source/data collision and preventing automatic cross-origin HTTP redirects from forwarding arbitrary credentials or request bodies. It is regression evidence, not an external penetration test. [Lifecycle measurements](Stabilization.md#long-running-lifecycle-measurements) cover repeated Applications/requests, Queue, Scheduler, Agent, and persistent MCP STDIO on Windows. A disposable `composer install --no-dev` working-tree copy passed PHP-only CLI, SQLite Doctor, rendered web, Node-optional, and MCP-optional checks. This does not prove the current Git `HEAD` reproduces the v2 tree.

The user-run Redis 8.0.5 / PhpRedis qualification passed **8 tests, 68 assertions, three alternate-client skips, zero failures, and zero errors**. Zero Phase 26 keys remained while the disposable server was reachable; the server was then stopped, its temporary directory removed, and Redis test variables cleared. The user-run MySQL 8.4.11 / PDO MySQL / InnoDB representative qualification passed **8 tests, 448 assertions, zero failures, and zero errors** across Schema, query hardening, API tokens, RBAC, MFA, Queue composition, Locks, and Idempotency. The Linux full suite with live MySQL opt-in passed **2,955 tests, 23,572 assertions, 20 skips, zero failures, and zero errors**. After the disposable MySQL database/user was dropped and opt-in variables cleared, the Linux full suite passed **2,955 tests, 23,004 assertions, 31 skips, zero failures, and zero errors**. The main and no-dev Linux qualification directories were removed; no Phase 26 qualification directories remained. Codex did not run these Linux or live-backend qualifications.

A separate disposable Linux `composer install --no-dev --no-interaction` passed normal CLI, `route:list`, Doctor, SQLite, Composer validation, and a zero-advisory Composer audit. `mcp/sdk` was absent; Agent status remained safe with `mcp_sdk_available=false`. Phase 26 is closed within these profiles. Isolated Apache, macOS, real cPanel, live Predis, Redis auth/TLS, clean committed-checkout reproduction, published v2 installation, and final release approval remain separate gates; see the [compatibility matrix](Stabilization.md#compatibility-evidence).

Phase 26E dependency qualification: unused `league/flysystem` 2.5.0 and its sole orphan `league/mime-type-detection` 1.17.0 were removed from the lock file, and unnecessary `php-http/discovery` Composer plugin execution was disabled. The Windows locked audit reports zero advisories and zero abandoned packages; strict validation, noninteractive install, optimized autoload (**6,527 classes**), and a 119-package license inventory passed. The user-run Linux no-dev install, Composer validation, and zero-advisory audit also passed. The known Flysystem advisory below is retained as **dated historical evidence**, not a current locked-dependency finding. This closes Phase 26E within the recorded profiles, not the wider release gates.

Phase 21A–21E cross-platform qualification, 2026-10-01: portable
[Project bundles](ProjectBundles.md), static [upgrade preflight](UpgradePreflight.md),
read-only [recovery planning](Recovery.md), opt-in
[deployment proof profiles](DeploymentProof.md), and bounded shared
[Change Plan metadata](ReviewableChanges.md) are implemented and qualified in
the development tree. The pre-change Windows baseline passed **2,691 tests, 19,059
assertions, 79 skips**; the final Windows PHP 8.2.12 / PDO SQLite 3.39.2 suite
passed **2,760 tests, 19,557 assertions, 82 skips, zero failures, zero errors,
and zero risky tests**. The user-run native Linux/WSL PHP 8.5.4 suite passed
**2,760 tests, 19,784 assertions, 28 skips, zero failures, and zero errors**.
Linux Phase 21A–21E focused runs passed respectively **17/143**, **16/100**,
**10/43**, **13/129**, and **21/161** tests/assertions; two real case/link tests
passed five assertions. The broad Linux regression passed **785 tests, 5,811
assertions, 11 skips**. The user also verified Linux bundle export, inspection,
preview, import, SHA comparison, and exclusion of `.env` and runtime Storage.
A Windows-to-Linux bundle kept SHA-256
`09691643595AAC6EC83D7AC0E9EE8FDFB392BA19B7BA9CE411A8F25797047059`
through Linux import; a Linux-to-Windows bundle kept SHA-256
`2FC5F92E1637933C410525529A25B155B57481C34331170F52883BFA674A085D`
through Windows inspection. The latter was inspected on Windows, not imported.
Four Doctor deployment profiles (`shared-hosting`, `single-server`, `worker`,
`multi-server`) correctly reported `not_ready` where disposable configuration
lacked proof, preserving configured/reachable/verified distinctions. The
Windows Phase 21 focused group passed **77 tests, 570 assertions, 3 skips**;
the broad Windows regression passed **785 tests, 5,734 assertions, 35 skips**.
Touched-file PHP lint, targeted PHPStan level 5, Composer validation, and
optimized autoload passed. At that Phase 21 checkpoint, the known low-severity Flysystem advisory remained
a separate release decision. Temporary Windows bundle files and the Linux
qualification directory were removed after verification; no temporary
Phase 21 database or restore snapshot remained.

**Phase 21 is complete within these tested boundaries.** Clean committed-checkout
reproduction, a real database restore, end-to-end multi-server operation, and
production deployment remain open release gates, as do published Composer
`create-project` proof, production documentation publication, and Phase 28 release
approval. Phase 26 stabilization and local Phase 27 documentation were subsequently completed in the recorded
qualification profiles above. No globally atomic multi-subsystem apply or
bundle authenticity signature is claimed. A source bundle is not a data
backup; a configured driver is not reachability or end-to-end proof. The dated
historical checks below retain their original scope and test totals.

Phase 22A–22L Windows checkpoint, 2026-10-01: the pre-change Windows PHP
8.2.12 / PDO SQLite 3.39.2 suite passed **2,760 tests, 19,557 assertions,
82 skips, zero failures, and zero errors**. The final working-tree suite passed
**2,813 tests, 20,019 assertions, 84 skips, zero failures, and zero errors**.
The implemented optional frontend path includes Application-owned asset mapping,
native ES modules/import maps, a bounded Vite manifest, three reviewed profiles
(`vite`, `react`, `vue`), explicit SPA fallback, loopback `dev --frontend`, and
same-origin Session/CSRF integration. Ordinary PHP boot, CLI, and native modules
remain Node-free. Disposable generated Vite, React, and Vue projects each
completed real `npm install` and production builds using Node 24.21.0, npm
11.19.0, and Vite 7.3.6. The three npm dependency audits reported zero
vulnerabilities. A supervised local Vite-origin proxy forwarded a real request
to the PHP backend, preserving a test Cookie and CSRF header; generated
production shells resolved hashed assets without HMR references. A separate
loopback PHP HTTP test exercised native Session cookies, CSRF rejection,
login/session rotation, authenticated identity, and logout across requests.
Disposable frontend projects, Node modules, build output, test cache, and
owned child processes were removed after those checks. Focused frontend,
base-path, Package-asset, Studio, and CLI checks passed; the broad Asset/View/
Route/HTTP/Auth/Dev/Package/Kit/Studio/Contract/SDK regression passed
**1,695 tests, 11,946 assertions, 46 skips**. All 50 identified
Phase 22 new or touched PHP files passed lint; targeted PHPStan level 5 passed
with the project's test fixtures loaded. `composer validate --strict`, optimized
autoload, CLI help/list/route/Doctor/infrastructure, and `git diff --check`
passed. At that Phase 22 checkpoint, the locked dependency audit reported the separate low-severity
`league/flysystem` advisory CVE-2026-102601.

Phase 22 native Linux qualification, user run and reported 2026-10-02: PHP
8.5.4, Composer 2.9.5, `/usr/bin/node` 22.22.1, and `/usr/bin/npm` 9.2.0 ran
on a confirmed case-sensitive native Linux filesystem (`process.platform=linux`).
All 17 official focused files passed together: **103 tests, 693 assertions,
zero failures, zero errors**. Real disposable Vite 7.3.6, React 19.3.0 with
react-dom 19.3.0 and `@vitejs/plugin-react` 5.2.0, and Vue 3.5.43 with
`@vitejs/plugin-vue` 6.0.9 profiles passed preview, apply, `npm install`,
production build, manifest, and `frontend:status` checks. Each `npm audit`
reported zero vulnerabilities at qualification time. Production outputs
contained no `/@vite/client`, `localhost:5173`, or `127.0.0.1:5173` reference.
A supervised session on PHP `127.0.0.1:18080` and Vite `127.0.0.1:15173`
emitted the HMR client and proxied `/api/phase22-probe` to the SqueHub JSON
response `{"phase22":"ok"}`. The final Linux suite passed **2,813 tests,
20,249 assertions, 27 skips, zero failures, and zero errors**. The user
removed the native Linux qualification copy, three profile copies,
temporary Node/build files, environment values, and owned processes;
the final process cleanup check passed. The earlier accidental Windows npm
resolution was corrected before this valid native Linux run and is not a
framework failure.

**Phase 22 — Frontend Platform and Application Profiles: COMPLETE.** All
22A–22L packages are implemented, tested, passed, and cross-platform
qualified on the supported Windows and native Linux qualification paths.
Real Vite, React, and Vue builds, HMR/proxy smoke, and final Windows and
Linux suites passed. Codex did not access WSL or XAMPP MySQL for this closure;
the Linux evidence is the user's reported run. This does not prove macOS,
every Linux distribution or Node version, every Apache/reverse-proxy/cPanel
configuration, production multi-node operation, a clean committed checkout,
or public release. A live Apache frontend smoke, provider-specific deployment,
published Composer installation, Phase 26–28, and the then-known low-severity
`league/flysystem` advisory CVE-2026-102601 remained separate release decisions at that checkpoint.
The [frontend profile guide](FrontendProfiles.md#native-linuxwsl-qualification)
retains a repeatable disposable qualification procedure. At this Phase 22
closure, the next implementation step was Phase 23 — Typed Application Data.

Phase 23A–23E Windows checkpoint, 2026-10-02: the Phase 22 baseline was
**2,813 tests, 20,019 assertions, 84 skips, zero failures, and zero errors**.
The final PHP 8.2.12 / PDO SQLite 3.39.2 working-tree suite passed
**2,846 tests, 20,308 assertions, 84 skips, zero failures, and zero errors**;
no risky tests were reported. Phase 23 added 33 tests and 289 assertions in
the full suite. Its six focused files passed together **33 tests, 286
assertions, zero failures, and zero errors**; separately: DataMapper **7/122**,
Request typed mapping **7/38**, browser typed form **3/30**, API Resource
typed flow **3/25**, typed payload registry **7/37**, and typed Queue/Event/
Notification integration **6/34** tests/assertions. They exercise the existing
Validator and CSRF/browser flow, explicit API Resource projection, Application
registry isolation, bounded JSON Queue payloads, retries, privacy, and legacy
array payload compatibility. All 24 new or modified Phase 23 PHP files passed
lint, and targeted
PHPStan level 5 for source and tests passed. `composer validate --strict`,
`composer dump-autoload -o` (6,156 classes), normal CLI/help/list/route-list/
Doctor/infrastructure smokes, and `git diff --check` passed. Doctor reported
`READY WITH WARNINGS` for scheduler persistence, SMTP, and optional Crypt
configuration in this local environment. The live locked
Composer audit returned exit 1 solely for the already known low-severity
`league/flysystem` CVE-2026-102601; this was then a dependency/release decision,
not a newly introduced Phase 23 defect. See [typed application data](ApplicationData.md).

Phase 23 native Linux qualification was run and reported by the user on a confirmed
case-sensitive native Linux filesystem with disposable SQLite :memory:
configuration. Focused files passed: DataMapperTest **7 tests/125 assertions**,
RequestTypedMappingTest **7/38**, TypedFormHttpTest **3/30**,
TypedApiResourceHttpTest **3/25**, TypedPayloadRegistryTest **7/37**,
TypedPayloadQueueIntegrationTest **6/34**, and QueueFoundationTest **12/72**.
Together they passed **45 tests, 361 assertions, zero failures, and zero errors**.
The final native Linux suite passed **2,846 tests, 20,538 assertions, 27 skips,
zero failures, and zero errors**. The Phase 23 qualification directory was
removed; no squehub-phase23.* qualification directories remained. Cleanup
passed. Codex did not access WSL or run these Linux checks.

**Phase 23 — Typed Application Data: COMPLETE.** Implemented: YES; Windows
verification: PASSED; native Linux verification: PASSED; cross-platform
qualification: PASSED; cleanup: PASSED. Each package, 23A Data Objects,
23B Validated Request Mapping, 23C Forms and Data Objects, 23D API Resource
Integration, and 23E Safe Queue/Event Payloads, is implemented, tested, passed,
and complete. Phase 23A–23E is complete, implemented, tested, and cross-platform
qualified on the supported Windows and native Linux qualification paths.
Plain and readonly PHP classes, constructor authority, validation before
construction, declared constructor inputs only, explicit nested data, explicit
ApiResource output projection, and stable alias/version/data Queue envelopes
are the shipped boundaries. Existing arrays remain first-class; Queue remains
at least once; native PHP serialize()/unserialize() is not used. This
qualification does not prove macOS, every Linux distribution or PHP minor,
production multi-node Queue operation, live MySQL/Redis behavior not exercised
by Phase 23, a clean committed checkout, or v2.0.0 release readiness. The
known low-severity Flysystem advisory remained a separate dependency/release
issue. At this Phase 23 closure, Phase 24 was the next implementation milestone.

## Phase 24 reliability qualification

**Phase 24A–24E is complete: implemented, tested, passed, and qualified in the recorded Windows, user-run native Linux, and guarded live Redis/MySQL profiles.** [Lock leases](Locks.md) coordinate named owners through array, local file, SQLite/MySQL database, or Redis stores, with backend-specific boundaries and no external fencing token. [HTTP idempotency](Idempotency.md) is opt-in for authenticated mutation routes; it atomically claims a scoped key and replays only a bounded safe response. [RetryPolicy](Retries.md) provides bounded timing for outbound HTTP and webhook attempts while their existing failure classification remains authoritative. [Circuit breaker](CircuitBreaker.md) is opt-in and coordinates one named dependency through local file state only. Queue retries remain governed by Queue's existing at-least-once worker behavior.

The pre-Phase-24 Windows baseline passed **2,846 tests, 20,308 assertions, 84 skips, zero failures, and zero errors**. Windows focused checks included real separate-process file and SQLite lock contention, idempotency races and stale-claim recovery, a local HTTP retry, and single-probe circuit recovery. Phase 24A passed **37 tests/704 assertions/3 skips**; Phase 24B passed **17/719/2**, including a regression for bounded, progressing file pruning; the HTTP/Webhook retry and transport subset passed **25/262**; and Phase 24D passed **11/70**. A separate Phase 24E process-race and prior-pillar subset passed **38 tests/844 assertions/1 skip**. The final Windows PHP 8.2.12 / PDO SQLite 3.39.2 suite passed **2,924 tests, 21,890 assertions, 89 skips, zero failures, and zero errors**, with no risky-test marker in the PHPUnit progress. The guarded live Redis/MySQL tests skipped *on Windows* because no disposable opt-in service was configured there. All **1,321** first-party PHP files passed lint; targeted PHPStan level 5, `composer validate --strict`, optimized autoload (**6,192 classes**), CLI smokes, **1,893** documentation links, and `git diff --check` passed. At that Phase 24 checkpoint, the known low-severity Flysystem advisory was a separate dependency decision.

The user-run native Linux focused/regression set passed **96 tests, 1,817 assertions, zero failures, and zero errors**. It covered Locks and file/SQLite child-process contention, idempotency stores and real HTTP/concurrency, RetryPolicy and HTTP transport, circuit state and process recovery, and Queue/Webhook regressions. The native filesystem was confirmed case-sensitive; cross-process SQLite coordination used a native temporary database file. The final native Linux suite passed **2,924 tests, 22,122 assertions, 31 skips, zero failures, and zero errors**. Codex did not run Linux.

The user ran guarded live Redis tests with **Redis Server 8.0.5** and **PhpRedis loaded**: `LockRedisLiveTest` passed **1 test/9 assertions** and `IdempotencyRedisLiveTest` passed **1 test/13 assertions**. The disposable server listened on loopback port **16379**, used database **15** and a Phase-24-specific prefix, and had RDB saving and AOF disabled. **The disposable Phase 24 Redis instance had persistence disabled, was stopped, and its temporary Redis directory was removed, so its disposable test state was discarded.** A later post-stop scan did not independently prove zero matching keys because the server was no longer reachable; do not use it as key-by-key cleanup evidence.

The user ran guarded live MySQL tests with **MySQL 8.4.11** and **PDO MySQL loaded** against the disposable `squehub_test_phase24` database and restricted `squehub_phase24@127.0.0.1` user. `LockMySqlOptInTest` passed **1 test/25 assertions** and `IdempotencyMySqlOptInTest` passed **1 test/28 assertions**. Afterward the database and user were dropped, verification queries found neither, and all `SQUEHUB_TEST_*` variables were unset. Codex did not run the live Redis or MySQL sessions.

These results close Phase 24E within the stated qualification scope. They do not prove Redis authentication/TLS, every network topology, an external fencing mechanism, distributed behavior from a local file test, or exactly-once external effects. A lease may expire while work continues; a timeout may occur after a remote mutation succeeds; Queue remains **at least once**. The clean committed-checkout and v2.0.0 release gates remain open.

### Phase 24 native Linux reproduction procedure

The following procedure remains available to repeat the user-run qualification on a native Linux filesystem, with the Windows working tree at the shown mount. The copied source includes locally ignored `Docs/` and `Tests/`; a normal clone of current `HEAD` cannot reproduce this working tree. Codex did not run these Linux commands.

```bash
PHASE24_DIR="$(mktemp -d "$HOME/squehub-phase24.XXXXXX")"
rsync -a --exclude='.git/' --exclude='vendor/' --exclude='node_modules/' \
  --exclude='.env' --exclude='/Storage/' \
  /mnt/d/Projects/Squehub/on_dev/squehub-v2/ "$PHASE24_DIR/"
cd "$PHASE24_DIR"
composer install --no-interaction
cp .example.env .env
PHASE24_KEY="$(php squehub key:generate)"
sed -i "s|^APP_KEY=.*|APP_KEY=$PHASE24_KEY|" .env
unset PHASE24_KEY
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
mkdir -p Storage
touch Storage/phase24.sqlite
printf 'DB_SQLITE_DATABASE=%s/Storage/phase24.sqlite\n' "$PHASE24_DIR" >> .env
php -v
composer --version
printf a > .phase24-case
printf b > .Phase24-case
test "$(cat .phase24-case)" = a && test "$(cat .Phase24-case)" = b
rm .phase24-case .Phase24-case
php vendor/bin/phpunit Tests/Unit/LockTest.php
php vendor/bin/phpunit Tests/Integration/LockIntegrationTest.php
php vendor/bin/phpunit Tests/Unit/IdempotencyStoreTest.php
php vendor/bin/phpunit Tests/Integration/IdempotencyHttpTest.php
php vendor/bin/phpunit Tests/Integration/IdempotencyConcurrencyTest.php
php vendor/bin/phpunit Tests/Unit/RetryPolicyTest.php
php vendor/bin/phpunit Tests/Unit/RetryHttpIntegrationTest.php
php vendor/bin/phpunit Tests/Integration/HttpClientTransportTest.php
php vendor/bin/phpunit Tests/Unit/CircuitBreakerTest.php
php vendor/bin/phpunit Tests/Integration/CircuitBreakerProcessTest.php
php vendor/bin/phpunit Tests/Integration/QueueFoundationTest.php
COMPOSER_PROCESS_TIMEOUT=1200 composer test
```

The integration tests exercise their real child-process file and SQLite races under `$PHASE24_DIR`, never under `/mnt/d`. Any repeat of the guarded Redis tests requires a disposable `SQUEHUB_TEST_REDIS_URL` and an installed client; the tests use random Phase 24 prefixes. Any repeat of the guarded MySQL tests requires `SQUEHUB_TEST_MYSQL_ENABLED=1`, explicit host/port/database/user/password, and matching `SQUEHUB_TEST_MYSQL_CONFIRM_DATABASE` for an empty `squehub_test_*` database. Never reuse normal application DB settings. Record backend versions and cleanup. Remove a qualification copy only after confirming its absolute path is the generated `$HOME/squehub-phase24.*` directory.

## Phase 25 Agent and AI integration — complete

Phase 25A–25F is **implemented, tested, passed, and complete** for the reported Windows and native Linux profiles. It provides optional local, capability-bound [Agent/MCP integration](AgentAndAI.md) in this development tree. The selected official PHP MCP SDK is experimental `mcp/sdk` **0.8.1**, installed for first-party development in `require-dev` and suggested for applications that deliberately enable MCP. SqueHub's local STDIO integration advertises MCP revision **2025-11-25**; the SDK itself also implements the **2026-07-28** modern/stateless era. No remote HTTP transport or bundled LLM client is shipped. `agent:status [--json]` inspects effective grants; `agent:mcp` starts the local protocol process. Default inspection is read-only; `read_schema` and review-only `create_plan` require exact scoped grants. Source/log/test execution and mutation capabilities are defined but unsupported remotely. No generic shell, file browser, arbitrary network call, plan-apply tool, Migration execution, or Package lifecycle action is exposed.

Agent route and contract inspection intentionally uses only route metadata already registered in memory or an existing validated route-cache artifact. It may return a partial result on a fresh uncached Application rather than execute Project route PHP. CLI metadata comes from commands already registered in the running Console, not a second command invocation. Selected Package/Kit activation and Health summaries reuse bounded inspectors. A scoped `create_plan` operation reuses existing ChangePlan owners and returns `applied: false`; a human must use the owning workflow after reviewing fresh state.

The pre-change Windows baseline passed **2,924 tests, 21,890 assertions, 89 skips, zero failures, and zero errors**. The final Windows PHP 8.2.12 / PDO SQLite 3.39.2 suite passed **2,946 tests, 22,117 assertions, 90 skips, zero failures, and zero errors**. A sequential focused `--filter Agent` run passed **22 tests, 204 assertions, one skip**. Per-file Agent checks passed: capability **5 tests/43 assertions** (including denial of route-derived Contract operations), context **7/47** (including aggregate Health privacy), plans **4/36**, CLI **3/20**, and real STDIO integration **3/58/1 skip**. A live Symfony InputStream client negotiated revision 2025-11-25, read a resource, listed and called a tool, and reconnected to a separate server process; malformed/oversized input, secret-sentinel redaction, and stdout purity passed. The official SDK client subprocess test was skipped on Windows after `connect()` blocked; that specific Windows path is unverified. Strict Composer validation, optimized autoload (**6,575 classes**), and targeted Agent PHPStan level 5 passed. The locked dependency audit at that historical checkpoint reported the low-severity Flysystem advisory PKSA-w9tt-7782-78jx / CVE-2026-102601 and no new MCP SDK advisory.

The user-run native case-sensitive Linux qualification used **PHP 8.5.4**, **Composer 2.9.5**, and **mcp/sdk 0.8.1** (Apache-2.0). Focused results were `AgentCapabilityTest` **5 tests/43 assertions**, `AgentContextTest` **7/47**, `AgentPlanTest` **4/36**, `AgentCliTest` **3/20**, and `AgentMcpTest` **3/73**. Combined: **22 tests, 219 assertions, zero skips, failures, and errors**. The official SDK client STDIO interoperability case executed and passed on Linux. The final Linux suite passed **2,946 tests, 22,364 assertions, 31 skips, zero failures, and zero errors**. Cleanup passed: the disposable Phase 25 Linux qualification directory was removed and no `squehub-phase25.*` directory remained. Phase 25 cross-platform qualification is complete within those Windows/native Linux profiles. The clean committed-checkout and v2.0.0 release gates remain open; macOS, remote MCP, and other untested deployment profiles are not inferred.

### Phase 25 native Linux repeat procedure

The already passed qualification can be repeated on a native, case-sensitive Linux filesystem. This copies the development working tree, including locally ignored `Docs/` and `Tests/`; it is **not** proof that current `HEAD` reproduces the source. Codex did not run these Linux commands.

```bash
PHASE25_DIR="$(mktemp -d "$HOME/squehub-phase25.XXXXXX")"
rsync -a --exclude='.git/' --exclude='vendor/' --exclude='node_modules/' \
  --exclude='.env' --exclude='/Storage/' \
  /mnt/d/Projects/Squehub/on_dev/squehub-v2/ "$PHASE25_DIR/"
cd "$PHASE25_DIR"
composer install --no-interaction
cp .example.env .env
PHASE25_KEY="$(php squehub key:generate)"
sed -i "s|^APP_KEY=.*|APP_KEY=$PHASE25_KEY|" .env
unset PHASE25_KEY
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
mkdir -p Storage
touch Storage/phase25.sqlite
printf 'DB_SQLITE_DATABASE=%s/Storage/phase25.sqlite\n' "$PHASE25_DIR" >> .env
php -v
composer --version
composer show mcp/sdk
printf a > .phase25-case
printf b > .Phase25-case
test "$(cat .phase25-case)" = a && test "$(cat .Phase25-case)" = b
rm .phase25-case .Phase25-case
php squehub agent:status --json
php vendor/bin/phpunit Tests/Unit/AgentCapabilityTest.php
php vendor/bin/phpunit Tests/Unit/AgentContextTest.php
php vendor/bin/phpunit Tests/Unit/AgentPlanTest.php
php vendor/bin/phpunit Tests/Integration/AgentCliTest.php
php vendor/bin/phpunit Tests/Integration/AgentMcpTest.php
COMPOSER_PROCESS_TIMEOUT=1200 composer test
```

`AgentMcpTest.php` executed its official SDK client case on Linux against an actual `agent:mcp` child and verified negotiation, resource/tool discovery, and structured results. Its other cases exercise malformed/oversized input handling, stdout purity, repeated startup, and clean shutdown through a compatible live client. The focused tests cover default denial, secret sentinels, scope validation, malicious paths/tool arguments, and plan/apply separation. A repeat run should record any skip rather than treating it as proof. No Redis, MySQL, remote AI provider, or external MCP server is needed for this local gate. After recording results, confirm no Agent child process remains, then remove only the checked generated temporary directory:

```bash
PHASE25_REAL="$(realpath -- "$PHASE25_DIR")"
HOME_REAL="$(realpath -- "$HOME")"
case "$PHASE25_REAL" in
  "$HOME_REAL"/squehub-phase25.*) rm -rf -- "$PHASE25_REAL" ;;
  *) printf 'Refusing to remove unexpected path: %s\n' "$PHASE25_REAL" >&2; exit 1 ;;
esac
unset PHASE25_REAL HOME_REAL PHASE25_DIR
```

Phase 11G assessment, reconciled 2026-09-26: **NOT READY — BLOCKERS REMAIN**. This is a record of reported checks on the current development source and disposable copies, not a release announcement. This documentation update did not stage, commit, publish, or deploy anything. The [roadmap](Roadmap.md#phase-11g--production-portability-and-release-assessment) assigns the remaining work.

Later Phase 12I checkpoint, 2026-09-27: the Windows working-tree suite passed **1,222 tests, 7,999 assertions, 14 skips**. Standalone PHP SDK generation passed a real local HTTP/Kernel test, and JavaScript passed a local Node/Fetch smoke. TypeScript compilation and Linux/WSL execution for 12I were not run by Codex at that checkpoint. The user later reported that generated clients passed Linux/WSL execution and generated TypeScript compiled; exact later totals and tool versions were not supplied. Phase 12A–12I implementation is present in the working tree, but the release remains **NOT READY**: the source is not reproducible from a clean committed checkout and later release gates remain open. See [client SDK generation](SdkGeneration.md) and the [current feature status](FeatureStatus.md).

Pre-Phase-15 Database/ORM checkpoint, 2026-09-29: Phase 16A–16E is implemented early in the development working tree. Focused Windows/SQLite tests cover nested relations, polymorphic aliases, cursor pages, lifecycle/observers, transaction retries, and a file-backed SQLite lock conflict. Linux/WSL verification and live MySQL qualification of those then-new paths were **NOT RUN by Codex** at that checkpoint; the Phase 11G evidence below predates them. Later user-reported Linux/SQLite and guarded MySQL checks covered selected Phase 16 paths. The user subsequently reported that the final ORM relation pass also passed Linux/WSL and live MySQL; exact later totals, versions, and database setup details were not supplied. The existing source-reproducibility gate remains open, so this checkpoint is **NOT READY** for a v2 release. See [Database](Database.md), [Models](Models.md), [Relationships](Relationships.md), [Pagination](Pagination.md), and [Migrations](Migrations.md).

Phase 13L Activation Registry: the user reported successful Linux/WSL verification after its Windows working-tree implementation. Exact later test totals and environment versions were not supplied. This result does not close clean-checkout reproducibility or the wider release gates. See [Activation Registry](ActivationRegistry.md) and [feature status](FeatureStatus.md).

Phase 15A development-working-tree checkpoint, 2026-09-30: path-first routes now declare Model lookup explicitly with `->bind('parameter', ModelClass::class, key: 'column')`; omitting `key:` uses the Model primary key. Binding runs only for a matched request that passes route middleware. The original scalar route value remains available through `Request::route()`, while the controller receives the resolved Model. Ordinary Model connection and soft-delete rules apply, and a missing row uses the existing browser/API 404 boundary. No registration or static route inspection performs a framework binding query. On Windows, the PHP 8.2.12 / SQLite 3.39.2 working-tree suite passed **2,279 tests, 15,846 assertions, 62 skips, 0 failures, 0 errors, 0 risky**; focused Phase 15A tests passed **16 tests, 99 assertions**. PHPStan passed for `App/Database` at level 5 and for routing/HTTP at level 3. `composer validate --strict` passed. A locked dependency audit reported a separate low-severity `league/flysystem` advisory, CVE-2026-102601. The user reports that Phase 15A Linux/WSL qualification succeeded; Codex did not run or independently inspect that execution, and exact Linux totals and environment details were not supplied in this report. Source reproducibility and the wider release gates remain open. See [Routing](Routing.md#bind-a-route-parameter-to-a-model) and [Models](Models.md#models-in-route-actions).

Phase 15B development-working-tree checkpoint, 2026-09-30: the same path-first route system now declares trailing optional segments, bounded parameter constraints, exact or dynamic host conditions, and explicit static-prefix fallbacks. The matcher checks ordinary routes and 405 before a fallback; rejected constraints perform no Model lookup. Host matching uses the server-supplied Host or server-name fallback, strips an incoming port, and does not trust forwarded-host headers. The current Application Contract and SDK cannot faithfully describe optional-path, host-restricted, or fallback routes, so those routes cannot attach an `OperationContract`. The final Windows suite passed **2,309 tests, 15,995 assertions, 62 skips, 0 failures, 0 errors, and 0 risky tests**. The user-run WSL Ubuntu qualification passed with PHP 8.5.4, Composer 2.9.5, PCRE 10.46, and SQLite 3.46.1: **2,309 tests, 16,199 assertions, 17 skips, 0 failures, 0 errors, and no risky tests reported**. Codex did not run that Linux execution. This implementation does not close source reproducibility or wider v2.0.0 release gates. See [Routing](Routing.md) and [Application Contracts](ApplicationContract.md).

Phase 15C development-working-tree checkpoint: the existing `Response` path adds typed cookies, in-memory binary bytes, local-file attachment/inline delivery, and deferred producer streams. File Range handling is an explicit opt-in when the application passes the current Request; only a bounded single byte range is supported. No streaming View compiler, new HTTP kernel, or new Queue service is introduced. [HTTP Responses](Responses.md) describes the public contracts, file authorization boundary, memory behavior, and HEAD handling. The Windows suite passed **2,332 tests, 16,209 assertions, 62 skips, 0 failures, and 0 errors**; focused new response tests passed **23 tests and 211 assertions**. The user-run Linux/WSL qualification passed **2,332 tests, 16,413 assertions, 17 skips, 0 failures, and 0 errors**, with no risky tests reported. Codex did not run that Linux execution. Phases 15A, 15B, and 15C remain Windows/Linux qualified; this checkpoint does not close the wider release gate.

Phase 15D development-working-tree checkpoint, 2026-09-30: `Config/TrustedProxies.php` keeps all forwarding metadata untrusted by default. An Application may trust exact IPv4/IPv6 peers or CIDRs, select one `Forwarded` or `X-Forwarded-*` profile, and optionally restrict effective Hosts. The immediate `REMOTE_ADDR` gates the policy; client IP resolution walks from the nearest trusted proxy and stops at the first untrusted hop. Request scheme, host, and port integrate with host-restricted routes without trusting direct spoofed forwarding headers. The Windows full suite passed **2,354 tests, 16,327 assertions, 62 skips, 0 failures, 0 errors, and 0 risky**. Focused Phase 15D tests passed **22 tests, 105 assertions**; broader HTTP/routing/security checks passed **173 tests, 1,474 assertions, 4 skips**. The user-run Linux/WSL qualification passed **2,354 tests, 16,531 assertions, 17 skips, 0 failures, and 0 errors**, with no risky tests reported. Codex did not run that Linux execution. The current Doctor smoke reported an unavailable default database, an environment condition unrelated to proxy trust. See [HTTP request metadata](Http.md#trusted-proxies-and-request-metadata), [Routing](Routing.md#host-conditions), and [Deployment](Deployment.md#reverse-proxies-and-allowed-hosts). This checkpoint does not close the wider release gate.

Phase 15E development-working-tree checkpoint, 2026-09-30: `APP_BASE_PATH`/`Config/Http.php` now selects an explicit URL mount, with the root deployment as the default. The Kernel maps a bounded public request path to an application-relative route path; named routes, internal redirects, local View assets, the global `asset()` helper, and the built-in development server's mounted public assets use the public prefix. Route definitions, API scopes, and contract operation paths remain application-relative. Session and typed-cookie Paths remain explicit. The post-change Windows suite passed **2,380 tests, 16,488 assertions, 63 skips, 0 failures, 0 errors, and 0 risky tests**; `composer validate --strict` passed. Focused Phase 15E tests passed **28 tests, 151 assertions, 1 skip**; Windows could not create the outside-assets symlink needed for that one security case; the later user-run WSL qualification exercised the Linux path after its fixture cleanup fix. A separate isolated Windows Apache 2.4.58/XAMPP mod_php smoke used a temporary loopback-port configuration and a public-only `/app/` Alias: the root View returned 200, a known public asset returned its exact 263 bytes with 200, missing browser and API paths returned their respective HTML/JSON 404s, and a request outside the mount returned 404 without exposing source text. It did not exercise named-route or redirect behavior through live Apache; those remain Windows Kernel-tested. The process was stopped and temporary files removed without altering global Apache configuration or MySQL. The user subsequently reported successful WSL/Linux and real XAMPP verification after the symlink-cleanup and public-asset corrective follow-ups. Exact post-correction WSL totals and XAMPP request details were not supplied in this record. Linux Apache and real cPanel/shared-host deployment remain unverified. The earlier Phase 11G Apache test proved document-root hosting only. Source reproducibility and the wider release gates remain open. See [mounted requests](Http.md#url-base-path-and-mounted-requests), [Routing](Routing.md#names-and-urls), and [Deployment](Deployment.md#subdirectory-mounts-and-shared-hosting).

Phase 17A–17E development-working-tree checkpoint, 2026-09-30: optional remembered browser login, explicit RBAC fallback, TOTP MFA, an opt-in browser response security policy, and purpose-bound signed URLs are implemented. The final Windows PHP 8.2.12 / SQLite 3.39.2 suite passed **2,471 tests, 17,442 assertions, 69 skips, 0 failures, 0 errors, and 0 risky tests**. The broad Auth/routing/security filter passed **891 tests, 6,281 assertions, 33 skips**. `composer validate --strict` and PHPStan level 5 for touched Phase 17 source passed; a wider static pass still reports 14 findings in pre-existing Account Security, identity-provider, token, and legacy router code. Guarded MySQL tests for the new remember-token, RBAC, and MFA persistence safely skip without disposable opt-in variables. At this Windows checkpoint, Codex had not run Linux/WSL or live MySQL; the later user-run results below closed those Phase 17 gates. Earlier user-reported Linux passes for Phase 12I, Phase 13L, Phase 15E, and the final ORM relation pass, plus a live MySQL pass for that ORM relation work, do not qualify these new security paths. The working tree still does not reproduce from a clean committed checkout. See [Authentication](Authentication.md), [RBAC](RBAC.md), [MFA](MFA.md), [Browser Security Policy](BrowserSecurityPolicy.md), and [Signed URLs](SignedUrls.md).

Final Phase 17 qualification reported after that checkpoint: the normal Linux/WSL suite passed **2,471 tests, 17,656 assertions, 20 skips, 0 failures, and 0 errors**. Guarded live MySQL 8.4.11/InnoDB tests passed for remember-me (**1 test, 63 assertions**), RBAC (**1 test, 93 assertions**), and MFA (**1 test, 39 assertions**). The full Linux suite with MySQL opt-in passed **2,471 tests, 18,107 assertions, 12 skips, 0 failures, and 0 errors**. The temporary database and users were removed, the SQUEHUB_TEST_MYSQL_* variables were unset, and the final normal Linux suite returned to **2,471 tests, 17,656 assertions, 20 skips, 0 failures, and 0 errors**. Phase 17A–17E is **complete** within those reported Windows, Linux/WSL, and applicable live MySQL profiles. Codex did not run the Linux or MySQL sessions. This qualification does not resolve clean committed-checkout reproduction or declare a v2.0.0 release.

Phase 18A–18D Windows working-tree checkpoint, 2026-09-30: Queue worker operations, chains/batches, opt-in queued Event listeners, and optional Broadcasting are implemented on the existing Queue/Event architecture. The PHP 8.2.12 / PDO SQLite 3.39.2 full suite passed **2,540 tests, 17,924 assertions, 72 skips, 0 failures, 0 errors, and 0 risky tests**; a broad regression passed **741 tests, 5,478 assertions, 21 skips**. Focused gates passed: Queue operations **42 tests/271 assertions/2 skips**, composition after Redis retention and migration rollback corrections **55/418/5 skips**, queued Events **53/198**, and Broadcasting **18/115**. A separate PHP enqueue/worker process test covered queued listener delivery and safe failure output; coordinated SQLite Queue composition worker tests covered reservation races. The guarded new MySQL and Redis composition tests skipped without explicit disposable opt-in. **Linux/WSL, live MySQL composition, and live Redis composition remain pending user-run qualification**. Core Broadcasting ships Array/Null adapters only; no external provider or WebSocket compatibility was tested. `composer validate --strict` passed. The locked dependency audit reported one separate low-severity `league/flysystem` advisory, CVE-2026-102601. No new Composer dependency was added. Phase 18 is not yet cross-platform or live-backend qualified, and the source still cannot be reproduced from a clean committed checkout. See [Queue](Queue.md), [Queue composition](QueueComposition.md), [Events](Events.md#queued-class-listeners), and [Broadcasting](Broadcasting.md).

Final Phase 18 qualification reported by the user, 2026-09-30: **Phase 18A–18D are complete.** Native Linux/WSL PHP 8.5.4 focused tests passed: 18A **42/271/2 skips**, 18B **28/256/3 guarded skips**, 18C **53/198**, and 18D **18/115**; broad regression passed **741/5,514/16 skips**. Normal full Linux passed **2,540 tests, 18,138 assertions, 23 skips, 0 failures, 0 errors**. Live disposable MySQL 8.4.11/InnoDB composition passed **1 test/66 assertions**, followed by a full **2,540/18,655/14 skips** suite. The selected live Redis client composition path passed **30 assertions**; an alternate optional client dataset skipped, followed by a full **2,540/18,709/10 skips** suite. The user verified the disposable MySQL database and users were removed, Redis DB 15 had zero keys, and all `SQUEHUB_TEST_*` variables were unset. One first post-cleanup full run observed `SchedulerCliTest::testListDoesNotExecuteAndRunClaimsOnceWithPrivateFailure` crossing a wall-clock occurrence boundary. Its exact test then passed **1/10**, its file **3/32**, five repeats **5/50**, and the subsequent clean full Linux suite passed **2,540/18,138/23 skips** with zero failures or errors. Keep Scheduler occurrence-boundary test determinism under review; do not erase this historical observation. Codex did not run Linux, MySQL, or Redis for this qualification. Phase 18 completion does not close clean committed-checkout reproducibility, external Broadcasting provider interoperability, or v2 release approval.

Phase 19 Windows development-working-tree checkpoint, 2026-10-01: 19A bounded Application-owned observations, 19B correlation propagation, 19C opt-in local profiler, 19D private route/config artifacts, and 19F loopback-only read-only Studio are implemented. The 19E aggregate `optimize` command was evaluated and deferred because the separate cache builders cannot yet guarantee all-or-nothing activation. The earlier PHP 8.2.12 / PDO SQLite 3.39.2 Windows suite passed **2,628 tests, 18,600 assertions, 75 skips, zero failures, zero errors, and zero risky tests** in **4:28.613** with **90 MB** peak PHPUnit memory. Focused Studio checks then passed **15 tests/139 assertions**, including a real loopback server test **1/50** for mounted pages/assets, Host and source-file denial, and live production/disabled policy. Subsequent Phase 19F qualification exposed a Routes-page gap: `route:list` saw the registered Closure-backed welcome route while Studio depended on a route-cache snapshot and displayed no records. The corrected Studio now uses RouteRegistry inspection for Routes and contract metadata and the shared ScheduleLoader for Scheduler definitions. The corrective Windows full suite passed **2,640 tests, 18,677 assertions, 75 skips, zero failures, zero errors, and zero risky tests** in **4:32.963** with **90 MB** peak PHPUnit memory. New route and Scheduler inspection tests passed **11 tests/59 assertions**; the broad Studio/routing/contract/Package/Kit/Scheduler regression passed **400 tests/2,766 assertions/20 skips**. A live Windows Studio server against this checkout served `/studio/routes` with HTTP 200 and displayed `welcome.page`, `Closure`, and `Project/Routes/Web.php` rather than an empty section; `/studio/scheduler` and `/studio/contract` also returned 200, and the test server was stopped. An earlier initial full run exposed cross-Application environment publication leakage; `Environment::get()` was corrected to ignore only prior Application-published `$_ENV` fallbacks while retaining shell overrides, and a focused **61-test/485-assertion/one-skip** regression passed before the earlier clean full run. The user-run pre-correction Linux full suite passed 2,628 tests, 18,816 assertions, 23 skips, zero failures, and zero errors. The user subsequently reported that corrective Linux/WSL tests, the full corrective suite, and live Studio inspection passed; exact post-correction totals were not supplied. Phase 19 is complete, including Linux/case-sensitive route and configuration cache qualification. No external OpenTelemetry collector, new live MySQL/Redis path, production Studio remote-access profile, or clean committed-checkout reproduction was run by Codex. The v2.0.0 release remains **NOT READY** for the existing distribution and later-phase gates; this does not change Phase 19 completion. See [Observability](Observability.md), [Correlation](Correlation.md), [Profiler](Profiler.md), [framework performance caches](PerformanceCaching.md), and [Studio](Studio.md).

Phase 20 — Internationalization and Provider Ecosystem: **COMPLETE** in the development working tree. The pre-change Windows PHP 8.2.12 / PDO SQLite 3.39.2 full suite passed **2,640 tests, 18,677 assertions, 75 skips, zero failures, zero errors, and zero risky tests**. The final Windows suite passed **2,691 tests, 19,059 assertions, 79 skips, zero failures, zero errors, and zero risky tests**. Focused Windows checks passed 20A normal PHP **12/97**, 20A with explicitly enabled `ext-intl` **12/105**, 20B S3 **9/69/1 guarded skip**, 20C provider Mail **19/127/2 guarded skips**, and 20D Memcached **12/56/1 guarded skip**. The default Windows PHP CLI lacked `ext-intl` and `ext-memcached`; its focused optional-extension tests were run under separate configurations. Additional Queue brokers were evaluated and deliberately deferred. At that Phase 20 checkpoint, the locked Composer audit reported the separate low-severity `league/flysystem` advisory, CVE-2026-102601; no first-party Phase 20 Storage source used Flysystem.

The user-run native Linux/WSL qualification used PHP 8.5.4, SQLite 3.46.1, and enabled ext-intl. Focused Internationalization passed **12 tests, 105 assertions**; a broad Phase 20 regression passed **1,210 tests, 8,581 assertions, 13 skips**. The initial full Linux suite passed **2,691 tests, 19,283 assertions, 27 skips, zero failures, zero errors**. Following guarded provider tests, all `SQUEHUB_TEST_*` variables were unset, MinIO's owned prefix and temporary bucket/data were cleared, and MinIO and Memcached were stopped. The final normal Linux suite passed **2,691 tests, 19,277 assertions, 28 skips, zero failures, zero errors**. Codex did not run these Linux or live-provider sessions. These bounded results complete Phase 20 but do not close the separate clean-checkout and deployment release gates. See [Internationalization](Internationalization.md), [provider-backed Storage](ProviderStorage.md), [provider-backed Mail](ProviderMail.md), [Cache](Cache.md), and [feature status](FeatureStatus.md).

| Phase 20 capability | User-reported qualification | Remaining boundary |
| --- | --- | --- |
| 20A Internationalization | Native Linux/WSL ICU/ext-intl focused run: **12 tests/105 assertions**; full Linux suite passed. | Optional ICU formatting still requires ext-intl on each deployment host. |
| 20B S3-compatible Storage | Guarded live S3-compatible MinIO: **1 test/27 assertions**; owned prefix empty and temporary service/resources cleaned up. | AWS S3 itself was not tested; MinIO qualifies S3-compatible interoperability only. |
| 20C Resend/Postmark Mail | Actual SqueHub adapters each passed a guarded live-provider **1 test/1 assertion** run. | Postmark's pending account approval restricted cross-domain sending; its same-domain adapter path passed. No general provider SLA or all-recipient delivery claim. |
| 20D Memcached Cache | Linux Memcached server 1.6.40 and PHP ext-memcached 3.4.0: **7 tests/14 assertions/1 intentional skip**; isolated namespace, no `flush_all`, service stopped afterward. | Skipped negative-path case required an absent extension and correctly skipped while it was installed. No multi-server guarantee. |

## Supported and verified environments

Composer declares `php: ^8.2`. The corrected full suite passed on Windows with PHP 8.2.12 and PDO SQLite 3.39.2, and on WSL 2 Ubuntu 26.04.1 with PHP 8.5.4 and SQLite 3.46.1. The Linux snapshot under `/home/val/projects/squehub-v2-releasegate` used a confirmed case-sensitive filesystem. The WSL environment reported kernel 6.18.33.2-microsoft-standard-WSL2, Composer 2.9.5, cURL 8.18.0, OpenSSL 3.5.5, and sodium enabled. These runs prove their stated environments, not every PHP patch release or operating system; macOS remains unverified. A later guarded run used real MySQL 8.4.11/InnoDB on WSL. A separate Windows MariaDB service is not a MySQL qualification result.

The clean working-tree source snapshot was copied outside the repository without `.git`, `.env`, `vendor`, or root runtime `Storage`. It retained `App/Storage`, which is framework source. Composer installed 109 locked packages anew. After the focused Linux portability fix was synchronized, the corrected WSL suite passed **725 tests, 4,886 assertions, 7 skips, 0 failures, 0 errors, 0 risky**; the active Windows tree passed **725 tests, 4,879 assertions, 9 skips, 0 failures, 0 errors, 0 risky**. Different platform capabilities explain the skip and assertion differences. The active development tree's `vendor` directory was not used for the Linux snapshot. A fresh committed checkout cannot reproduce this v2 implementation: current `HEAD` contains only 80 files, while essential v2 source and `composer.lock` remain untracked.

| Capability | Result | Evidence and limit |
| --- | --- | --- |
| Windows, PHP 8.2.12 | PASS | Corrected full suite: 725 tests, 4,879 assertions, 9 skips; CLI and disposable listening HTTP server. |
| Linux, PHP 8.5.4 | PASS | WSL 2 Ubuntu 26.04.1; corrected full suite: 725 tests, 4,886 assertions, 7 skips. macOS remains unverified. |
| Case-sensitive execution | PASS | Full corrected suite on native WSL filesystem under `/home/val`; differently cased test filenames coexisted with distinct contents. |
| SQLite 3.39.2 / 3.46.1 | PASS | Windows and Linux full suites; Windows disposable database, Model, migration, Queue, and Scheduler smoke. |
| Live MySQL 8.4.11/InnoDB | PASS for guarded coverage | Disposable `squehub_test_11g` and restricted user were removed after testing. `MySqlSchemaOptInTest`: 1 test, 52 assertions. Full Linux suite with MySQL enabled: 725 tests, 4,938 assertions, 6 skips, 0 failures/errors/risky. Covers the test's Model, QueryBuilder, Schema/FK, transaction, migration, failure, and cleanup paths; does not qualify every MySQL topology or MariaDB. |
| Redis optionality when unavailable | PASS | Earlier CLI and shared-hosting-style checks booted without Redis; optional Redis did not prevent unrelated services. This is distinct from the later live PhpRedis result. |
| Live Redis via PhpRedis | PASS for existing live contracts | Redis Server 8.0.5, PhpRedis 6.2.0, PHP 8.5.4, isolated DB 15. `RedisLiveTest`: 1 test/8 assertions; `RedisSubsystemLiveTest` PhpRedis data set: 7 assertions; `RedisQueueLiveTest` PhpRedis data set: 9 assertions, including atomic claim, stale recovery, and process boundary. Combined Linux + MySQL + Redis suite: 725 tests, 4,962 assertions, 3 skips, 0 failures/errors/risky. |
| Live Redis via Predis | NOT VERIFIED | `predis/predis` was not installed in the live verification environment; deterministic paths do not establish a live-server result. This is an optional qualification gap, not a PhpRedis failure. |
| Redis auth/TLS and wider topology | NOT VERIFIED | The bounded live tests do not establish credential/TLS behavior, distributed multi-host operation, or production worker supervision. |
| Windows Apache/XAMPP document root | PASS with limit | Apache 2.4.58, PHP 8.2.12, OpenSSL 3.1.3. Fresh deployment copy passed Composer install/strict validation and a 725-test/4,881-assertion/9-skip suite; optimized autoload contained about 5,607 classes. Incomplete `.env` returned the setup-required 503; correctly configured root placement loaded at `http://localhost/`. Subdirectory URL hosting was not proved. |
| Database Queue | PASS | Disposable migrations, dispatch, and a separate `queue:work --once` process. |
| Scheduler | PASS | Database store, one due execution, then duplicate-occurrence skip in the same minute. |
| Local HTTP Client | PASS | Focused local transport suite: 4 tests, 47 assertions. |
| HTTPS certificate and hostname verification | NOT VERIFIED | No controlled HTTPS fixture was run in this gate. |
| Plain local SMTP | PASS | Focused local SMTP suite: 5 tests, 37 assertions. No external provider delivery. |
| STARTTLS / SMTPS successful delivery | NOT VERIFIED | A negative STARTTLS safety path ran; this is not a successful TLS handshake. |
| Crypt sodium / OpenSSL | PASS on Windows and Linux | Windows focused driver tests: 13 tests, 123 assertions; both drivers also ran in the Linux full suite. An actual sodium-absent host remains unverified. |
| Doctor | PASS | Windows disposable profile: 13 passes, one optional Mail warning, one optional Redis skip. Linux disposable SQLite profile: 11 passes, three optional/setup warnings, one Redis skip; missing `.env` correctly failed. |
| Health endpoints | PASS | Real HTTP: `/health/live` 200, `/health/ready` 200; broken required DB readiness 503. JSON and `no-store` observed. |
| Clean source snapshot | PASS | Fresh locked Composer install, optimized autoload, full suite and CLI; this is separate from a clean committed checkout. |
| Clean committed checkout | BLOCKER | Required v2 code is absent from `HEAD`. |
| Fresh application smoke | PASS with limits | Disposable current-source project exercised route, view, database, migration, Model, validation, configured Auth, Cache, Session, Rate Limit, Storage, Crypt, Array Mail, Array Notification, local HTTP Client, database Queue, worker, Scheduler, Doctor, Health, and the Redis gateway without a live connection. This was not a published `create-project` install. |

After the live MySQL/Redis tests and environment cleanup, the normal Linux suite returned to **725 tests, 4,886 assertions, 7 skips, 0 failures, 0 errors, 0 risky**. Live service results did not alter the default local test path.

## Requirements and deployment profiles

The project needs PHP and the extensions required by its selected Composer packages and services. The tested CLI had PDO SQLite, PDO MySQL, cURL, sodium, OpenSSL, and ZIP. SQLite and MySQL drivers are alternatives for their respective database configurations. cURL supports the current HTTP Client. Crypt's `auto` mode prefers sodium and can use OpenSSL; deploy with at least one supported cryptographic backend when using Crypt. ZIP or an archive extractor is needed for Composer distribution downloads. Redis requires a reachable server and a supported client. PhpRedis passed the bounded live runs above; Predis was absent from that environment. Redis is optional for a basic application. SMTP is optional until Mail sends a message.

For conventional shared hosting, use a reachable configured database, a writable native PHP `session.save_path`, file Cache and Rate Limit stores, local Storage, and database Queue and Scheduler tables if those features are enabled. A PHP process without a writable session directory returned a 500 for an ordinary route in the disposable server; configuring a writable protected session path made the route return 200. Health liveness can pass while readiness fails when a required dependency is unavailable. Optional SMTP need not block boot.

The proposed Redis/VPS profile selects Redis Cache, Session, Rate Limit, and Queue with database Scheduler. The existing PhpRedis live tests cover specified service and Queue paths, including a process boundary; they do not prove an entire VPS deployment, Redis authentication/TLS, Predis, or every worker topology. Explicit `redis` configuration is intended to fail when unavailable; `auto` fallback behavior has isolated coverage. Keep these claims separate from the bounded live result.

No empty runtime directory should be committed to make the Application boot. Runtime Storage, logs, cache, rate-limit records, Queue markers, and compiled views must be writable where used and ignored by Git. Keep framework source directories such as `App/Storage` in the distribution. `.example.env` is a template, not a credential file: copy it to `.env`, generate `APP_KEY` with `php squehub key:generate`, and review DB, session, driver, Mail, and health settings. Do not copy a development `.env` into a release or test snapshot.

## Verification commands

Run these against each claimed platform and a clean distribution copy:

```text
composer install --no-interaction --prefer-dist --no-progress
composer validate --strict
composer dump-autoload -o --strict-psr
composer test
php squehub
php squehub list --raw
php squehub route:list
php squehub doctor
php squehub doctor --json
php squehub infrastructure
php squehub queue:work --help
php squehub schedule:run --help
php squehub schedule:list --help
git diff --check
```

Run destructive migrations and worker execution only against a disposable application database. Use the existing guarded MySQL opt-in suite only with its designated empty `squehub_test_*` database. Do not point it at ordinary application `DB_*` values. For Redis, use an isolated test server and namespace and record server version, client, authentication, and TLS mode without printing credentials. Check real process boundaries, not only in-process unit tests.

## Packaging and release blockers

1. **Required source is uncommitted.** The working tree has hundreds of untracked files; a normal clone of current `HEAD` lacks key `App`, `Bootstrap`, `Config`, and `Project` v2 files and `composer.lock`. A passing working tree or copied snapshot cannot fix this.
2. **The v2 Git ignore policy has been corrected, but the exact candidate is not yet qualified.** `Docs/`, `Tests/`, and `phpunit.xml.dist` are candidate release source. Generated portal output, private `phpunit.xml`, `.env`, dependencies, and runtime data remain excluded. Confirm their presence and behavior in a fresh checkout of the release commit before claiming reproducibility.
3. **Some optional integrations still need scoped qualification.** Guarded MySQL, PhpRedis, Phase 20 S3-compatible MinIO, Memcached, Resend, and Postmark adapter paths passed within their documented limits. Predis live execution, Redis authentication/TLS, successful SMTP TLS, AWS S3 itself, Postmark cross-domain sending pending provider-account approval, controlled HTTPS, macOS, Linux Apache, and real shared-host/cPanel URL-subdirectory deployment have no equivalent pass. Phase 15E's isolated Windows Apache smoke covers a bounded `/app/` profile, not every production mapping or feature path. Decide which claims are required for the first release and test each claimed profile explicitly.

The release gate remains closed until the intended source is represented in a reproducible distribution and the claimed supported environments pass. Resend and Postmark passed guarded provider-adapter tests, but broader external delivery topologies, Redis TLS/auth, macOS, and successful STARTTLS/SMTPS remain explicit verification gaps. They should be described accurately even if a narrower initial release scope is chosen. Phase 13A's five code generators are implemented in this development working tree; that does not establish a published v2 distribution or qualify Linux execution for those generators. See [Generators](Generators.md).

## Compatibility fix found during verification

The shipped `.example.env` left `QUEUE_DATABASE_CONNECTION` blank, but the database Queue treated that blank string as a named connection and failed. `Config/Queue.php` now maps an empty value to `null`, selecting the configured default database connection. Two isolated regression tests cover the blank and explicit-name cases. The disposable worker then completed an enqueued job from a separate PHP process. `Query` and worker semantics were otherwise unchanged. `.example.env` now lists all environment keys found in the current `Config` files, with optional keys commented where an empty value would not be a valid setting. A stale PHPStan suppression in the Predis adapter was removed; no Redis runtime behavior was changed by that edit.

The first case-sensitive Linux run exposed three narrower portability issues: a Windows-specific log-path test ran on Linux, Response tried to set the HTTP status after PHPUnit had already sent output, and the Storage symlink test could reject links without recording assertions. Logging now accepts host-native absolute paths, with platform-specific tests for Windows drives and POSIX paths. Response checks `headers_sent()` before changing status or headers; its body and HEAD behavior remain independent. The Storage test now asserts both read and listing rejection. The corrected Linux full suite has no failures, errors, or risky tests.

## Compatibility policy for v2

SqueHub v2 should follow semantic versioning for documented public behavior. The public application API includes the documented `App\Plugins` symbols, documented canonical subsystem classes and helpers, CLI commands and options, configuration keys, project conventions, and extension contracts such as providers, Queue jobs, and packages. Internal classes are not automatically stable simply because they are autoloadable. A compatible patch should preserve working application code except where a security or data-integrity fix requires a narrowly explained change. Minor releases may add capabilities and deprecate old forms. Intentional removals or signature changes need a major version, a migration path, and advance deprecation where feasible. Legacy wrappers may remain while they are useful, but v2 does not promise perpetual compatibility with every undocumented v1 internal.

Security fixes can require urgent behavior changes in a patch release. Such changes need a clear advisory and migration guidance. Documentation must describe implemented behavior, label experimental work, and keep v1 public documentation separate. A release tag, Composer publication, and public stability claim require a separate explicit release decision after this gate passes.

## Audit notes

All 32 registered CLI commands returned help successfully. Seventy `App/Plugins` PHP symbols resolved, and representative application-facing imports resolved to their canonical services. The targeted PHPStan run passed at level 3; level 4 reported two existing defensive/control-flow findings in Queue code requiring separate review. First-party PHP lint passed for 444 files before the final small configuration fix, and changed PHP files were linted again. The internal Docs link scan found no broken relative links across 45 guides; documented CLI commands checked existed. A fake Redis credential placed in a disposable `.env` did not appear in failing Doctor JSON output. A narrow common-secret-signature scan found no candidate source or example file. These checks do not constitute a comprehensive secret audit. Composer audit reported no known advisories for the locked dependencies at verification time.

`git diff --check` found no whitespace errors in tracked changes; Git emitted line-ending warnings from the Windows working tree. Existing trailing whitespace outside Phase 11G remained in a few legacy source lines and Markdown hard line breaks. This gate did not churn those files. Internal timing observations (roughly 279–444 ms for selected CLI startup commands on this host) are sanity measurements only, not benchmarks or release performance promises.

See [Stabilization](Stabilization.md) for earlier source-snapshot and runtime-directory details, [Plugins](Plugins.md) for the application namespace, and the subsystem guides for their exact current APIs.
