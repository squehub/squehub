# SqueHub v2 authentication foundation

Authentication identifies an application identity through an Application-owned guard. The session guard uses the SqueHub session store; a named [personal access token guard](ApiTokens.md) authenticates an explicit Bearer header without starting a session. Both use the existing modern `App\Plugins\Model` identity provider. The separate [OIDC login client](OAuth.md) verifies an external identity that application code may map to a local model before calling `Auth::login()`. SqueHub does not create a User model, registration form, login route, or token-issuance endpoint. The default `Config/Auth.php` has no guard or identity, so a fresh framework boots without a user table, database connection, or browser session. Configure an identity before calling `auth()` methods that need a guard.

Optional [multi-factor authentication](MFA.md) adds a bounded TOTP or recovery-code challenge after the primary factor. An enrolled identity remains a guest until the second factor succeeds; a remembered browser cannot bypass that challenge.

## Configure an identity

An application model implements the small `Authenticatable` contract. It does not need an authentication superclass. The password attribute is hidden from normal model serialization; authentication reads it through `authPasswordHash()` inside the trusted guard/provider boundary.

```php
use App\Plugins\Authenticatable;
use App\Plugins\Model;

final class User extends Model implements Authenticatable
{
    protected string $table = 'users';
    protected array $fillable = ['email', 'password', 'name'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string
    {
        return $this->getAttribute('id');
    }

    public function authPasswordHash(): string
    {
        return $this->getAttribute('password');
    }
}
```

Set `Config/Auth.php` in the project to a configuration like this:

```php
return [
    'default' => 'web',
    'guards' => [
        'web' => ['driver' => 'session', 'identity' => 'users'],
    ],
    'identities' => [
        'users' => [
            'driver' => 'model',
            'model' => User::class,
            'identifier' => 'id',
            'password' => 'password',
            'credentials' => ['email'],
        ],
    ],
    'passwords' => [
        'algorithm' => 'default',
        'options' => [],
        'rehash_on_login' => true,
        'max_bytes' => 4096,
    ],
    'browser' => [
        'login_path' => '/login',
        'authenticated_path' => '/dashboard',
    ],
];
```

The built-in guards are `session` and `token`; the identity driver remains `model`. The example configures a browser session guard. Add a named token guard and `tokens` settings as described in [API tokens](ApiTokens.md) when an application needs Bearer authentication. Each configured model must extend the modern Model and implement `Authenticatable`. Authentication column names pass database identifier validation before a query runs. The lookup fields should have suitable database uniqueness constraints; an ORM lookup is not a substitute. Normal ModelQuery behavior excludes soft-deleted identities. The legacy `App\Core\Model` is outside this provider.

## Use the guard

```php
if (auth()->attempt(['email' => $email, 'password' => $plainPassword])) {
    // The existing session ID has been rotated and the identity is available.
}

$user = auth()->user();
$id = auth()->id();
$signedIn = auth()->check();
$guest = auth()->guest();

auth()->login($user); // For an already trusted, persisted identity.
auth()->logout();
```

`attempt()` returns `false` for missing credentials, an unknown account, or a wrong password. Unknown credential **keys** are a developer configuration error. The password input is never passed to ModelQuery. An unknown account performs one lazily cached dummy hash verification to reduce obvious lookup timing differences; this is not a claim of equal timing. Database, hasher, rehash, and session failures propagate. Successful verification can rehash an older password with the configured algorithm before the login state is written. `PasswordHasher` is injectable for registration and password-change code; use its `hash()`, `verify()`, and `needsRehash()` methods rather than storing plaintext. Native bcrypt input longer than 72 bytes is rejected to avoid silent truncation. The configured byte limit is technical resource protection, not a password-strength policy.

The Model provider writes only its configured password column during rehash. It does not save unrelated pending model edits or advance `updated_at`; a failed database update leaves the model's local password state unchanged and prevents login.

`auth()->guard('admin')` selects another configured guard explicitly. Guard instances and providers belong to the Application; resolved users are cached only until the next Kernel request. A first `user()` call with a session ID queries the identity provider; later calls in that request reuse it. The next request queries again. A removed or soft-deleted identity clears its stale guard entry and appears as a guest. Token middleware selects its named request guard so normal `auth()->user()` and Authorization checks see that request's token identity without changing the configured browser default for other requests.

Views can present this state through `@auth` and `@guest`, with optional static guard names and `@else` branches. A bare `@auth` is false and `@guest` is true when no default or request-selected guard exists. `@auth('admin')` means the configured guard named `admin` is authenticated; it does not confer an admin role or ability. The same compiled View evaluates the current request when rendered, rather than retaining a prior identity. See [Auth, Guards, Session and Authorization](ViewSecurity.md). Route `auth`/`guest` middleware and server-side Authorization remain necessary to protect actions.

## Session and route behavior

Login validates provider support and a persisted identifier, rotates the session ID, then stores that identifier and a one-way credential fingerprint in reserved `_squehub` metadata under the guard name. It preserves ordinary session data, flash, old input, and the CSRF token according to SessionStore regeneration semantics. `session()->all()` never exposes authentication metadata. A failed credential attempt does not rotate the session ID or write either value. A missing or mismatched fingerprint clears that guard's authentication on its next identity resolution.

Logout invalidates the **whole** session: all named guards, application data, flash, old input, and CSRF state are cleared, and the session ID changes. Flash a logout message *after* calling `logout()` if the application needs one. A failed invalidation does not clear the guard's in-memory cache as if logout had succeeded.

```php
use App\Plugins\Route;

Route::path('/account')->get([AccountController::class, 'show'])->through('auth');
Route::path('/login')->get([LoginController::class, 'show'])->through('guest');
```

`auth` and `guest` are built-in route aliases for the configured default guard. A route that requires a named token guard uses `RequireToken::guard('api')` instead; see [API tokens](ApiTokens.md). Global CSRF middleware runs first, then route middleware, then the controller. A browser login POST remains CSRF-protected, including when a credentialed [CORS policy](Cors.md) permits its Origin. Within an enabled [API response scope](ApiResponses.md), `auth` rejection uses 401 `unauthenticated` with `Authentication is required.`, while `guest` rejection uses 403 `forbidden`. Both use the API error envelope and trusted request ID. Session-auth rejection adds no Bearer challenge; explicit token-auth rejection can add the generic `WWW-Authenticate: Bearer` challenge.

Outside API scope, unauthenticated JSON requests receive 401 with `Authentication required.`; authenticated JSON requests to guest routes receive 403 with `Guest access required.`. Browser requests redirect with 303 to configured internal paths, or receive safe 401/403 HTML when the corresponding path is null. Redirect configuration rejects external, protocol-relative, escaped, and control-character paths. There is no intended-URL redirect in this foundation.

`login_path` and `authenticated_path` remain application-relative configuration such as `/login` and `/dashboard`. With `APP_BASE_PATH=/app`, their browser redirects target `/app/login` and `/app/dashboard`; do not store the mount a second time in Auth configuration. The Session cookie Path is configured separately by `SESSION_PATH` and is not automatically narrowed to `/app`. See [mounted requests](Http.md#url-base-path-and-mounted-requests) and [Sessions](Sessions.md).

## Optional remembered browser login

Remember-me is disabled by default. Enable it for a browser Session guard in `Config/Auth.php`, install `Database/Migrations/2026_09_30_create_remember_tokens.php` with the normal migration command, then explicitly request it only when a user selects the application's remember option:

```php
// Config/Auth.php, alongside the configured web guard and identity.
'remember' => [
    'enabled' => true,
    'driver' => 'database',
    'table' => 'remember_tokens',
    'ttl' => 2592000, // 30 days in seconds; never an indefinite credential.
    'cookie' => [
        'name' => 'squehub_remember', // The guard name is appended.
        'path' => null,
        'domain' => null,
        'secure' => true, // Recommended for an HTTPS deployment.
        'http_only' => true,
        'same_site' => 'Lax',
    ],
],
```

```php
use App\Plugins\Auth;

$remember = $request->input('remember') === '1';
if (Auth::attempt(['email' => $email, 'password' => $password], remember: $remember)) {
    // A full authenticated Session was established.
}
```

`Auth::login($trustedIdentity, remember: true)` is available when application code has already established the identity. Omitting the `remember` argument keeps ordinary Session Auth behavior. Calling either method with `remember: true` while the feature is disabled raises a configuration error after the primary credential is verified, before Session login. Issuance requires an HTTP request because the credential is delivered only in a typed response `Cookie`; no raw remember token is returned to application code.

Each guard receives its own cookie name, such as `squehub_remember_web`, and a token issued for one guard cannot authenticate another. A cookie contains an opaque selector and a 256-bit validator. Only the validator's SHA-256 digest is stored. The server enforces expiry independently of the cookie expiry. A valid cookie with no authenticated Session is checked against the configured identity provider, credential fingerprint, and expiry; the guard rotates the Session ID and atomically replaces the selector and validator before attaching a new cookie. The old cookie stops working. A known selector with a wrong validator revokes that browser's selector as a possible replay. Malformed, unknown, expired, deleted, soft-deleted, and stale-credential identities do not regain a Session. The built-in model provider does not inspect an application-specific `disabled` attribute; a disabled state must affect provider resolution, for example through model soft deletion. A bare `disabled` property does not block Session or remembered login.

The default cookie Path uses the selected URL mount when it is nonempty; at the root it uses `session.path`. An explicit `remember.cookie.path` always wins, including `/` on a mounted application. The default Domain is host-only. A null `remember.cookie.secure` setting uses the Session secure policy or the request's effective trusted HTTPS scheme; set it explicitly for a deployment policy. The browser credential is `HttpOnly` and `SameSite=Lax` by default. Cookie deletion uses the same name, Path, and Domain. Avoid widening Path or Domain to unrelated applications on a shared host.

`Auth::logout()` invalidates the whole Session and revokes the presented remember credentials for every configured named Session guard in this browser, then expires their cookies. `Auth::forgetRemembered('web')` only forgets this browser's credential for that guard. `Auth::revokeRemembered($identity, 'web')` explicitly revokes all remembered browsers for that guard and identity. Password change, password reset, and a stored password-hash rotation invalidate old remember records through the same one-way credential fingerprint used by Session Auth; stale records are removed when presented. Array storage is available for deterministic tests and transient development use. Database storage is the persistent shared-hosting choice and does not require Redis. The migration is never run automatically.

## Named API token guards

Configure a `token` guard beside the existing `session` guard when an application needs personal access tokens. `auth()->tokens('api')` issues, lists, revokes, rotates, and prunes credentials for that named guard. The raw credential is returned only in the issuance result; a database repository stores a one-way digest and an opaque lookup identifier. No route for issuing tokens is installed automatically, and the application must run the token-table migration before using database storage.

On a protected route, `RequireToken::guard('api')` authenticates a strict Bearer header and selects the request identity. Downstream `auth()->user()`, `auth()->id()`, and Gate checks use that selected identity. `auth()->token()` provides safe metadata for the active token guard, and `auth()->tokenAllows($ability)` checks its narrower token ability. A session cookie on the same request does not become a fallback identity on this route. The next Kernel request clears the selected guard and cached identity. See [API tokens](ApiTokens.md) for configuration, the migration, the route middleware, expiry, revocation, and CSRF/CORS guidance.

## Browser frontend integration

A same-origin React, Vue, or native-module frontend can use the ordinary Session guard: render its shell through a backend View, read `csrf_token()` from a per-session meta tag, and submit it in `X-CSRF-Token` on unsafe Fetch requests. Login, Session ID regeneration, protected routes, logout, and CSRF enforcement remain server-side. The selected [SPA fallback](SpaRouting.md) changes only unmatched browser navigation; it does not authenticate requests, create a new guard, or exempt `/api/*` from CSRF. See [Frontend Session Auth](FrontendAuth.md) for a complete route and fetch example.

For a separate frontend or mobile client, a route can instead require a named Bearer token guard. An explicit CSRF exclusion belongs only on a dedicated Bearer-only unsafe path and does not make Session Auth acceptable there. CORS remains a separate opt-in origin policy. Keep credentials and access tokens out of generated JavaScript and localStorage; the server's Auth configuration and token repository remain authoritative.

## External OIDC sign-in

Configure named providers in `Config/OAuth.php` only for applications that need browser sign-in through an external OIDC provider. `OAuth::provider('company')->redirect()` starts the code and PKCE flow; `->callback($request)` returns a verified `ExternalIdentity`. The callback does not set the current Auth guard. Application code looks up a deliberate `(issuer, subject)` link, applies its own enrollment or linking policy, and calls `Auth::login($user)` on the local persisted model. A matching email claim alone must not create or attach a local account. No external access or refresh token is stored or turned into a SqueHub personal access token. See [OIDC login](OAuth.md) for configuration, route examples, strict issuer checks, and deployment boundaries.

Public login routes should select an application-specific [rate limit](RateLimiting.md). Register a named policy in a provider, then attach it after `guest` when guest rejection should consume no permit:

```php
use App\Plugins\RateLimitRequests;

Route::path('/login')->post([LoginController::class, 'store'])
    ->through(['guest', RateLimitRequests::named('login')]);
```

Auth's `attempt()` does not consume rate limits automatically. Choose the key and policy for your deployment; no universal or trusted-proxy IP key is assumed.

`Diagnostics::snapshot()['auth']` has request-scoped `attempts`, `successes`, `failures`, `logins`, `logouts`, and `errors` counters. It retains no IDs, names, credentials, guard names, session IDs, or verification timings. Auth does not emit automatic log records or events and does not use the application Cache, Storage, or notification helpers.

Session authentication ends with the session lifecycle unless the application explicitly issues an optional remembered-browser credential. Personal access tokens have their own expiration and revocation lifecycle; [Account security](AccountSecurity.md) supplies separate one-time password-reset tokens and optional email verification. Explicit [rate limiting](RateLimiting.md) is available as a separate pillar; optional [roles and permissions](RBAC.md) compose with Authorization. Optional [MFA](MFA.md) adds a second stage for enrolled session identities. The OIDC client is limited to external browser sign-in and does not add an OAuth authorization server or a JWT API guard. Explicit policies are available through the separate [authorization foundation](Authorization.md). `attempt()` alone is not brute-force protection.

Applications can use [Mail](Mail.md) to deliver account messages after trusted application code issues a token. Auth does not send mail or choose a rate-limit policy automatically.

Authentication answers **who the current identity is**. [Authorization](Authorization.md) answers **what that identity may do** through explicit abilities, policies, and optional [RBAC](RBAC.md) grants. Passwords are hashed with PHP's native password APIs, never encrypted. The foundation does not include registration or passkeys; optional [TOTP and recovery-code MFA](MFA.md) is available. OIDC sign-in is a separate opt-in client.

For an explicitly documented API route, an [Application Contract](ApplicationContract.md) can describe `pat()` or `session()` as its external authentication requirement. This is documentation metadata: the route still needs the matching Auth middleware, and a declared token ability does not grant or enforce that ability. OIDC browser sign-in can establish a local session, but its provider configuration is not automatically exported as API security.

## Application-facing Plugins import

Application code may import `App\Plugins\Auth`, `App\Plugins\Authenticatable`, `App\Plugins\Model`, `App\Plugins\OAuth`, `App\Plugins\ExternalIdentity`, `App\Plugins\RateLimitRequests`, `App\Plugins\RequireToken`, and `App\Plugins\RequireTokenAbility`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
