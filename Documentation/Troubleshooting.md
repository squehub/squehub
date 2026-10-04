# Troubleshooting

Use `php squehub doctor` first. It returns safe checks for runtime, environment, database, selected drivers, and optional services. `php squehub infrastructure` reports configured and selected backends. Neither command prints application credentials.

| Symptom | Check | Next step |
| --- | --- | --- |
| **SqueHub setup required** page | `.env` missing or parsed values unchanged from `.example.env` | Copy the template, run `php squehub key:generate`, paste the full printed value into `APP_KEY`, review settings, and reload. [Installation](Installation.md) |
| Crypt unavailable | Blank/malformed `APP_KEY`, or missing sodium/OpenSSL | Set a private `base64:` key and install one supported cryptographic extension. [Cryptography](Cryptography.md) |
| Development server cannot bind port 8000 | Port is occupied or access is denied | Run `php squehub start` without a fixed port to try 8000–8099, or choose an allowed host/port explicitly. [CLI](Cli.md) |
| Source or `.env` appears as plain text in the browser | Web server document root points at the repository root | Point it to `public/` and verify static access to private files is blocked. [Deployment](Deployment.md) |
| Blank/broken browser session or 500 at startup | PHP session path or selected backend is unavailable | Make `session.save_path` writable/protected, or fix the chosen Redis backend. [Sessions](Sessions.md) |
| Debug bar stays off/on after `.env` edit | Shell `APP_DEBUG` may override `.env`; browser may reuse old page | Check effective environment and make a fresh request; `.env` changes load per request under `php squehub start`. [Configuration](Configuration.md) |
| 403 on POST or JSON request | Global CSRF runs before routes | Include `@csrf` in browser forms or send the session token with the configured header. Do not disable CSRF for ordinary forms. [CSRF](Csrf.md) |
| View not found | Template path or extension is wrong | Use `Project/Views/Name.squehub.php` and `View::render('Name')`; root `views/` is no longer searched. [Views](Views.md) |
| View compilation or cache preparation fails | Invalid template source, unsafe source path, or unwritable `Storage/Views` | Fix the logical View/source diagnostic; make the protected compiled-View runtime directory writable. `php squehub view:cache` checks current source without rendering; `view:clear` removes only owned derived artifacts when maintenance is needed. [Compiled Views](CompiledViews.md) |
| Model `create()` rejects fields | Modern Model is guarded by default | Declare `$fillable` or another deliberate mass-assignment policy. [Models](Models.md) |
| Queue dispatch appears synchronous | `QUEUE_CONNECTION=sync` | Select/configure Database or Redis Queue, install required tables, and run a worker. [Queue](Queue.md) |
| Scheduler lists tasks but nothing runs | No due schedule, missing persistent tables, or no host cron | Check `schedule:list`, install selected tables, and invoke `schedule:run` once per minute. [Scheduler](Scheduler.md) |
| Redis warning in Doctor | Optional Redis is not reachable/configured | Ignore only when no selected service requires it; explicit Redis drivers must have a reachable server. [Redis](Redis.md) |
| A `make:*` command refuses a name or target | Invalid name, existing file or case variant, unsafe path, or missing/mis-cased Package | Run the same command with `--preview`, check the reported path and Package casing, then choose a safe unused name. Generation does not overwrite. [Generators](Generators.md) |

Production HTTP errors intentionally omit stack traces and secrets. Use safe request IDs, protected logs, and controlled development mode to investigate failures. Do not paste `.env`, full authentication tokens, password-reset tokens, Queue payloads, or Mail bodies into public issue reports. See [Logging](Logging.md), [Diagnostics](Diagnostics.md), and [Health](Health.md).
