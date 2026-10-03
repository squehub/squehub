# Agent and AI integration

SqueHub provides an optional, local bridge from a compatible MCP host to bounded framework metadata. The AI host owns its model and prompts; SqueHub owns the application context, capability checks, and existing subsystem boundaries. An ordinary SqueHub application needs no MCP client, LLM account, Node.js, Redis, Docker, or remote Agent service.

> Inspect first. Propose second. Mutate only through a deliberate, authorized SqueHub workflow.

Phase 25A–25F is **complete: implemented, tested, passed, and qualified on Windows and user-run native Linux**, including official MCP SDK client interoperability on Linux. This guide describes the implemented boundaries, not a published v2 release or an autonomous code-writing service. See [v2 status](Status.md) for the release boundary.

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

The default `search_docs` tool searches only physical Markdown files under this application's `Docs/V2.x/`. It accepts a 2–120-byte query and 1–20 results, searches at most 512 files of at most 256 KiB each, and returns at most a 240-byte excerpt per match. Unsafe linked paths, arbitrary filesystem searches, vendor traversal, and `.env` are excluded. If this locally ignored documentation is absent from an installed artifact, the result is **unavailable**, not an invitation to scan another path. A separate `inspect_schema` tool is listed only when the schema capability is granted; it uses the existing Schema reader and returns bounded table/index metadata, never table rows.

Resources and tools are registered explicitly with the official SDK. A 256 KiB input-line limit and a 64 KiB result limit bound the STDIO process. Do not put raw credentials, token values, private records, or arbitrary application source in Agent responses. Selected Studio/Health projections are reused where safe. Route source files are **not executed by Agent inspection**; this is why an uncached route/contract result may be partial. A resource's `state` can be unavailable or truncated.

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

The registry also names `read_source`, `read_logs`, `run_tests`, `apply_plan`, `run_migration`, and `manage_package` so future policy can discuss them precisely. They are **unsupported as remote Agent operations in v2.0.0**; adding them to configuration cannot make them executable. There is no arbitrary shell, generic file read/write/delete, directory listing, or arbitrary network request tool. `Config/Agent.php` is trusted local configuration; do not let an MCP caller edit it.

## Version-aware plans

The Agent reports this working tree as `2.0.0-dev` and gives a current path-first routing example, such as `Route::path('/users/{id}')->get(...)->named('users.show')->through('auth')`. It reads route/contract metadata only when it is already registered or in a validated existing route-cache artifact; otherwise it reports a partial result rather than executing route registration PHP. It also lists available public Plugins symbols and, when launched by `agent:mcp`, the already-registered CLI commands. The Agent does not infer a complete API schema from Controller method names or execute a Controller to inspect it.

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

## Security and operational limits

- **Application scope:** each manager uses its own Application root and grants. One application's MCP process does not inherit another's capability set.
- **No secrets by default:** `.env`, `APP_KEY`, transport credentials, private Storage, logs, raw data rows, and arbitrary source are not MCP resources. Configured metadata is projected without credential values. Treat application-authored route descriptions and documentation as untrusted content when an AI host summarizes them.
- **No automatic mutation:** the default server provides inspection. A scoped plan returns data for review; it cannot perform the action. There is no MCP shell or general network escape hatch.
- **STDIO locality:** a local host spawns the process. Remote Streamable HTTP would need independent authentication, authorization, Host/Origin and browser-origin checks, limits, TLS guidance, and a new threat review; it is not shipped.
- **Dependency boundary:** the optional SDK is experimental and not an ordinary application runtime requirement. MCP compatibility is pinned to the revision stated above; later-client behavior must be verified rather than assumed.
- **Inspection bounds:** routes, packages, contract operations, schema indexes, docs hits, and MCP messages are limited. Missing/unsafe metadata fails safely or reports unavailable/partial; inspection does not fetch arbitrary application data or execute route declaration PHP to complete an inventory.
- **Bootstrap boundary:** `agent:mcp` and `agent:status` choose inspection-only Package boot: enabled Package providers and Kit lifecycle hooks are not executed. Agent inspection does not run migrations, Seeders, Queue workers, Scheduler tasks, route-source PHP, or external AI/provider calls. Application Config PHP still evaluates during normal CLI bootstrap; keep configuration scripts free of side effects.

SqueHub Studio remains a separate development-only loopback inspector. Doctor remains its own CLI check. Agent reads selected existing inspection projections; it does not install a Studio or Health public endpoint or expose their private internals automatically.

## Verification

The final Windows suite passed **2,946 tests, 22,117 assertions, 90 skips, zero failures, and zero errors**. The focused Agent filter passed **22 tests, 204 assertions, one skip**, covering capabilities and denial, malicious/oversized tool arguments, metadata bounds, safe plan creation, Application isolation, and real STDIO negotiation. A live Symfony InputStream client negotiated the served MCP revision with `agent:mcp`, read a resource, listed and called a tool, then reconnected to a fresh server process. The official SDK client subprocess case was skipped on Windows after `connect()` blocked. On a native case-sensitive Linux filesystem with PHP 8.5.4, Composer 2.9.5, and mcp/sdk 0.8.1, all five focused Agent files passed **22 tests, 219 assertions, zero skips, failures, and errors**; `AgentMcpTest` passed **3 tests/73 assertions**, including the official SDK client interoperability case. The final Linux suite passed **2,946 tests, 22,364 assertions, 31 skips, zero failures, and zero errors**. The disposable `squehub-phase25.*` qualification directory was removed and cleanup verified. These results close Phase 25 cross-platform qualification within the tested Windows/native Linux profiles. They do not qualify remote MCP transport, production multi-host behavior, or a published v2 installation. See [release readiness](ReleaseReadiness.md) for per-file results and the repeat procedure.
