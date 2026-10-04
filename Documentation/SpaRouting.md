# SPA routing in SqueHub v2

SPA fallback is optional. Ordinary SqueHub routes, API handlers, package routes, and View responses continue to work without a frontend build or Node.js. Enable the fallback only for an application that has a browser shell and client-side paths.

SPA routing passed Windows and native Linux focused and full-suite checks. See [current status](Status.md) for the tested environments; a particular reverse proxy or shared host still requires its own deployment check.

## Configuration

`Config/Frontend.php` selects one application-relative shell:

```php
return [
    // Other frontend settings may coexist with this browser routing policy.
    'spa' => [
        'enabled' => true,
        'prefix' => '/app',
        'view' => 'Frontend.App',
        'except' => ['/app/server-only'],
    ],
];
```

`view` is an existing logical `.squehub.php` View. `prefix` and `except` are literal path prefixes, not regular expressions. `/app` includes `/app` and `/app/dashboard`, but not `/application`. A prefix of `/` selects otherwise unmatched browser paths across the application. Configuration defaults to disabled. A disabled SPA has normal 404 behavior and does not render or load a shell.

These paths are **application-relative**. With `APP_BASE_PATH=/site`, a `prefix` of `/app` serves `/site/app/dashboard`; do not write `/site/app` in `Config/Frontend.php`. Requests outside `/site` remain 404. Existing asset and route URL helpers add the mount to public URLs.

## Precedence and boundaries

The web server serves real public static files before PHP. The SqueHub matcher then tries ordinary routes and existing explicit `Route::path(...)->fallback()` handlers. A method mismatch still returns 405. Only a request that remains unmatched reaches the SPA policy; an ineligible request continues to the application's 404 handler. An overlapping generic fallback at `/` runs before the SPA policy, so remove or narrow it when choosing a root SPA.

The SPA shell is selected only for `GET` or `HEAD` navigation that explicitly accepts HTML. Requests without an `Accept` header, JSON requests, XHR requests, non-navigation fetches, and requests accepting only `*/*` retain their normal route/error behavior. A `HEAD` response uses the same status and headers, and the HTTP sender omits its body. Unmatched unsafe methods are never converted to SPA HTML; global CSRF still runs before matching, so an unsafe request without a valid token may receive 403 before it can reach 404 or 405.

API scopes and `/api` paths, including additional configured API prefixes, never receive the SPA shell. Missing static-looking paths (including `/assets/missing.js` and other file-suffix paths), Health, Studio, and private framework/source locations retain missing/error behavior. Unsafe encoded paths, traversal, and malformed separators do not become shell responses. The SPA View does not override a real API route or turn a missing asset into a 200 page.

The shell is rendered through the regular View system on each selected request. Its response includes `Cache-Control: private, no-store` and `Vary: Accept` because the View may contain session-specific state. A static Vite `index.html` is not a substitute for a per-session shell when it displays a CSRF token. See [Frontend Session Auth](FrontendAuth.md), [Routing](Routing.md), and [mounted deployment](Deployment.md).
