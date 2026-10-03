# Upgrade preflight

Upgrade preflight is a read-only comparison between the current application
root and an explicitly selected local target source directory. It produces a
reviewable [Change Plan](ReviewableChanges.md) and categorical findings. It never
applies the plan.

```bash
php squehub upgrade:check ../local-target-source
php squehub upgrade:check ../local-target-source --json
```

The command exits unsuccessfully for `blocked` and `unknown` results; a
`review` result still requires an operator decision. JSON output contains
the same safe plan and categorical findings as the PHP API.

```php
use App\Upgrades\UpgradePreflight;

$report = (new UpgradePreflight())->inspect(
    currentRoot: $applicationRoot,
    targetRoot: $localTargetSource,
);

$report->status();   // compatible, review, blocked, or unknown
$report->findings(); // status, code, and safe relative subject
$report->plan();     // App\Changes\ChangePlan
$report->toArray();  // machine-readable review data
```

The target must already exist on the local filesystem. Selecting a release,
downloading it, installing dependencies, or executing an upgrade are separate
operations. A target source tree should include its `composer.json` and
`composer.lock`. Preflight does not boot the current or target application.

## Results

| Status | Meaning |
| --- | --- |
| `compatible` | No target-originating differences or unresolved findings were found in the inspected source surface. This is a limited static conclusion, not a deployment guarantee. |
| `review` | Source changes require human review, including newly visible migration files. |
| `blocked` | A known mismatch or unsafe collision was found. No change is applied. |
| `unknown` | Available static evidence cannot prove compatibility. Do not treat this as approval. |

`blocked` takes precedence over `unknown`, which takes precedence over
`review`. Each finding has a stable category code and a relative subject,
without source contents or configuration values. The Change Plan records
relative file actions and SHA-256 before/after fingerprints; its preconditions
are review evidence and do not authorize application. Shared plan metadata
labels the source as `upgrade`, calls out untrusted target source, and lists
the compatibility and verification topics for an operator to review. No
source fingerprint is claimed if the inspected target might be incomplete.

## Inspection scope

Preflight inventories source files beneath `App/`, `Bootstrap/`, `Config/`,
`Project/`, `Database/`, `Assets/`, `public/`, and `Scripts/`, plus selected
root files such as `composer.json` and `composer.lock`. It deliberately
excludes `.env`, runtime `Storage/`, `vendor/`, test artifacts, and unselected
private files. Linked or reparse-point source entries, unsafe names, case
collisions, excessive file sizes, and unreadable files are blocked rather than
followed or silently omitted. A partial target that lacks a current framework
file yields `unknown`; absence alone is not a deletion instruction. The
original caller-supplied root path is checked before canonicalization, so a
linked ancestor cannot disappear during path resolution. Private directory
segments are excluded before recursion, and source entries and report findings
have explicit bounds. Filename collisions use the same portable Unicode and
case policy as Project Bundles; Unicode names are rejected where required
normalization support is unavailable.

The `composer.json` comparison checks project identity, target PHP version,
required PHP extensions, and changed requirements. It uses only a small
statically checkable constraint subset. An unsupported constraint is
`unknown`, because the Composer semver library is not a runtime dependency.
If an authoritative framework release version is unavailable, a declared
framework compatibility range also remains `unknown`. A missing target lock
file is `unknown`.

Package and Kit discovery reads their static metadata and existing
`Project/Activation.json` ownership records. It checks enabled dependency
availability and modification of registered Package files. Shared publication
ownership and incompatible activation-state replacement are blocked. No
Package entry PHP or Kit lifecycle hook is run. If an enabled current Package
or Kit is absent from the selected target source, preflight reports `unknown`:
the target may be partial, and no contribution is implicitly deleted.

Executable PHP configuration files are compared by checksum and presence.
When their bytes differ, preflight reports that configuration semantics are
unproven; it does not include the file to inspect returned arrays. It lists
new migration filenames but does not claim to know their SQL effect or pending
database status. Migration history and application records are untouched.

## Security and operational boundaries

Preflight performs no network requests, Composer update, database writes,
migrations, Storage writes, Queue work, Mail delivery, or source modification.
It does not read or display `.env` values. Reports do not include absolute
machine paths, source contents, secrets, or executable configuration values.
Protect the selected target and generated report according to your normal
deployment review process.

An `unknown` or `review` result calls for operator investigation. Check
Composer resolution, configuration changes, Package/Kit compatibility,
migration effects, and deployment prerequisites before any separate upgrade
operation. Upgrade preflight is evidence for that decision, not a substitute
for tests, backups, or a staged deployment.
