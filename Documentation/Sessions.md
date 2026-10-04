# SqueHub v2 sessions

The Application registers one `SessionManager`, which creates one `SessionStore`. Web bootstrap starts the store before legacy routes and templates load, because existing code reads `$_SESSION` during bootstrap. This is a centralized eager compatibility start. CLI Application bootstrap only registers the service; it does not open a browser session. `Bootstrap/Web.php` saves and closes an opened session before sending the Response, releasing PHP's native file lock where applicable.

## Configuration and drivers

`Config/Session.php` reads optional environment settings. No database connection is needed.

| Setting | Default | Meaning |
| --- | --- | --- |
| `SESSION_DRIVER` | `native` | `native` PHP storage; `redis` requires Redis; `auto` selects Redis when usable, otherwise native; `array` is isolated to one manager |
| `SESSION_REDIS_CONNECTION` | `main` | Named Redis connection when Redis is selected |
| `SESSION_REDIS_NAMESPACE` | Application filesystem root | Set the same application namespace on servers whose checkout paths differ; keep unrelated applications separate. This is unrelated to the HTTP URL mount. |
| `SESSION_NAME` | `squehub_session` | Cookie/session name; starts with a letter and contains letters, digits, `_`, or `-` |
| `SESSION_LIFETIME` | `120` | Minutes; `0` makes a browser-session cookie |
| `SESSION_PATH` | `/` | Cookie path |
| `SESSION_DOMAIN` | unset | Cookie domain |
| `SESSION_SECURE` | `false` | Restrict cookie to HTTPS when enabled; enable this on production HTTPS |
| `SESSION_HTTP_ONLY` | `true` | Hide cookie from browser scripts |
| `SESSION_SAME_SITE` | `Lax` | `Lax`, `Strict`, or `None`; `None` requires `SESSION_SECURE=true` |
| `SESSION_STRICT_MODE` | `true` | Reject uninitialized client-supplied IDs |

The native and Redis drivers use PHP's cookie and session lifecycle. Redis stores the same PHP session payload under a hashed session ID and renews native TTL on writes and unchanged-session touches. A `0` cookie lifetime uses a 1,440-second server lifetime. Invalid configuration or a failed start, ID change, or close raises `SessionException` with a safe message. Native storage adopts an already active PHP session for legacy compatibility; Redis storage rejects adopting an unrelated active native session. The array driver has no cross-process persistence. `auto` probes Redis on first Session store resolution, then keeps that choice; explicit `redis` never falls back. Redis session records contain sensitive data and require protected Redis access and backups. Redis uses a per-session atomic lease (five-second wait, 300-second lease) until close to serialize ordinary concurrent requests. A request exceeding the lease can overlap another request; there is no automatic merge of divergent session data.

`APP_BASE_PATH=/app` does not silently change the Session cookie path. The default `SESSION_PATH=/` still works for a mounted application but sends the cookie to other paths on the same host. Set `SESSION_PATH=/app` in that deployment when the cookie should be scoped to the application mount. Distinct applications on one host should also use distinct `SESSION_NAME` values and separate Session stores or Redis namespaces as appropriate; a narrow cookie Path alone does not make two server-side sessions independent. A typed `Cookie` attached to a Response keeps its explicitly chosen Path unchanged. See [subdirectory deployment](Deployment.md#subdirectory-mounts-and-shared-hosting).

## Session data and identity

```php
session()->put('theme', 'dark');
$theme = session()->get('theme', 'light');
if (session()->has('user_id')) {
    // A present null value also counts as present.
}
session()->forget('theme');
$value = session()->pull('one_time'); // Read and remove.
$data = session()->all();
```

These keys are **literal**: `session()->get('profile.theme')` reads a key with a dot in its name. `all()` omits SqueHub's reserved `_squehub` metadata and the old `_token` CSRF key while it is awaiting migration. Values must be serializable; Closure and resource values are rejected. Avoid storing framework service objects or credentials in session data.

`session()->id()` reads the current ID. `session()->regenerate()` changes it, removes the old native ID, and preserves data, flash, old input, and the CSRF token. `session()->invalidate()` clears all of them, including CSRF state, and creates a fresh ID. [Authentication](Authentication.md) uses these operations for login and whole-session logout; [account security](AccountSecurity.md) rotates again after a successful password change. Guard-specific identifiers and credential fingerprints live in reserved metadata and remain hidden from `all()`. CSRF metadata is hidden there too; see [CSRF protection](Csrf.md).

## Flash data

```php
session()->flash('status', 'Profile saved');
$message = session()->get('status');
```

Flash is readable immediately and throughout the next request. It expires as the following request begins. Reads do not consume it. Flashing a key replaces any ordinary value under that key. `put()`, `forget()`, and `pull()` clear its flash marker; flashing again renews it. Flash metadata is held only under the reserved `_squehub` root. Direct `$_SESSION` edits to a flashed key are legacy compatibility behavior and do not update its lifetime marker.

Views can present either a normal or flashed key with `@session('status') ... @endsession`. The block runs when the **literal key exists**, including when its value is `null`, `false`, `0`, `''`, or `[]`; it temporarily binds `$value`. An optional `@else` handles an absent key. No separate `@flash` directive was added, because flashed values use this same Session read contract. The directive does not consume or prolong flash data. See [Auth, Guards, Session and Authorization](ViewSecurity.md) for examples and the temporary binding rules.

## Old input

```php
session()->flashInput($request->all());
$email = old('email', '');
$nested = old('profile.email');
$all = session()->old();
```

Old input is available immediately and for the next request, then expires. Calling `flashInput()` again replaces the pending input. `old()` supports dot paths and preserves arrays, zero, false, and null. It does not alter the current Request. Filtering recursively excludes password, secret, token, authorization, API key, credential, private-key, and temporary-upload-path fields, regardless of case. `UploadedFile` instances and PHP upload arrays are omitted; unrelated fields survive. Unexpected objects and resources cause `SessionException` before storage changes.

## Legacy bridge and current boundaries

`App\Core\Notification` keeps `notification_<type>` keys; `App\Components\Notification` keeps its `_notification` map; the global `flash()` helper keeps `_flash`. They now use the same store and request-based flash aging while retaining their consume-on-read public methods. The native driver keeps these keys visible in `$_SESSION` for existing templates. Other objects already holding cached data are not synchronized automatically.

Browser form validation flashes errors under `_validation_errors` and filtered old input for the next request when a safe redirect destination exists. `$errors` in views and `errors()` in PHP expose the field-indexed bag without consuming it; `@error('field') ... @enderror` scopes its first message for display. The validation redirect excludes the CSRF field and `_method` framework metadata before calling `flashInput()`. The previous internal navigation path lives only in reserved `_squehub` metadata and is absent from `all()`. JSON validation remains 422 and CSRF failure remains 403 without flashing; see [Forms and Validation UX](Forms.md). Database session storage and distributed session locking are not implemented. `@csrf` resolves through the v2 token service at render time and uses the configured field name.

## Application-facing Plugins import

Application code may import `App\Plugins\Session`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
