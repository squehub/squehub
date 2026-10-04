# Deployment proof profiles

`php squehub doctor` keeps its existing health report. Add `--profile` to produce a separate, time-stamped deployment evidence record:

```bash
php squehub doctor --profile=shared-hosting
php squehub doctor --profile=single-server --json
php squehub doctor --profile=worker --probe
php squehub doctor --profile=multi-server --json
```

The profiles answer different questions. `shared-hosting` checks the PHP runtime, application environment, `public/index.php`, configuration, URL mount, application key presence, selected database, session, and Storage settings. It does not require Redis or a Queue worker. `single-server` additionally reports Queue selection, but a synchronous Queue is acceptable for an application without background workers. `worker` requires a persistent Queue selection and reports the availability of the worker and Scheduler command files; it cannot infer that either process is running. `multi-server` also reports shared-session, shared-Queue, shared-Cache, shared-rate-limit, and shared-Storage configuration. It leaves application-key consistency, version consistency, actual worker operation, and scheduler ownership unverified until evidence is supplied from the other nodes.

Each check contains `configured`, `reachable`, and `end_to_end_verified`. An unknown observation is `null`. Selecting a driver does not establish network reachability, and a reachable backend does not prove that jobs are being processed. The overall `level` is the highest tier established for every required check; one failed required configuration gives `not_ready`. A deliberately attempted probe that returns `false` also makes the profile CLI exit unsuccessfully, even when configuration remains present. Checks without a backend round trip stay unknown, so the overall profile can remain at `configured` while one bounded web check is end-to-end verified. The `checked_at` UTC timestamp matters because a successful observation can become stale. Code consuming `DeploymentEvidence` can call `fresh($maximumAgeSeconds)`; old evidence must not be presented as current qualification.

For worker and multi-server profiles, an `auto` driver is insufficient evidence of shared persistence: it may fall back to local state. Select and qualify an explicit persistent backend for those deployments.

## Deliberate probes

`--probe` asks existing Doctor checks to inspect selected local infrastructure. The database check runs `SELECT 1`; a Queue database check inspects required tables; a Redis-backed check may ping the selected server. Session and Storage checks inspect selection and local prerequisites, but do not create a session or write a Storage object. Their `reachable` state stays unknown when the check does not actually perform a backend round trip. No probe sends Mail, dispatches a job, runs a migration, or writes application data. `--probe` is available only with `--profile`.

For a web profile, supply an explicit URL to check bounded public behavior:

```bash
php squehub doctor --profile=shared-hosting \
  --verify-url=https://example.com/shop \
  --asset-path=/assets/default/favicon/site.webmanifest \
  --json
```

The URL path must equal `APP_BASE_PATH` (`/shop` in this example). The check makes seven GET requests without following redirects: application root, one public asset, a missing browser path, a missing API path with `Accept: application/json`, `/.env`, `/squehub`, and `/App/Core/View.php`. Successful bounded proof requires HTTP 200 for the root and asset, a non-HTML asset response, 404 for both missing paths, JSON content type for the API 404, and 403 or 404 for all three private-source paths. It scans only the first 8 KiB of every response for obvious debug or secret markers; this cannot certify an entire page. The CLI never prints response bodies. A failure returns a categorical result, not the response content. The caller chooses the URL and should use a deployment they control; the verifier does not discover or contact hosts automatically.

Web verification is a bounded sample. It does not certify every route, every node, end-to-end Mail delivery, actual worker execution, or backup recoverability. `--verify-url` is unavailable on `worker` and `multi-server` profiles. Profile evidence does not replace the normal `doctor`, `infrastructure`, or `recovery:plan` reports.

## Deployment interpretation

Keep `public/` as the web document root. Keep `.env`, `Project/`, `Config/`, `Database/`, and private `Storage/` outside direct web access. For subdirectory hosting, configure `APP_BASE_PATH` and verify the exact mount URL. Configure `APP_KEY` privately. A multi-server application needs a shared session strategy and appropriate persistent Queue and Storage services, but even valid local settings do not prove another node has the same key, build, or scheduler ownership policy. Record and compare those facts through your deployment process.

The evidence JSON contains only categorical states, timestamps, platform versions, and reason codes. It does not contain application keys, DSNs, credentials, response bodies, or remote node secrets. External SMTP/provider delivery, distributed worker behavior, and live recovery remain separate operator-run qualifications.
