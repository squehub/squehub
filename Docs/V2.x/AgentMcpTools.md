# MCP capabilities, resources, and tools

The optional SqueHub Agent serves bounded metadata for one Application over local MCP STDIO. This page is the practical inventory for host operators. Use [local MCP client setup](AgentMcpSetup.md) to connect and the [Agent and AI overview](AgentAndAI.md) for architecture, security, and Change Plan context. The framework reports version `2.0.0`, and its served STDIO protocol revision is `2025-11-25`.

## Capability grants

The shipped `Config/Agent.php` returns `['grants' => []]`. That permits six bounded reads and no plan creation or schema inspection. `php squehub agent:status --json` reports `allowed`, `supported`, `risk`, `mode`, `scope`, and `scope_values` for every named capability without displaying secrets.

| Capability | Risk | Mode | Default | Supported? | Scope | Purpose |
| --- | --- | --- | --- | --- | --- | --- |
| `read_framework_metadata` | `low` | `read` | Allowed | Yes | None | Framework identity, public Plugins symbols, and registered CLI inventory. |
| `read_application_contract` | `low` | `read` | Allowed | Yes | None | Bounded route-derived operation flags and selected application metadata. |
| `read_routes` | `low` | `read` | Allowed | Yes | None | Already registered routes or an existing validated route cache. |
| `read_package_metadata` | `low` | `read` | Allowed | Yes | None | Bounded Package and Kit activation metadata. |
| `read_docs` | `low` | `read` | Allowed | Yes | None | Search physical Markdown under `Docs/V2.x/`. |
| `read_health_metadata` | `low` | `read` | Allowed | Yes | None | Aggregate, non-probing Health and infrastructure summaries. |
| `read_schema` | `sensitive` | `read` | Denied | Yes | `connections` | Exact allowlisted connection metadata and table/index inspection. |
| `read_source` | `sensitive` | `read` | Denied | No | `roots` | Reserved source-inspection capability; no source tool is implemented. |
| `read_logs` | `sensitive` | `read` | Denied | No | `sources` | Reserved log-inspection capability; no log tool is implemented. |
| `run_tests` | `execution` | `execute` | Denied | No | `suites` | Reserved test-execution capability; no test-running tool is implemented. |
| `create_plan` | `review` | `read` | Denied | Yes | `operations` | Exact allowlisted review-only Change Plan operations. |
| `apply_plan` | `mutation` | `write` | Denied | No | `fingerprints` | Reserved plan-application capability; MCP cannot apply plans. |
| `run_migration` | `mutation` | `write` | Denied | No | `connections` | Reserved migration-execution capability; MCP cannot run migrations. |
| `manage_package` | `mutation` | `write` | Denied | No | `packages` | Reserved Package-lifecycle capability; MCP cannot manage Packages. |

The risk, mode, and scope columns are registry descriptors. Unsupported entries remain visible in status with `allowed: false`; `Config/Agent.php` cannot grant them. A scope name identifies the required allowlist key for a supported capability, not a permission by itself.

To deny a default read or grant only supported narrow scopes, edit the trusted local configuration and restart the MCP process:

```php
return [
    'grants' => [
        'read_docs' => false,
        'read_schema' => ['connections' => ['reporting']],
        'create_plan' => ['operations' => ['migration_source', 'feature_blueprint']],
    ],
];
```

`read_schema` requires an exact connection name from the current application's database configuration. `create_plan` requires exact operation names. Unknown capabilities, an unscoped sensitive grant, and a grant for unsupported `read_source`, `read_logs`, `run_tests`, `apply_plan`, `run_migration`, or `manage_package` fail validation. Naming an operation in a prompt or tool argument does not grant it. The grants belong to the current Application root, so another application's MCP process has a separate inventory.

## Resources

Resource discovery includes only permitted entries. Each resource uses `application/json` content. A client may read an available URI, but each read checks its capability again.

| URI | Required capability | Returned boundary |
| --- | --- | --- |
| `squehub://framework` | `read_framework_metadata` | Framework version, served protocol, Application fingerprint, path-first routing example, bounded public Plugins symbols, and CLI inventory. |
| `squehub://application/contract` | `read_application_contract` | Route-derived operation flags plus API version strategy and selected frontend/deployment metadata. It is not a full Contract or OpenAPI export. Denied `read_routes` produces `state: denied` with no operations. |
| `squehub://routes` | `read_routes` | At most 100 route metadata records from already loaded registration or a validated existing route cache. Fresh uncached boot can be partial. |
| `squehub://packages` | `read_package_metadata` | Bounded Package/Kit activation and dependency metadata. No lifecycle execution. |
| `squehub://health` | `read_health_metadata` | Aggregate Health, infrastructure, Queue, and Scheduler states and counts. Connection and task labels are omitted. It is a snapshot, not a live end-to-end proof. |
| `squehub://cli` | `read_framework_metadata` | Bounded names/descriptions from the registered CLI. Directly constructed Agent managers without that registry return unavailable. |
| `squehub://schema` | `read_schema` for a connection | Names and driver types from up to the first 32 configured connections, filtered by the exact allowlist; no credentials or records. Hidden without a scoped grant. |

An allowed routes resource can report `observed` or `partial`; denying `read_routes` removes that URI from discovery and denies direct reads. The separate application contract projection then reports `state: denied` and no operations. Neither resource executes Project route PHP to complete an inventory. Other resources may report `unavailable`, `configured`, `registered`, or a `truncated` flag according to the source. Treat `partial` and `unavailable` as explicit limits, not a reason to request arbitrary files through the model.

## Tools

Tool discovery is capability-filtered, and every call is checked again. The MCP SDK marks these tools as read-only and non-destructive. Unknown argument fields fail validation.

| Tool | Required capability | Arguments | Result |
| --- | --- | --- | --- |
| `search_docs` | `read_docs` | Required `query` of 2–120 bytes; optional `limit` of 1–20, default 5. | `state`, `items` with application-relative `path`, `line`, and bounded `excerpt`, plus `truncated`. |
| `inspect_schema` | `read_schema` for selected connection | Required simple `table` identifier; optional exact `connection` name (otherwise configured default). | Table existence and up to 64 index metadata entries; `records_exposed: false`. |
| `create_plan` | `create_plan` for selected operation | Required `operation`; `target` required except for `migration_source`. | Subsystem Change Plan, fingerprint, provenance, `applied: false`, `apply_supported: false`. |

`search_docs` reads only physical Markdown under this application's `Docs/V2.x/`. It inspects at most 512 files of at most 256 KiB each and returns at most a 240-byte excerpt per match. This includes internal Markdown files placed in that tree even when the public website omits them, so do not put secrets in installed documentation. An omitted installed docs tree yields `state: unavailable`. The tool does not traverse arbitrary application files, links, `vendor`, or `.env`. `inspect_schema` calls the existing Schema reader for an allowlisted connection; it may access that database's metadata, but never returns table rows.

## Review-only Change Plans

`create_plan` supports exactly four operations:

| Operation | Target | Planner |
| --- | --- | --- |
| `migration_source` | Omit `target` | Reviews Migration source candidates without executing their PHP or a database migration. |
| `package_enable` | Existing Package name | Asks the Package planner for an enable plan. |
| `kit_enable` | Existing Kit name | Asks the Kit planner for an enable plan. |
| `feature_blueprint` | Bounded feature name | Asks the Feature Blueprint generator for a multi-file plan. |

For example, after granting only `feature_blueprint`, a host may call `create_plan` with:

```json
{"operation":"feature_blueprint","target":"Post"}
```

The result contains a `plan` with operation, target, owner, actions, warnings, conflicts, preconditions, and Agent metadata; a plan `fingerprint`; and `provenance` with the framework version, Application fingerprint, creation time, and requested capability. Its `applied` and `apply_supported` fields are both `false`. The returned `ChangePlan` is review data with no private prepared execution handle. Review the exact actions and current state in the owning [SqueHub Change Plan workflow](ReviewableChanges.md). A plan fingerprint is an identity for review, not approval or an MCP execution permit; the owning subsystem retains authorization and freshness checks.

## Security and transport limits

- SqueHub MCP is local STDIO only. This connection exposes no remote HTTP transport, generic MCP `apply_plan`, arbitrary shell, network request, or general file read/write tool. The host may have separate tools with their own permissions.
- Default resources omit `.env`, `APP_KEY`, token values, logs, private Storage, database rows, and arbitrary source. Keep secrets out of application-authored documentation and route descriptions that an AI host may summarize.
- A 256 KiB input-line limit and a 64 KiB result limit bound STDIO messages. Resource lists, route metadata, Package details, docs hits, and schema indexes have additional limits; oversized results fail safely.
- The optional SDK is experimental and installed only when MCP is enabled. The server advertises the protocol revision it actually serves, even if the SDK supports newer revisions.
- The Agent uses inspection-only Package boot and does not run Package providers, Kit hooks, route source PHP, migrations, Seeders, Queue workers, or Scheduler tasks while inspecting. Normal application Config PHP still evaluates during bootstrap.

For a complete route or API contract export, use the deliberate [route](Routing.md) and [Application Contract](ApplicationContract.md) workflows outside Agent inspection. For connection and client errors, see [MCP troubleshooting](AgentMcpSetup.md#troubleshooting). The v2 release boundary is in [v2 status](Status.md).
