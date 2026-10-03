# Connect a local MCP client

SqueHub's optional Agent lets a compatible local MCP client inspect bounded framework and application metadata. The server runs as a PHP **STDIO** process for one Application root. It is read-only with the shipped configuration. This MCP connection offers no general shell, filesystem browser, or plan-apply tool; the client may have separate tools of its own. Start with the [Agent and AI overview](AgentAndAI.md), and use the [capabilities and tools reference](AgentMcpTools.md) when deciding what to expose.

This guide describes SqueHub `2.0.0` and its optional local Agent server. The server advertises MCP protocol revision **2025-11-25**. The optional `mcp/sdk` 0.8.1 also supports the newer `2026-07-28` revision, but SqueHub does not advertise that revision. The local command starts no HTTP listener, and remote Streamable HTTP is not shipped. An ordinary SqueHub application does not need an MCP client, an LLM account, or a remote Agent service.

## Prepare the application

Use a complete SqueHub application checkout and follow the [installation guide](Installation.md). Run commands from that application's root, where the lowercase `squehub` launcher and `Config/Agent.php` live. The development checkout includes experimental `mcp/sdk` 0.8.1 as a development dependency. If an installed application deliberately enables MCP without development dependencies, install the optional SDK in that application:

```bash
composer require mcp/sdk:^0.8.1
php squehub agent:status --json
```

`agent:status` does not start a server. Check `mcp_sdk_available`, `mode`, `protocol.supported_stdio`, and the capability inventory in its output. With the shipped empty `grants` array, `mode` is `read-only`, `protocol.supported_stdio` is `2025-11-25`, and `remote_http` is `false`. The Application fingerprint in this response identifies the current root without exposing its path; it is specific to your installation.

The server command is `php squehub agent:mcp`. It waits for protocol input on stdin, writes MCP frames only to stdout, and writes operational errors to stderr. Let the client launch it; running it manually without a client can appear to hang because it is waiting for stdin. Press **Ctrl+C** to stop that manual process. Ensure the host can find the intended PHP executable. A host using another working directory should set `cwd` to the application root and use an absolute path to the `squehub` launcher if needed.

## Generic STDIO configuration

MCP hosts use different configuration formats. These JSON-shaped examples show the required process fields on each platform; replace the illustrative paths with the PHP executable and SqueHub application root on your machine.

Windows example:

```json
{
  "command": "C:\\php\\php.exe",
  "args": ["C:\\apps\\my-squehub-app\\squehub", "agent:mcp"],
  "cwd": "C:\\apps\\my-squehub-app"
}
```

Linux example:

```json
{
  "command": "/usr/bin/php",
  "args": ["/srv/my-squehub-app/squehub", "agent:mcp"],
  "cwd": "/srv/my-squehub-app"
}
```

In either case, `args` and `cwd` must point to the same application root. Keep the process environment private. The client must support negotiation with SqueHub's served `2025-11-25` revision. Each application you connect needs its own process and its own `Config/Agent.php` grants. `agent:mcp` performs inspection-only Package boot; enabled Package providers, Kit lifecycle hooks, Project route source PHP, migrations, Seeders, workers, and Scheduler tasks are not run to fill inspection results. Normal configuration PHP still evaluates during CLI bootstrap, so keep configuration free of side effects.

## Codex CLI

Install the Codex CLI with the [official OS-specific guide](https://learn.chatgpt.com/docs/codex/cli), then confirm `codex --version` works in the shell that will configure it. If the shell says `codex` is not recognized, resolve the CLI installation or `PATH` first; that error occurs before SqueHub starts.

From the application root, the [Codex MCP setup guide](https://learn.chatgpt.com/docs/extend/mcp?surface=cli) documents this local STDIO registration form:

```bash
codex mcp add squehub -- php squehub agent:mcp
codex mcp list
```

For a client launched from elsewhere, set the application root explicitly in its MCP server configuration:

```toml
[mcp_servers.squehub]
command = "php"
args = ["squehub", "agent:mcp"]
cwd = "/absolute/path/to/application"
```

Use `/mcp` in the Codex terminal UI to inspect its MCP connections. The command and configuration syntax above are documented client forms; a live SqueHub-to-Codex handshake has not been qualified here. Codex CLI, the ChatGPT desktop app, and the Codex IDE extension share MCP configuration when they use the same Codex host. In the desktop app, inspect the server under **Settings > MCP servers** and restart the client after configuration. A different host needs its own reviewed server registration. ChatGPT web does not read local Codex configuration or launch this STDIO process merely because a local client is configured. Follow the client's trust and approval controls before allowing it to use a connected server.

To replace a saved Codex CLI definition, `codex mcp remove squehub` removes that registration; then add the reviewed command again from the intended application root.

## Claude Code

From the application root, [Claude Code's MCP documentation](https://code.claude.com/docs/en/mcp) documents this local STDIO form:

```bash
claude mcp add --transport stdio squehub -- php squehub agent:mcp
claude mcp list
```

Use `claude mcp get squehub` or `/mcp` to inspect the registered connection. This is vendor-documented command syntax; a live SqueHub-to-Claude Code handshake has not been qualified here. Ensure the saved command runs in the intended application root when Claude Code starts a new session.

To replace a saved Claude Code definition, `claude mcp remove squehub` removes that registration; then add the reviewed command again.

## First inspection

After connecting, list MCP resources and tools in your host. The default resource list includes `squehub://framework`, `squehub://application/contract`, `squehub://routes`, `squehub://packages`, `squehub://health`, and `squehub://cli`; the default tool list includes `search_docs`. Read `squehub://framework` to confirm the reported framework version, protocol revision, and Application fingerprint. A host can then read routes or search local `Docs/V2.x/` Markdown. See the [resource and tool reference](AgentMcpTools.md) for the exact boundaries and result states.

Useful first requests to the host are:

- "Read SqueHub's framework and routes resources. Report the version and routing style, and say when the route list is partial."
- "Use `search_docs` for `idempotency`. Give the returned documentation path and line numbers."
- After a local `create_plan.operations` grant for `feature_blueprint`: "Request a `feature_blueprint` plan for `Blog`. Summarize actions, conflicts, and risks. Do not apply it."

These requests exercise bounded inspection and proposal. Prompt text cannot grant a capability, and results still need review against the current application.

The default grants do not expose `inspect_schema` or `create_plan`. Enable either only for exact local scopes in `Config/Agent.php`; inspect the resulting inventory with `agent:status --json` before reconnecting the client. Creating a plan returns review data only. Use the owning [SqueHub change workflow](ReviewableChanges.md) deliberately after reviewing current state, conflicts, and risk.

## Troubleshooting

| Symptom | Check and action |
| --- | --- |
| `agent:mcp` reports the SDK is unavailable | Run `php squehub agent:status --json` in the same application and PHP environment as the host. Install `mcp/sdk:^0.8.1` deliberately if MCP is required. |
| The host cannot start the process | Check the PHP executable, the lowercase `squehub` launcher, `cwd`, file paths, and the application's normal CLI setup. Keep protocol stdout free of host wrapper messages. |
| The host rejects the handshake | Check that it can negotiate the server's advertised `2025-11-25` STDIO revision. A client that requires only another revision has not been verified with this integration. |
| `create_plan` or `inspect_schema` is absent | These tools are hidden until their supported capabilities have exact operation or connection grants. `agent:status --json` shows the effective inventory. Unsupported write grants cannot enable more tools. |
| Routes or contract operations are partial | Agent inspection does not execute Project route declarations. It reads routes already registered in memory or an existing validated route-cache artifact. Use the ordinary route and contract workflows separately when a complete inventory is needed. |
| `search_docs` is unavailable | The installed application may omit its physical `Docs/V2.x/` Markdown tree, or `read_docs` may be denied. Agent does not search another directory as a fallback. |
| A plan fails or a table is denied | Check the exact `create_plan.operations` or `read_schema.connections` allowlist and valid target or table identifiers. A missing Package or Kit target cannot be planned successfully. |
| PHP reports a duplicate OpenSSL extension on Windows | Inspect the loaded `php.ini` for both `extension=openssl` and `extension=php_openssl.dll`. Keep only one OpenSSL extension declaration, and preserve the separate `[openssl]` CA configuration. |

For the duplicate OpenSSL warning, inspect the active CLI configuration with PowerShell:

```powershell
php --ini
Select-String -Path 'D:\xampp\php\php.ini' -Pattern 'openssl' -CaseSensitive:$false
```

The `D:\xampp\php\php.ini` path is an example; use the active file reported by `php --ini` in the second command. Remove only the redundant extension declaration after confirming which configuration file PHP loads; keep the `[openssl]` section and its CA settings. This warning is a local PHP configuration issue, not an Agent grant or MCP protocol error.

After the edit, run `php --ini` again and confirm it prints no startup warning. Confirm OpenSSL is loaded once:

```powershell
php -m | findstr /I openssl
```

SqueHub's Phase 25 Windows and native Linux Agent qualifications include real STDIO negotiation and an official PHP SDK client on Linux. They did not exercise Codex or Claude Code host interoperability or remote MCP transport. See [v2 status](Status.md) for release qualification and the [Agent overview](AgentAndAI.md#verification) for focused evidence.
