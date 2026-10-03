# Agent and AI integration

SqueHub provides an optional, local bridge from a compatible MCP host to bounded framework metadata. The AI host owns its model and prompts; SqueHub owns the application context, capability checks, and existing subsystem boundaries. An ordinary SqueHub application needs no MCP client, LLM account, Node.js, Redis, Docker, or remote Agent service.

> Inspect first. Propose second. Mutate only through a deliberate, authorized SqueHub workflow.

Phase 25A–25F is **complete: implemented, tested, passed, and qualified on Windows and user-run native Linux**, including official MCP SDK client interoperability on Linux. This guide describes the implemented boundaries of the optional Agent service. See [v2 status](Status.md) for release and environment qualification.

For a client setup walkthrough, use [Local MCP setup](AgentMcpSetup.md). For the exact capability, resource, tool, and result fields, use [MCP tools and resources](AgentMcpTools.md). This page explains the Application and change-review boundaries behind those interfaces.

## MCP and the SqueHub Agent

**MCP** is the local communication protocol between a compatible AI host and SqueHub's STDIO process. The optional PHP SDK handles JSON-RPC framing and the protocol handshake. **The SqueHub Agent** is the framework-aware code behind that process: it chooses bounded context, validates tool arguments, and enforces this Application's grants. It does not provide a model or autonomous execution loop.

```text
AI host (model and its own permissions)
  │ MCP over local STDIO
  ▼
php squehub agent:mcp
  │ MCP adapter → AgentManager
  ▼
CapabilityRegistry + CapabilitySet
  │ trusted Config/Agent.php grants
  ├─ AgentContext / AgentInspector → bounded reads
  └─ AgentPlanService → review-only ChangePlan
```

The registry defines which capabilities exist; `CapabilitySet` checks the configured grant for the selected Application on each resource read or tool call. MCP discovery lists available operations but grants none by itself. A separate AI client's shell or file permissions remain outside this SqueHub connection.

## Local MCP setup

The MCP server is an explicit **STDIO** CLI process. It opens no listening HTTP socket. A compatible local host starts `php squehub agent:mcp` from the target application's root. `stdout` is reserved for protocol frames; operational errors go to `stderr`. Ordinary `php squehub` commands and applications do not start MCP.

```bash
php squehub agent:status
php squehub agent:status --json
php squehub agent:mcp
```

The checkout uses the official experimental PHP MCP SDK `mcp/sdk` **0.8.1** as a development dependency. A deployed application that enables MCP installs it deliberately, for example with `composer require mcp/sdk:^0.8.1`; ordinary deployments do not need it. `agent:status` shows whether the optional SDK is present. `agent:mcp` fails clearly if it is absent.

A host's configuration syntax varies, but the process it starts has this shape:

```json
{
  "command": "php",
  "args": ["/absolute/path/to/application/squehub", "agent:mcp"],
  "cwd": "/absolute/path/to/application"
}
```

Use an absolute path appropriate to the host and keep its ordinary process environment private. The host must support SqueHub's **MCP STDIO protocol revision 2025-11-25**. SqueHub's current STDIO Agent integration advertises the [2025-11-25 handshake revision](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports), although [mcp/sdk v0.8.1](https://packagist.org/packages/mcp/sdk) itself also implements the [2026-07-28](https://modelcontextprotocol.io/specification) modern/stateless era. SqueHub reports the revision it actually serves. Streamable HTTP and remote Agent access are deferred. No deprecated HTTP+SSE endpoint is installed.

[Local MCP setup](AgentMcpSetup.md) includes client-neutral process settings and documented Codex and Claude Code examples. Client configuration syntax alone is not a verified SqueHub handshake with either product; the interoperability evidence below is for the official PHP SDK client and a direct live STDIO protocol client.

## Status and effective permissions

`agent:status --json` emits one compact JSON object without starting MCP. Without `--json`, the same object is indented. The shape below shows one default capability row; the command actually returns all 14 registered rows. The fingerprint is an opaque hash of the canonical Application root, not the root path. `mcp_sdk_available` reflects this PHP installation.

```json
{
  "framework": {"name": "SqueHub", "version": "2.0.0"},
  "protocol": {"transport": "stdio", "supported_stdio": "2025-11-25"},
  "mode": "read-only",
  "application": {"fingerprint": "<application-specific SHA-256>"},
  "capabilities": [
    {"name": "read_framework_metadata", "risk": "low", "mode": "read", "allowed": true, "scope": null, "scope_values": [], "supported": true}
  ],
  "remote_http": false,
  "mcp_sdk_available": true
}
```

The last value is `false` when the optional SDK is absent. A scoped `create_plan` grant changes `mode` to `inspection-and-proposal`; it does not add an apply operation. For each capability, `allowed` reports the effective grant, `supported` says whether this release implements it, and `scope_values` shows the exact configured connection or operation names. An unsupported capability remains `allowed: false` even when its name appears in the inventory. Resource and tool discovery also filters by capability, then each call checks the grant again.

## Default inspection surface

With `Config/Agent.php`'s shipped empty `grants` array, the Agent is **read-only**. Its six default capabilities are `read_framework_metadata`, `read_application_contract`, `read_routes`, `read_package_metadata`, `read_docs`, and `read_health_metadata`. They expose bounded, selected metadata. They do not grant a filesystem browser, shell, outbound HTTP client, database records, logs, tests, migrations, Package lifecycle action, or source write.

| Resource | Content and boundary |
| --- | --- |
| `squehub://framework` | Framework identity/version, actual MCP transport revision, Application fingerprint, current path-first route style, bounded public Plugins symbols, and the registered CLI inventory when launched through `agent:mcp`. |
| `squehub://application/contract` | Bounded operation flags projected from routes already registered in memory or an existing validated route-cache artifact, plus API version strategy and frontend/deployment metadata. It can be partial if source route declarations were not loaded. Denying `read_routes` yields `state: denied` and no operations even if `read_application_contract` remains allowed. It is not a full Contract/OpenAPI export. |
| `squehub://routes` | At most 100 route metadata records from the already-registered RouteRegistry or an existing validated route-cache artifact. On a fresh uncached Application it may return `state: partial`; the Agent does not load Project route PHP merely to fill the list. Closure source and handler internals are not exported. |
| `squehub://packages` | Bounded Package/Kit activation metadata, without lifecycle mutations. |
| `squehub://health` | Safe aggregate Health/infrastructure/Queue/Scheduler summaries. Configured connection, drive, transport, and Queue connection labels and Scheduler task definitions are omitted; selected driver types, states, and counts remain. This is a snapshot, not a proof that every deployment component works end to end. |
| `squehub://cli` | Bounded command names/descriptions from the already-registered Symfony Console application. No command is executed for inspection; directly constructed Agent managers without that registry report unavailable. |
| `squehub://schema` | Configured connection names/drivers only, when `read_schema` is deliberately granted. No credentials or rows. |

The default `search_docs` tool searches only physical Markdown files under this application's `Docs/V2.x/`. It accepts a 2–120-byte query and 1–20 results, searches at most 512 files of at most 256 KiB each, and returns at most a 240-byte excerpt per match. Unsafe linked paths, arbitrary filesystem searches, vendor traversal, and `.env` are excluded. It searches local Markdown even when a page is excluded from the public documentation portal, so do not store private prose in that tree when `read_docs` is enabled. If an installed artifact omits the Markdown, the result is **unavailable**, not an invitation to scan another path. A separate `inspect_schema` tool is listed only when the schema capability is granted; it uses the existing Schema reader and returns bounded table/index metadata, never table rows.

| Tool | When discovered | Request and result |
| --- | --- | --- |
| `search_docs` | `read_docs` allowed (default) | Required `query`, optional `limit` (default 5); returns `state`, bounded `items` with relative `path`, one-based `line`, redacted `excerpt`, and `truncated`. |
| `inspect_schema` | Scoped `read_schema` grant present | Required simple `table` identifier, optional `connection` (defaults to configured database); returns `state`, `connection`, `table`, `exists`, up to 64 `indexes`, and `records_exposed: false`. The selected connection must match the allowlist exactly. |
| `create_plan` | Scoped `create_plan` grant present | Required `operation`, optional `target`; returns `plan`, `fingerprint`, `provenance`, `applied: false`, and `apply_supported: false`. Each operation is checked against the allowlist at call time. |

Unknown tools, extra arguments, invalid identifiers, and over-limit inputs fail closed. A listed tool does not imply that every connection or plan operation is permitted. See [MCP tools and resources](AgentMcpTools.md) for the full result shapes and limits.

Resources and tools are registered explicitly with the official SDK. A 256 KiB input-line limit and a 64 KiB result limit bound the STDIO process. Do not put raw credentials, token values, private records, or arbitrary application source in Agent responses. Selected Studio/Health projections are reused where safe. Route source files are **not executed by Agent inspection**; this is why an uncached route/contract result may be partial. A resource may report `state: unavailable` or a separate `truncated: true` flag.

## Capability grants

`Config/Agent.php` defaults to:

```php
return [
    'grants' => [],
];
```

An operator may deny a default read capability with `false`, or deliberately grant a supported, narrowly scoped inspection/planning capability:

```php
return [
    'grants' => [
        'read_docs' => false,
        'read_schema' => ['connections' => ['reporting']],
        'create_plan' => ['operations' => ['migration_source', 'feature_blueprint']],
    ],
];
```

Each grant belongs to **one Application root**. `read_schema` requires an exact allowlisted connection; `create_plan` requires an exact allowlisted operation. Scope names are bounded identifiers. Unknown capabilities, unsupported operations, unscoped sensitive grants, and attempts to grant write capabilities fail configuration validation. Merely naming a capability in a prompt or tool argument does not grant it. `agent:status` reports the effective inventory without displaying secrets.

A client name or label such as "Codex", "Claude", "Administrator", or "Official Client" is informational. `AgentManager` receives the selected Application and trusted local grants; it does not promote a client label, prompt, or model identity into authorization. If an operator grants `create_plan` or `read_schema`, the SqueHub process enforces the configured operation or connection scope on every call, regardless of which compatible client sends it.

The registry also names `read_source`, `read_logs`, `run_tests`, `apply_plan`, `run_migration`, and `manage_package` so future policy can discuss them precisely. They are **unsupported through this Agent/MCP connection in v2.0.0**; adding them to configuration cannot make them executable. There is no arbitrary shell, generic file read/write/delete, directory listing, or arbitrary network request tool. `Config/Agent.php` is trusted local configuration; do not let an MCP caller edit it.

## Version-aware plans

The Agent reports framework version `2.0.0` and gives a current path-first routing example, such as `Route::path('/users/{id}')->get(...)->named('users.show')->through('auth')`. It reads route/contract metadata only when it is already registered or in a validated existing route-cache artifact; otherwise it reports a partial result rather than executing route registration PHP. It also lists available public Plugins symbols and, when launched by `agent:mcp`, the already-registered CLI commands. The Agent does not infer a complete API schema from Controller method names or execute a Controller to inspect it.

With a scoped `create_plan` grant, the `create_plan` tool accepts one of `migration_source`, `package_enable`, `kit_enable`, or `feature_blueprint`. `migration_source` takes no target; the others require a bounded target name. It delegates to the existing migration, Package, Kit, or Feature Blueprint planner and returns that subsystem's reviewable [Change Plan](ReviewableChanges.md), a fingerprint, and Agent/framework/Application provenance. The response says `applied: false` and `apply_supported: false`. It does **not** write source, enable a Package/Kit, run a Migration/Seeder, or apply a plan.

```text
inspect current framework and application metadata
    ↓
request a scoped plan
    ↓
review actions, conflicts, risks, provenance, and current state
    ↓
use the owning SqueHub command/workflow deliberately
    ↓
run tests and verify the result
```

The subsystem that owns an action retains its normal authorization, preview, freshness checks, and apply rules. An Agent proposal is untrusted input; neither its text nor its fingerprint is approval to mutate. A plan may become stale before a human acts and must be rechecked through the owning workflow. There is no generic MCP `apply_plan` tool. Database Migration execution and Package lifecycle changes remain separate explicit CLI/application operations.

The returned plan has `metadata.source: agent` and records an `untrusted_source` risk marker. Its provenance names the requested capability, framework version, Application fingerprint, and UTC creation time. The plan fingerprint changes if the underlying source plan changes. Review the reported actions, warnings, conflicts, and preconditions, then use the corresponding SqueHub CLI preview and deliberate apply path. The MCP response never supplies an execution permit.

## Security and operational limits

- **Application scope:** each manager uses its own Application root and grants. One application's MCP process does not inherit another's capability set.
- **No secrets by default:** `.env`, `APP_KEY`, database passwords, Redis/Mail/OAuth tokens, session IDs, Authorization headers, private Storage, logs, raw data rows, and arbitrary source are not MCP resources. Configured metadata is projected without credential values. Output filtering redacts recognized credential labels and value patterns, but it cannot classify every secret an application author might write into a route name, Package label, or Markdown sentence. Review that content before an AI host can read it.
- **No automatic mutation:** the default server provides inspection. A scoped plan returns data for review; it cannot perform the action. There is no MCP shell or general network escape hatch.
- **Client permissions are separate:** this SqueHub MCP connection grants no shell, general filesystem, or network tool. An AI client may have its own unrelated tools and permissions; review those in the client as well as the SqueHub grants.
- **STDIO locality:** a local host spawns the process. Remote Streamable HTTP would need independent authentication, authorization, Host/Origin and browser-origin checks, limits, TLS guidance, and a new threat review; it is not shipped.
- **Dependency boundary:** the optional SDK is experimental and not an ordinary application runtime requirement. MCP compatibility is pinned to the revision stated above; later-client behavior must be verified rather than assumed.
- **Inspection bounds:** routes, packages, contract operations, schema indexes, docs hits, and MCP messages are limited. Missing/unsafe metadata fails safely or reports unavailable/partial; inspection does not fetch arbitrary application data or execute route declaration PHP to complete an inventory.
- **Bootstrap boundary:** `agent:mcp` and `agent:status` choose inspection-only Package boot: enabled Package providers and Kit lifecycle hooks are not executed. Agent inspection does not run migrations, Seeders, Queue workers, Scheduler tasks, route-source PHP, or external AI/provider calls. Application Config PHP still evaluates during normal CLI bootstrap; keep configuration scripts free of side effects.

SqueHub Studio remains a separate development-only loopback inspector. Doctor remains its own CLI check. Agent reads selected existing inspection projections; it does not install a Studio or Health public endpoint or expose their private internals automatically.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| `agent:status` reports `mcp_sdk_available: false`, or `agent:mcp` cannot start | Install the optional `mcp/sdk` 0.8.1 dependency in this Application and rerun status. The server writes startup failure text to stderr; stdout is reserved for MCP frames. |
| Status or startup fails after editing `Config/Agent.php` | Check for unknown capability names, unsupported grants, an unscoped sensitive grant, or a scope value outside the permitted identifier form. The CLI intentionally hides configuration values in its error. |
| Routes or Application Contract return `state: partial` | No Project route PHP was loaded and no existing validated route cache was available. Use an explicit route or contract CLI workflow if a complete inventory is needed; Agent inspection will not execute route declarations to fill it. |
| `squehub://schema` or `inspect_schema` is absent or denied | Check the exact `read_schema.connections` allowlist and the chosen default or explicit connection. Schema access is disabled by default and never returns records. |
| Documentation search returns `state: unavailable` | Verify this Application has a physical `Docs/V2.x/` tree. The tool does not fall back to another directory. |

See [Local MCP setup](AgentMcpSetup.md) for host configuration and protocol troubleshooting.

## Verification

The Phase 25 Windows suite passed **2,946 tests, 22,117 assertions, 90 skips, zero failures, and zero errors**. The focused Agent filter passed **22 tests, 204 assertions, one skip**, covering capabilities and denial, malicious/oversized tool arguments, metadata bounds, safe plan creation, Application isolation, and real STDIO negotiation. A live Symfony InputStream client negotiated the served MCP revision with `agent:mcp`, read a resource, listed and called a tool, then reconnected to a fresh server process. The official SDK client subprocess case was skipped on Windows after `connect()` blocked. On a native case-sensitive Linux filesystem with PHP 8.5.4, Composer 2.9.5, and mcp/sdk 0.8.1, all five focused Agent files passed **22 tests, 219 assertions, zero skips, failures, and errors**; `AgentMcpTest` passed **3 tests/73 assertions**, including the official SDK client interoperability case. The Linux suite passed **2,946 tests, 22,364 assertions, 31 skips, zero failures, and zero errors**. The disposable `squehub-phase25.*` qualification directory was removed and cleanup verified. These results close Phase 25 cross-platform qualification within the tested Windows/native Linux profiles. At that checkpoint, remote MCP transport, production multi-host behavior, and a published v2 installation were not qualified. See [release readiness](ReleaseReadiness.md) for per-file results and the repeat procedure.
