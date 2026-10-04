# SqueHub Activation Registry

The SqueHub Activation Registry is the Application-owned view of installed Packages, Kits, and their dependencies. Packages build runtime capabilities; Kits build solutions by composing Packages and publishing ordinary application files. An installed component is not automatically enabled, and discovering its files never authorizes execution.

Applications continue to use `package:*`, `kit:*`, SqueHub Doctor, and SqueHub Dev. The registry is framework infrastructure rather than a separate daily-use CLI tool. [Studio](Studio.md) and the optional local [Agent/MCP integration](AgentAndAI.md) consume bounded, read-only activation metadata without parsing command output or private state files.

## State and status

SqueHub persists deliberate Package and Kit lifecycle state in one private, versioned JSON file at `Project/Activation.json`. The file records enabled intent and the source and ownership data needed for safe upgrades and removal. It is framework-owned metadata, not a developer-facing interchange format: use the Package and Kit commands rather than editing or parsing it. A project with no Package or Kit lifecycle state needs no registry file.

The registry combines this saved state with current static Package and Kit discovery. It derives `enabled`, `disabled`, `broken`, and discovered/manual status from the present files, manifest metadata, and dependencies. A missing or invalid component remains inspectable instead of being silently erased from saved state. `package:list`, `package:inspect`, `kit:list`, `kit:inspect`, and Doctor report that status without including a Package entry class or Kit lifecycle PHP. A malformed or unsupported registry fails safely; SqueHub does not replace it with empty activation state.

Package `Commerce` and Kit `Commerce` are different typed identities. The registry answers dependency and reverse-dependency questions using those types, so equal display names do not merge. It reports requirements, not an invented history of *why* someone enabled a Package.

## Dependencies and runtime boot

Package metadata may declare `Package → Package` requirements. Kit manifests may declare `Kit → Package` requirements. The existing Package graph supplies deterministic dependency order and rejects missing requirements and cycles. `Package → Kit` is prohibited, and Kit-to-Kit dependencies are deferred.

Only valid, enabled Packages enter the runtime boot order. An enabled Kit never becomes a ServiceProvider: its published Project code participates through the normal application structure, while the Kit definition and hooks stay outside normal HTTP boot. A disabled Package never boots merely because its files are present or a disabled Kit requires it. An enabled Package with an invalid dependency continues to fail through the established Package boot boundary; an unhealthy Kit composition is reported without adding a new global HTTP failure.

When a Package is required by other Packages or installed Kits, inspection reports all relevant dependents. The conservative Kit protection applies even while an installed Kit is disabled. Removing one Kit releases its requirement, but does not automatically disable or remove a shared Package. Disabling a Kit also leaves required Packages, published files, Migrations, and Seeders in place.

## Review and apply

Package and Kit lifecycle commands continue to produce [Change Plans](ReviewableChanges.md). A plan shows dependency activation and file/state changes before anything is applied. For example, enabling a Package can show its disabled Package prerequisites; enabling a Kit can show required Package activation followed by Kit publication and activation. The developer approves that reviewed work with the existing `--yes` flow.

Each lifecycle plan carries a fingerprint of the combined activation state. A Package change makes an earlier Kit plan stale, and a Kit change makes an earlier Package plan stale. Mutations serialize through one registry lock and publish a complete replacement JSON file rather than truncating live state. Kit activation and its reviewed Package enablement share one state write. Filesystem publication and trusted lifecycle hooks remain separate effects, so a whole Kit operation is not an ACID transaction. Failed operations retain the existing partial-effect reporting.

Preview, normal boot, static inspection, and Doctor do not create or rewrite activation state. Neither Package nor Kit dependencies are silently enabled during a request. Kit hooks run only during explicit reviewed lifecycle application. Migrations and Seeders are never run by activation.

## Existing projects

Projects created before the shared registry may contain `Project/Packages/State.json`, `Project/Kits/State.json`, or both. When `Project/Activation.json` is absent, SqueHub validates and reads legacy state into the registry view **without writing during normal boot or inspection**. The first explicit Package or Kit lifecycle mutation can persist the complete combined state atomically. Legacy files are left in place rather than deleted during an HTTP request.

Once canonical state exists, it is authoritative. Stale legacy files are reported for review; they are not merged into or allowed to overwrite current activation state. Keep the private `Project/` metadata outside the public document root and avoid manual edits to either format.

The managed activation root uses exact `Project/` casing. A sole lowercase `project/` remains supported for the existing legacy application and view lookup paths, but it does not receive Package or Kit lifecycle writes. Move managed components into `Project/` before enabling them. On case-sensitive filesystems, physically separate `Project/` and `project/` roots in one application are a portability conflict and the registry rejects them. It never reads one root and writes activation or lock state into the other.

## Security and portability

The registry stores no application key, database password, SMTP credential, token, runtime object, or executable PHP. Source labels and owned paths are bounded and portable; paths required for ownership are application-relative. The registry requires no database, Redis, worker, Node.js, or long-running process, so small applications and conventional PHP hosting remain supported. Its SHA-256 fingerprint detects stale review state; it is not a signature or an encryption mechanism.

Static inspection cannot prove that trusted Package entry code, Kit hooks, or generated application PHP are safe. Review third-party sources and the Change Plan before applying them. The [Packages](Packages.md), [Kits](Kits.md), [Contributions](Contributions.md), and [Doctor](Health.md) guides describe each public surface. Contribution provenance remains a separate record of observed framework contributions; the Activation Registry answers lifecycle and dependency questions without duplicating it.

## Verification status

The registry applies validated Package and Kit activation state during application boot. See [public status](Status.md) for supported environments and remaining limits.
