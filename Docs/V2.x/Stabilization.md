# SqueHub v2 core stabilization gate

This guide records the earlier Phase 8E source-snapshot gate and the completed Phase 26 stabilization audit. The Phase 26 native Linux, live MySQL, live PhpRedis, and no-dev results were supplied after the Windows checkpoint; Codex did not run those Linux qualifications. [Release readiness](ReleaseReadiness.md) records the wider release decision. Earlier qualification does not automatically qualify a later source snapshot.

## Phase 26 stabilization — complete

Phase 26A–26E are **complete, tested, and qualified in the recorded Windows and native Linux profiles**. The pre-change Windows baseline passed **2,946 tests, 22,117 assertions, 90 skips, zero failures, zero errors, and zero risky tests** on PHP 8.2.12 and PDO SQLite 3.39.2. The final Windows suite passed **2,955 tests, 22,755 assertions, 90 skips, zero failures, zero errors, and zero risky tests**. The user-run native Linux qualification used PHP 8.5.4, SQLite 3.46.1, and a confirmed case-sensitive filesystem: focused stabilization passed **45 tests and 1,292 assertions**, with zero failures or errors. This is a first-party audit, not penetration testing, legal certification, or v2 release approval.

### Public API and security

The [public API audit](PublicApiAudit.md) classifies all 142 Plugins symbols, 53 helpers, 72 CLI commands, 39 configuration files, and representative HTTP, ORM, Package, and Kit contracts. The canonical routing API remains `Route::path(...)->get(...)->named(...)->through(...)`; six unreleased static verb shortcuts were removed from the Plugins gateway. The v1 `$router` bridge remains explicitly legacy. The `lock()` helper and eight existing Plugins symbols were added to their previously incomplete reference tables.

The security audit found a high-impact legacy Mail template defect: a caller-provided `content` key could overwrite the trusted template source before PHP evaluated it. The renderer now compiles the file source before extracting data into a separate scope; a regression proves that PHP syntax in `content` is escaped as data. An outbound HTTP redirect could also carry custom credential headers or a 307/308 body to a different origin; automatic following now stops at that boundary. Origin comparison normalizes the URL scheme before choosing its default port. The broader security matrix passed **1,122 tests, 9,317 assertions, 42 skips, zero failures, and zero errors** on Windows. This matrix exercises Auth, tokens, MFA, RBAC, CSRF, CORS, proxies, Storage, HTTP, Webhooks, Queue, Agent, Locks, Idempotency, and related boundaries. It is regression evidence, not penetration testing.

The outbound HTTP Client no longer follows redirects to another origin automatically: arbitrary application headers and 307/308 request bodies may contain credentials, so selective stripping is insufficient. It returns the 3xx response for application validation and a separate destination request; [HTTP Client](HttpClient.md#transport-tls-redirects-and-retries) documents the contract. Same-origin redirects still follow. `maxResponseBytes()` limits buffered responses, while streamed sinks require an application-owned size and disk-capacity policy.

### Compatibility evidence

| Profile | Phase 26 evidence and limit |
| --- | --- |
| Windows PHP 8.2.12, PDO SQLite 3.39.2 | Baseline, focused security/public-API/lifecycle suites, real SQLite Queue/HTTP tests, and final `composer test` passed (2,955 tests, 22,755 assertions, 90 skips). |
| Native case-sensitive Linux PHP 8.5.4, SQLite 3.46.1 | User-run Phase 26 focused stabilization passed (45 tests, 1,292 assertions; zero failures/errors). The full Linux suite with live MySQL opt-in passed (2,955 tests, 23,572 assertions, 20 skips; zero failures/errors); after cleanup and variable removal it passed again (2,955 tests, 23,004 assertions, 31 skips; zero failures/errors). |
| MySQL 8.4.11, PDO MySQL, InnoDB | User-run guarded representative qualification passed (8 tests, 448 assertions; zero failures/errors) for Schema, query hardening, API tokens, RBAC, MFA, Queue composition, Locks, and Idempotency. The disposable database and user were removed and opt-in variables cleared. This does not qualify MariaDB or an application database. XAMPP MySQL was not changed. |
| Redis 8.0.5 with PhpRedis | User-run live suite passed (8 tests, 68 assertions, three alternate-client skips; zero failures/errors). Cleanup was verified while Redis was reachable: zero Phase 26 keys remained, then the disposable server was stopped, its temporary directory removed, and test variables cleared. Live Predis and Redis auth/TLS remain separate claims. |
| Apache and shared hosting | Apache 2.4.58 is installed, but existing Apache processes prevented a new isolated smoke without touching their sites. A disposable PHP-only copy passed CLI, SQLite Doctor, and a rendered local web request without Node, Redis, workers, or MCP. Real cPanel remains unverified. |
| Node-optional PHP mode | With Node hidden from `PATH`, ordinary `php squehub` and `route:list` passed in the disposable copy. |
| MCP-optional production mode | A Windows working-tree copy and a separate user-run disposable Linux copy completed `composer install --no-dev --no-interaction`. On Linux, normal CLI, `route:list`, Doctor, SQLite, Composer validation, and Composer audit passed; `mcp/sdk` was absent and Agent status safely reported `mcp_sdk_available=false`. The audit returned zero advisories. This is not clean committed-`HEAD` reproduction. |
| macOS | Not run. |

The disposable no-dev install needed the host's available ZIP DLL enabled **for that Composer process only** and approved package-network access. The host's default session directory was unwritable in the sandbox, so the local web smoke used a writable temporary `session.save_path`. Neither condition required a framework or global PHP configuration change. The copy, child server, and temporary state were removed.

### Long-running lifecycle measurements

Windows PHP 8.2.12 measurements below use `memory_get_usage(true)` and `memory_get_peak_usage(true)` after an explicit warmup. They are diagnostic samples, not comparative performance claims. The [lifecycle regression](../../Tests/Integration/StabilizationLifecycleTest.php) uses a generous 32 MiB post-warmup allowance to catch retained Applications or worker state without depending on allocator granularity.

| Workload | Iterations | Start | Post-warmup | End | Peak | Elapsed |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Fresh Applications and HTTP requests | 32 Applications / 128 requests | 6 MiB | 10 MiB | 10 MiB | 10 MiB | 1,716.79 ms |
| Persistent database Queue worker | 64 jobs / 66 attempts, including retry and terminal failure | 10 MiB | 10 MiB | 10 MiB | 10 MiB | 19.25 ms |
| Repeated Scheduler evaluation | 100 ticks / 100 executions / one definition | 6 MiB | 6 MiB | 6 MiB | 6 MiB | 2.27 ms |
| Agent framework resource | 261 reads in one Application | 10 MiB | 10 MiB | 10 MiB | 10 MiB | 1,972.21 ms |
| Persistent in-process MCP STDIO adapter | 32 resource calls after five warmup reads | 10 MiB | 10 MiB | 12 MiB | 12 MiB | 336.35 ms |

The focused test also checks request locale, Diagnostics, View request scope, route isolation, Queue locale cleanup after success/retry/failure, unchanged Scheduler definition count, stable MCP capabilities, bounded responses, and secret-sentinel absence. Existing Queue Notification, typed payload, Config/Route/View cache, frontend manifest, and HTTP retry tests remain part of broad/full regression. The small timing sample does not prove every production worker or multi-server deployment has a fixed memory ceiling.

The Windows live MCP test's earlier ten-second Symfony process timeout covered its entire multi-request server lifetime. Under a full-suite run it expired even though the same test passed alone. Its test harness now allows 30 seconds total while retaining a ten-second idle timeout for a stalled exchange. This changes verification tolerance, not the server protocol. A later direct PHPUnit run and the final logged `composer test` both passed the complete suite.

### Dependencies and open gates

The unused vulnerable `league/flysystem` 2.5.0 and its orphan `league/mime-type-detection` were removed using Composer. The root explicitly disallows execution of the unnecessary `php-http/discovery` Composer plugin. Noninteractive development installation, strict validation, optimized autoload, locked audit, and licenses passed; the current locked audit reports **zero advisories and zero abandoned packages**. `mcp/sdk` 0.8.1 remains an optional development dependency and Composer suggestion. PHPMailer's LGPL-2.1-only runtime license and Phan's OSL-3.0 transitive development license are recorded as inventory, not legal conclusions. See [Provider Storage](ProviderStorage.md) and [Release readiness](ReleaseReadiness.md).

Phase 26A–26E are complete in the recorded Windows/Linux, live MySQL, live PhpRedis, and no-dev profiles. The user removed the main and no-dev Linux qualification directories; no Phase 26 qualification directories remained. Isolated Apache, macOS, real cPanel, live Predis, Redis auth/TLS, and wider deployment proof remain open where indicated. Clean committed-checkout reproduction, published `composer create-project`, public v2 docs, and final tag/release approval remain separate Phase 27/28 gates.

The v2 core remains unreleased. This document's earlier Phase 8E/26 copy-based checks predate the local `release/v2.0.0` candidate, which now tracks source, tests, configuration, documentation, and `composer.lock` and has been cloned independently on Windows and native Linux. That progress does not substitute for the final exact-commit checks, public Composer installation, public documentation, and explicit release approval in [Release readiness](ReleaseReadiness.md).

## Clean-source check

1. Copy the intended source tree to an isolated temporary directory outside the repository. Exclude `.git`, `.env`, `vendor`, the **root** runtime `Storage` directory, generated caches and logs, coverage, IDE metadata, and `project-backup`. Keep `App/Storage`: it is framework source. Compare the source file inventories before testing.
2. In that copy run `composer validate --strict`, `composer install` from `composer.lock`, then `composer test`.
3. Run `php squehub`, `php squehub list --raw`, `php squehub route:list`, and representative `--help` paths. Use `CACHE_DRIVER=array` for a safe `cache:clear` smoke test.
4. Verify HTTP boot with a non-database route and a production-mode error response. Recheck package loading and the source casing manifest before release.

Do not copy an existing `vendor` directory into this check. Dependency ZIP installation requires PHP `zip` or an unzip/7z tool; source installation requires Git and access to locked package repositories. The earlier Phase 8E host had `ZipArchive` enabled; the current Phase 26 Windows CLI reports it disabled, and the isolated install used `-d extension=zip` without changing `php.ini`. The Phase 8E snapshot installed 109 locked packages with `composer install --no-interaction --prefer-dist --no-progress`, regenerated autoload, and passed 415 tests with 2,682 assertions and 3 environment skips. The first snapshot test attempt exposed an overbroad `Storage` exclusion that also removed `App/Storage`; restoring those 11 source files and rechecking the inventory resolved the failures.

## Verified and unverified platforms

The corrected working-tree suite runs on Windows with PHP 8.2.12 and PDO SQLite 3.39.2. PHP 8.2 is the declared minimum. A later WSL 2 snapshot ran the corrected full suite on Ubuntu 26.04.1, PHP 8.5.4, and a confirmed case-sensitive native Linux filesystem under `/home/val`; see [Release readiness](ReleaseReadiness.md). The host's default Windows `session.save_path` was not writable in the isolated web run; the direct web smoke passed with `php -d "session.save_path=<writable temporary directory>" public/index.php` while MySQL was pointed at unavailable port 1. Deployment PHP must likewise have a writable, appropriately protected session directory.

The MySQL test is opt-in and requires an explicit, confirmed empty `squehub_test_*` database. Normal application `DB_*` settings are never a substitute. This earlier gate had only SQL compilation and SQLite execution; a later guarded MySQL 8.4.11/InnoDB run passed its existing coverage. MariaDB remains separately unverified. See [Release readiness](ReleaseReadiness.md) for versions and limits.

## Runtime directories and source policy

Logging lazily creates `Storage/Logs`; application cache lazily creates its namespace under `Storage/Cache`; the default local Storage drive lazily creates `Storage/Files`. The View compiler writes derived PHP into Application-owned `Storage/Views`, separately from data Cache. `cache:clear` does not delete compiled Views; `view:clear` removes their owned artifacts. These generated contents should not be committed. No empty runtime directory is required for Application bootstrap. Tests use temporary projects and databases. See [Compiled Views and Production Lifecycle](CompiledViews.md).

The future v2 commit must include the intended `App`, `Bootstrap`, `Config`, `Database`, `Docs`, `Project`, `Tests`, `Assets`, and `Scripts` source; root entry points and metadata; `composer.json`, `composer.lock`, and `phpunit.xml.dist`. It must also represent the old lowercase-to-canonical case renames and intentional removals. Exclude `.env`, `vendor`, runtime `Storage` contents, IDE files, coverage, and `project-backup`. Review the Git index on a case-sensitive checkout before committing: on Windows, an old lowercase tracked path can silently refer to a capitalized working-tree file.

The exact 49 index-to-working-tree casing conflicts and six physical v1 root-view removals are recorded in [PreCommitManifest.md](PreCommitManifest.md). Regenerate that inventory before staging a future release commit.

## Release criteria

Before a v2 release, require a green full suite in both the intended working tree and a clean dependency install; clean Composer/autoload checks; CLI normal and help paths; no known source-casing or security blocker; accurate internal documentation; and an explicit version/release decision. Later Linux and guarded MySQL results are recorded in [Release readiness](ReleaseReadiness.md); they do not close the clean committed-checkout blocker. No release tag, deployment, package publication, or version bump is part of this gate.

## Application-facing Plugins import

This guide covers a subsystem or maintenance workflow; see [Plugins](Plugins.md) for supported application imports. Canonical framework namespaces remain supported.
