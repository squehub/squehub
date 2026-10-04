# Reviewable changes

SqueHub plans source changes before it applies them. The current implementation covers the five single-file `make:*` generators, the [SqueHub Feature Blueprint](FeatureBlueprints.md), Package install, enable, disable, upgrade, and remove, the separate [SqueHub Kit](Kits.md) lifecycle, [Project bundle](ProjectBundles.md) import, and read-only upgrade preflight. These operations share the internal `App\Changes` plan, action, risk, warning, conflict, renderer, and result vocabulary. This is a developer review tool, not a sandbox for executable Package or Kit code or a general rollback engine.

```text
Inspect → Plan → review conflicts and risk → apply deliberately → verify
```

## Review a generator

```bash
php squehub make:controller Admin/UserController --preview
php squehub make:controller Admin/UserController --yes
```

The preview names the exact application-relative `CREATE` target, owner, risk, and plan fingerprint. It creates no application files or directories. `--yes` applies the reviewed operation without a prompt. An interactive terminal can instead run the command without either flag and answer its confirmation prompt. Non-interactive invocations without `--yes` fail promptly, so automation cannot hang or silently apply changes. Repeating a generator for an existing target is a blocking conflict; there is no force-overwrite mode.

`make:controller`, `make:middleware`, `make:migration`, `make:model`, and `make:seeder` all use this flow. Controller, Middleware, and Model generation may target an existing Package with `--package=<Name>`. Migration and Seeder generation remain application-root operations. Generation writes one file; it never runs a migration or Seeder.

`make:feature Post --preview` composes several related source actions into **one** shared `ChangePlan`. It checks all intended targets before applying any, preserves application or Package ownership in each action, and rejects collisions and stale preconditions. The Blueprint includes a Migration source file, but applying its plan never executes that Migration. A Package-targeted Blueprint uses runnable root Migration and test locations instead of creating Package-local artifacts that existing runners would miss. Feature generation follows the same preview, interactive confirmation, and explicit non-interactive `--yes` rules as the single-file generators.

## Review a Package change

```bash
php squehub package:install ./Weather --preview
php squehub package:install ./Weather --yes
php squehub package:enable Weather --preview
php squehub package:enable Weather --yes
php squehub package:upgrade Weather ./Weather-v2 --preview
php squehub package:upgrade Weather ./Weather-v2 --yes
php squehub package:remove Weather --preview
php squehub package:remove Weather --yes
```

The same rules apply to `package:disable`. A plan lists the Package-owned files that would be created, modified, or removed; relevant state changes; declared Package requirements; safe dependency conflicts; and warnings. Upgrade warnings identify added or removed requirements and numeric version changes. Other version metadata is reported without printing its arbitrary text. Installation remains disabled by default. Enabling shows disabled prerequisites as reviewed actions rather than activating them silently; disabling does not silently disable dependents. Removal does not roll back migrations or delete application database records.

In a multi-Package application, review shared dependencies before disabling or removing a Package. If two enabled capabilities both require it, both dependent relationships must be resolved explicitly; a plan does not cascade changes. A deeper chain still blocks activation when any requirement is missing, disabled, or broken. [Large applications](LargeApplications.md) describes the intended Package boundary and dependency patterns.

Local-source preview leaves the application project byte-identical. An HTTPS Git source needs a disposable system temporary checkout to inspect its files. That source-inspection scratch is outside the application project and is removed afterward; preview does not mutate Package state, application files, Composer autoload, migrations, or Seeders. Remote source availability and trust still require review.

## Review a Kit change

```bash
php squehub kit:install ./Ecommerce --preview
php squehub kit:enable Ecommerce --preview
php squehub kit:enable Ecommerce --yes
php squehub kit:remove Ecommerce --preview
```

A Kit plan identifies the definition and owned application files separately. For example, enabling an Ecommerce Kit that requires Payments may show a reviewed Package activation action, a Kit state transition, and published application files. An illustrative excerpt of the shared renderer is:

```text
CREATE
  Project/Routes/Shop.php
  Project/Views/Shop/Index.squehub.php
STATE
  Project/Activation.json
  Project/Packages/Payments#activation
WARNING Migration and Seeder execution: NOT INCLUDED.
```

The exact action set depends on the Kit manifest, current Package state, and current application files. A source-file collision, modified owned file, missing requirement, unsafe path, or stale plan blocks application. Kit-owned Migration replacement or deletion is blocked conservatively; preview does not query migration status or change a database. Preview identifies declared lifecycle hooks but does not execute them. A Kit hook is trusted PHP outside the plan's complete side-effect control; inspect it before deliberate apply. Disabling a Kit does not delete published files or disable its Packages, and removing Kit-owned files requires a separate reviewed removal. Kit install and upgrade currently accept local directory sources only. See [SqueHub Kits](Kits.md).

## Review configuration cache publication

```bash
php squehub config:cache --preview
php squehub config:cache
```

The preview shows a `CREATE` or `MODIFY` action for `Storage/Cache/Framework/Config.json`, the framework owner, source-file and existing-artifact hashes, risk, and expected verification. `ConfigCache::planBuild()` inspects hashes without evaluating Config PHP or creating private Storage. The CLI selects a restricted inspection bootstrap for this option: it loads environment values and command definitions, but does not execute Config files or boot providers. The preview does not publish a cache artifact. It does not inspect arbitrary behavior inside Config PHP; the normal build remains the deliberate execution boundary.

The explicit `config:cache` command retains its established immediate build behavior. It now creates a manager-owned plan and checks Config/environment identity and the current artifact before evaluating Config, then checks both again under the publication lock. If either changed since planning, publication stops and the caller must plan again. A read-back validates the published artifact before the shared `ChangeResult` reports verified completion; a failed read-back reports a partial application instead of claiming rollback. Cached values and environment-derived identity stay out of the plan output. `config:clear` remains a separate explicit recovery command. See [Framework performance caches](PerformanceCaching.md).

## Plan contents and stale state

The internal plan records `CREATE`, `MODIFY`, `DELETE`, and `STATE` actions, including Package directory creation/removal where relevant. Where useful, it records an autoload note, but ordinary PSR-4 Package operations do not run `composer dump-autoload`. File actions carry SHA-256 fingerprints or an expected-absence precondition. Modified and deleted actions show the owner and shortened before/after fingerprint in console output; the internal plan retains the complete hash. Package and Kit lifecycle plans also carry the whole [Activation Registry](ActivationRegistry.md) fingerprint. A change to either component type makes a pending plan stale. File ownership and registry state are rechecked before lifecycle writes, including under the shared registry lock. If a target appears, disappears, changes bytes, changes ownership, or activation changes after planning, the old plan is rejected rather than applied against different input. Package source scanning also rejects sibling names that would collide on case-insensitive filesystems.

Plans are immutable, deterministic review artifacts. Their fingerprint is derived from normalized safe metadata, not a timestamp, random identifier, username, or absolute project path. The current plan format is **internal and versioned for evolution**; do not persist it as a stable public interchange format. Package source bytes and generator template contents remain outside ordinary plan output. Plans without the optional review metadata retain their existing JSON shape and fingerprint behavior.

### Review metadata

An operation can now label a `ChangeAction` with a bounded category such as `file`, `directory`, `activation`, `configuration`, `migration`, `database`, `asset`, or `composer`. Its verb remains `create`, `modify`, `delete`, `state`, or `autoload`. This keeps **what changes** separate from **what happens** without changing existing actions that do not supply a category.

`ChangePlanMetadata` optionally records a source kind (`generator`, `feature`, `package`, `kit`, `bundle`, `upgrade`, `configuration`, `migration`, `recovery`, or `application`), a source SHA-256 fingerprint, and fixed review codes for security, compatibility, and expected verification. These are bounded framework codes, not free-form values. They cannot carry source bytes, credentials, local paths, or a serialized executable. Lists are sorted in machine-readable output, so input order does not change plan identity. A source fingerprint identifies reviewed bytes; it does not authorize an apply or establish trust.

Verification codes describe **expected checks**, for example `file_checksum`, `activation_state`, or `migration_pending`. They do not claim that those checks ran. The operation owner records observed success through `ChangeResult::verified` after its own apply and verification. `ChangeResult::complete()` remains false after an unverified or partial apply. `ChangeResult::toArray()` reports the reviewed plan hash, observed applied/unapplied actions, verification flag, and any relative recovery path without serializing exceptions or source data. Similarly, `reviewStatus()` reports `blocked` when conflicts exist while `risk()` retains the action-risk vocabulary used by existing callers.

The plan engine coordinates review data. It does not execute source, write files, run Composer, apply migrations, or restore databases. Package Manager, Kit Manager, Generator, Bundle importer, and other operation owners recheck their own file, ownership, source, and state preconditions at apply time. Synthetic preconditions such as Package ownership or Activation Registry fingerprints cannot be checked by a generic filesystem hash routine. A plan ID or fingerprint is never a substitute for those checks. A database restore or destructive migration requires its own separate authorization and execution path.

`ChangeRenderer` shows the action risk, optional category and metadata codes, warnings, and a `BLOCKED` status for conflicts. JSON output contains only review metadata and hashes. Common credential assignments such as `APP_KEY=...` and `DB_PASSWORD: ...` are rejected from free-form plan and action text with a generic error. Subsystems must still keep arbitrary secrets and source content out of action reasons, warnings, conflict descriptions, and target names; a pattern check cannot identify every sensitive value.

## Risks, warnings, and conflicts

The compact risk vocabulary is `low`, `review`, and `destructive`. Creating a new file is generally low risk; replacing owned source warrants review; deleting Package files is destructive. A warning highlights work to inspect, such as added migrations or Seeders, but does not by itself block apply. A conflict blocks apply: examples include a target collision, changed Package-owned file, unowned file in a removal tree, unsafe path, or an unsatisfied dependency. Resolve the conflict and build a fresh plan.

[Contribution provenance](Contributions.md) identifies an observed Package or application owner where it can do so reliably. Package lifecycle also uses an owned-file snapshot. Unknown ownership is not guessed. A plan does not execute controllers, middleware, Package hooks, migrations, Seeders, Jobs, or scheduled tasks to discover ownership.

## Application and recovery boundary

Generator creation prepares a complete same-directory temporary file and publishes it only when the target is still absent. Package lifecycle retains its staging, renamed-backup, ownership check, and recovery paths. SqueHub validates predictable conflicts before the first application mutation, then verifies cheap file/state outcomes after apply. Filesystem operations are not a universal transaction: if cleanup or publication fails after some effects are visible, the command reports the applied state and an application-relative recovery location where one exists. Do not infer that a failed command always rolled back everything.

Plans and console output omit source contents, private configuration values, credentials, request data, and absolute local paths. A fingerprint is evidence of bytes, not proof that Package source is safe. **Package source remains executable application code; review does not sandbox it.** Applying a source plan never runs a migration or Seeder. Run those through their own explicit commands after inspecting the new code.

The owner model can represent Kit-owned artifacts separately from observed application and Package runtime registrations. Project bundle import and upgrade preflight also use `ChangePlan`. Upgrade preflight is inspection only; it cannot apply an upgrade. Recovery planning inventories separate source, database, uploads, secret, session, and Queue boundaries rather than pretending they form one transactional change. The current commands do not provide a general database restore or global rollback engine.

See [Generators](Generators.md), [Feature Blueprints](FeatureBlueprints.md), [Packages](Packages.md), [SqueHub Kits](Kits.md), [CLI](Cli.md), and [Security](Security.md).
