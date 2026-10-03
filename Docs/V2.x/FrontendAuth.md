# Frontend Session Auth and CSRF

A browser frontend on the application's own origin uses SqueHub's existing Session guard and global CSRF middleware. React, Vue, and native modules do not need a separate authentication system. Configure a Session guard and identity provider in `Config/Auth.php` as described in [Authentication](Authentication.md); the shipped default does not assume an application identity.

Phase 22I passed Windows real Session-cookie/CSRF HTTP checks and the user-run native Linux frontend focused suite. The exact qualification and remaining release boundaries are in [release readiness](ReleaseReadiness.md).

## Render a session-bound token in the shell

The application can return a dynamic shell through the existing Plugins View API:

```php
use App\Plugins\{Route, View};

Route::path('/frontend')->get(static fn () => View::response(
    'Frontend.App',
    headers: ['Cache-Control' => 'private, no-store']
));
```

In `Project/Views/Frontend/App.squehub.php`:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
<div id="frontend-app"></div>
```

`{{ ... }}` escapes the value for the HTML attribute. `csrf_token()` uses the current Session's existing CSRF manager; no second token store or frontend-only guard is created. The token is not an authentication credential. The dynamic page must not be published as a shared-cacheable static HTML file or baked into a Vite manifest. The opt-in [SPA fallback](SpaRouting.md) can return this same View for unmatched browser navigation and adds `Cache-Control: private, no-store` automatically.

## Send same-origin requests

Read the token from the rendered page and submit it in the configured `X-CSRF-Token` header on unsafe JSON requests:

```javascript
const token = document.querySelector('meta[name="csrf-token"]')?.content;

const response = await fetch('/api/profile', {
  method: 'PATCH',
  credentials: 'same-origin',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-Token': token,
  },
  body: JSON.stringify({ name: 'Ada' }),
});
```

For a mounted application, use the mounted API URL (for example `/site/api/profile`) from application configuration or a rendered public URL. Never assume `/api` is at the domain root when `APP_BASE_PATH` is set. The browser sends the Session cookie according to its configured path, Secure, HttpOnly, and SameSite policy. Keep the frontend and backend on one browser origin; if Vite is the browser-facing development server, proxy selected PHP paths so cookies and CSRF requests use that same origin. Do not disable CSRF to make the development proxy work.

The header is authoritative if supplied, even when empty or invalid. The configured `_csrf` body field and the legacy `_token` body field are also accepted where applicable. URL query tokens are not. CSRF runs before route middleware, controller validation, and Auth. A missing or invalid token returns safe 403 JSON or HTML without executing login/logout. `GET`, `HEAD`, and `OPTIONS` do not require a token; they must not mutate application state.

## Login and logout remain ordinary SqueHub routes

An application defines its login policy and routes explicitly. For example, after configuring a Session guard and identity source:

```php
use App\Plugins\{Auth, JsonResponse, Request, Route};

Route::path('/api/login')->post(static function (Request $request): JsonResponse {
    $accepted = Auth::attempt([
        'email' => (string) $request->input('email'),
        'password' => (string) $request->input('password'),
    ]);

    return new JsonResponse(['authenticated' => $accepted], $accepted ? 200 : 401);
});

Route::path('/api/me')->get(static fn (): array => [
    'id' => Auth::id(),
])->through('auth');

Route::path('/api/logout')->post(static function (): array {
    Auth::logout();
    return ['logged_out' => true];
})->through('auth');
```

Use the normal [rate limiter](RateLimiting.md), validation, and application-specific response policy for a public login endpoint. A successful login regenerates the Session ID while preserving the current CSRF token. Logout invalidates the Session and its token; fetch a fresh dynamic shell before another unsafe browser request. An unauthenticated protected API request returns the existing 401 behavior. The SPA fallback does not catch `/api/*` or turn an Auth/CSRF failure into HTML.

## Token and cross-origin alternatives

Personal access tokens remain available for separate frontends, mobile applications, and external clients. A route using `RequireToken::guard(...)` selects a named Bearer guard and does not fall back to a Session identity. An explicit CSRF path exclusion is suitable only for a dedicated Bearer-only unsafe route; never share that exclusion with a cookie-authenticated route. See [API Tokens](ApiTokens.md).

`Config/Api.php` remains the sole CORS policy. The frontend integration does not enable wildcard origins, credentialed CORS, or automatic CSRF exemptions. An authorized CORS origin permits a browser to read a response; it does not replace Session cookie rules or the CSRF token check. See [CORS](Cors.md) and [CSRF](Csrf.md).
