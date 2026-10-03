# SqueHub Dev

**SqueHub Dev** runs a coordinated local development session from the application root. The command is available in the v2.0.0 development working tree; it is not part of a published stable v2 release.

```bash
php squehub dev
```

By default, Dev performs a concise structured [Doctor](Health.md) preflight and starts the PHP development server. It does not start Queue workers, Scheduler, Redis, Node.js, a frontend server, or an external database server. A normal PHP application can use it without those optional services. A selected [frontend profile](FrontendProfiles.md) can opt into Vite supervision with `--frontend`.

The optional Phase 22 frontend supervision passed a real user-run native Linux HMR/proxy smoke and process cleanup, in addition to Windows checks. [Release readiness](ReleaseReadiness.md) records the exact tool versions and platform limits. Ordinary Dev remains Node-free.

## Dev and start

```bash
php squehub start
php squehub dev
```

`start` independently runs the PHP development server. `dev` coordinates that same server with preflight and any explicitly selected supported service. Neither command changes Package or [Kit](Kits.md) activation or application source. Dev never executes Kit lifecycle hooks. Both are local development tools; use a proper web server and process manager for [production deployment](Deployment.md).

The server serves `public/` as its document root. Ordinary files under `public/assets/` are served directly; other requests use `public/index.php` and the normal Application boot. The URL Dev prints is the local address it selected, not a public HTTPS URL or proof of application readiness. Dev does not request `/` as a readiness probe because an application route can have effects.

With `APP_BASE_PATH=/app`, `start` and `dev` print a local URL ending in `/app` and handle application requests there. The built-in router serves only validated GET/HEAD `/app/assets/...` paths from contained ordinary files under `public/assets/`; it does not make framework source or arbitrary public files available through the mount. The mount configuration is read for each development-server request, so changing `.env` takes effect on the next request. This is a local server behavior; see [subdirectory deployment](Deployment.md#subdirectory-mounts-and-shared-hosting) for public-only Apache and shared-host mapping.

## Host and port

```bash
php squehub dev --host=localhost --port=8001
```

`--host` and `--port` follow the existing `start` server contract. With no port option, the server tries the first available port from 8000 through 8099 and prints the selected URL. An unavailable explicitly requested port fails rather than selecting another one. A binding or startup error is reported; it is not hidden behind a healthy Dev banner. `php squehub dev --help` shows the exact current options.

## Preflight and setup

Dev displays the framework's structured Doctor summary rather than parsing CLI text or duplicating checks, then uses the existing readiness report as the startup gate. Readiness failures stop startup. Optional Doctor warnings, such as unavailable unused Redis or unconfigured SMTP, are visible but do not block a basic application. A Doctor result outside the configured readiness requirements can still need attention even when Dev starts. Preflight is a startup check, not continuous monitoring or a production-readiness certificate.

If `.env` is missing or unchanged from `.example.env`, configure the application manually or use [SqueHub Setup](Setup.md), then run `php squehub doctor`. Dev does not create `.env`, generate `APP_KEY`, install dependencies, run Migrations or Seeders, or repair failed checks. The web server may still create ordinary runtime files through the configured Logging, Cache, and Session services during requests.

Doctor's Package and Kit summaries come from the [SqueHub Activation Registry](ActivationRegistry.md). Dev never changes activation state. Web requests use normal Application boot: enabled Packages can contribute routes and services, disabled Packages stay inactive, and a broken enabled Package is not silently bypassed. Enabled Kits remain composition metadata and do not boot lifecycle PHP. See [Packages](Packages.md) and [Kits](Kits.md).

## Optional Queue worker

```bash
php squehub dev --queue
```

`--queue` starts the existing `queue:work` worker alongside the server only when the application's selected default Queue connection is a persistent worker-backed driver. A `sync` Queue executes jobs in the calling process and does not need a worker; asking Dev for one on that configuration fails clearly. Dev does not select a different Queue connection, create Queue tables, start Redis, or alter retry policy. Configure and verify the Queue backend first using [Queue](Queue.md) and [Doctor](Health.md). `php squehub queue:work` remains available independently.

The selected Queue worker is optional, but its unexpected exit ends the coordinated Dev session and stops the server. This makes a failed requested worker visible instead of leaving a session that appears to process jobs. Stopping Dev can interrupt an in-flight Queue attempt. Normal reservation expiry and retry rules may execute that job again, so Queue delivery remains at least once.

## Optional frontend process

```bash
php squehub profile:apply react --preview
php squehub profile:apply react --yes
cd Project/Frontend
npm install
cd ../..
php squehub dev --frontend
```

`profile:apply` does not run npm. Only `dev --frontend` asks for Node and the selected local Vite package. It starts the same PHP server plus a loopback Vite process. The backend View remains the document and supplies CSRF and module tags; Vite supplies modules and HMR, not a second application router. The selected frontend process is supervised like the optional Queue worker: unexpected exit ends the session, and Ctrl+C stops both owned children. The PHP server receives the selected Vite URL as a process-only environment override; Dev does not edit `.env`, write a runtime port registry, or use an arbitrary request Host as a proxy target.

The default frontend port is the first available loopback port beginning at the configured 5173; `--frontend-port=5174` requests one exact port and fails if unavailable. `--frontend-port` requires `--frontend`. With `APP_BASE_PATH=/app`, the backend URL retains `/app`; generated fetch helpers use that mount and same-origin Session/CSRF. An unselected application never probes Vite or Node. The normal `php squehub start` command remains server-only. For production, run `php squehub frontend:build` and deploy the verified manifest/output; Dev is not a production process manager.

## Scheduler status

The current Scheduler offers `schedule:run` as a one-shot command; it does not provide a canonical long-running worker for Dev to supervise. Run it through the documented host cron or task scheduler where needed. Dev has no `--scheduler` option. See [Scheduler](Scheduler.md).

Scheduler is not started by Dev. Run its one-shot command through the documented host scheduler where needed.

## Foreground lifecycle and limits

Dev stays in the foreground and forwards output from processes it starts. With multiple processes, output is labelled by service. Press **Ctrl+C** to stop the session. Dev starts PHP children directly with argument arrays and asks each owned child to terminate, then releases its output files and process resources. On Windows, termination directly stops the PHP child; an in-flight Queue job may be interrupted. On POSIX systems, Dev sends SIGTERM and gives the child a bounded grace period before a forceful stop. A managed process that exits unexpectedly ends the session with a failure and triggers cleanup of its siblings. Shutdown is best-effort for the directly owned children; independently detached descendants remain outside Dev's control.

Dev is a local convenience command, not a production daemon manager, file watcher, browser reloader, or new Logging system. It does not install Composer/npm packages, create a persistent process registry, or automatically restart workers. The Queue and Scheduler commands remain independent.
