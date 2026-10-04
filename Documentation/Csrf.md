# SqueHub v2 CSRF protection

SqueHub uses a session-bound synchronizer token for browser requests. Normal web bootstrap registers CSRF globally. An issued token is 32 cryptographically random bytes encoded as 64 lowercase hexadecimal characters; it remains stable within the session. Multiple forms and parallel requests may submit the same token. It changes only when `CsrfTokenManager::rotate()` is called or the session is invalidated. Ordinary `session()->regenerate()` changes the session ID and keeps the token.

## Sending a token

Issue one through `csrf_token()`. In `.squehub.php` views, `@csrf` emits the preferred hidden field with the configured name. Plain PHP can call `csrf_field()` to obtain the same trusted HTML. Both resolve the current session token at render time. A browser form targeting a PUT, PATCH, or DELETE route may add `@method('PATCH')` alongside `@csrf`; it still transports POST, and the effective method remains subject to this same global CSRF check. See [Forms and Validation UX](Forms.md). A manual form can also use:

```php
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
```

An unsafe JSON or AJAX request using the browser session can send `X-CSRF-Token`. This header takes precedence over the configured body field when present, even if its value is invalid. The genuine legacy `_token` body field is accepted when the configured field is absent. Query-string tokens are never accepted because URLs can enter browser history, logs, analytics, and referrer headers. The Request body is not modified; controllers should validate named fields instead of mass-assigning `$request->all()`.

```javascript
fetch('/profile', {
    method: 'PATCH',
    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': token},
    body: JSON.stringify(payload),
});
```

For a Session-authenticated browser frontend, render `<meta name="csrf-token" content="{{ csrf_token() }}">` in a backend `.squehub.php` shell and send that value with same-origin Fetch. Return the shell dynamically with `Cache-Control: private, no-store`; do not embed a visitor's token in a static Vite build or shared-cacheable HTML. A generated frontend profile's fetch helper follows this existing header policy. Login may regenerate the Session ID without changing the token; logout invalidates it, so request a fresh shell before another unsafe action. See [Frontend Session Auth](FrontendAuth.md).

## Protection and failures

`POST`, `PUT`, `PATCH`, and `DELETE` require a valid token. `GET`, `HEAD`, and `OPTIONS` do not; applications must keep state changes out of safe methods. Other unsupported/unsafe methods are guarded before dispatch. CSRF runs in the Kernel's global middleware stage before route matching, route middleware, controllers, and validation. Consequently, an unknown unsafe route without a token returns 403 before routing can produce 404/405. A valid token allows normal routing semantics. This ordering avoids controller execution while checking CSRF and keeps the current route dispatcher intact.

Optional `Request::validatedAs()` uses the existing Validator **after** this same CSRF boundary. A bad token cannot construct a typed value, execute its constructor, or invoke the controller; a valid token does not itself validate application fields. Existing flash errors and filtered old input work the same for typed and array-based browser forms. See [Typed application data](ApplicationData.md#browser-form-csrf-errors-and-old-input).

A missing or invalid token returns HTTP 403. Within an enabled [API response scope](ApiResponses.md), it uses the safe `forbidden` envelope and a trusted request ID. Outside that scope, JSON receives `{"message":"CSRF verification failed."}` and HTML receives a generic Forbidden page. Debug mode does not reveal token values. Failure does not invalidate the session or flash request input. A valid browser form may receive a validation 303 redirect with errors and old input; an API request keeps its JSON 422 contract.

Unsafe form, multipart, and JSON requests are all protected. `/api/*` has no automatic exemption. SameSite cookies complement the token check; neither CORS nor Origin/Referer checking replaces it. CSRF exclusions are not webhook authentication: excluded webhook routes still need provider signatures or equivalent verification. A dedicated [Bearer-token](ApiTokens.md) endpoint can use an explicit path exclusion only when its route requires the named token guard and does not accept Session fallback. An exclusion applies to the path before route authentication, so do not share it with a cookie-authenticated endpoint.

A valid configured [CORS preflight](Cors.md) is an `OPTIONS` policy check that can finish before global middleware and controllers. The subsequent unsafe request is separate and still needs its session-bound CSRF token. An allowed Origin alone does not make a POST, PUT, PATCH, or DELETE acceptable.

## Configuration

`Config/Csrf.php` defaults to `enabled => true`, body field `_csrf`, header `X-CSRF-Token`, and no exclusions. `enabled => false` is available for controlled test setups but removes browser CSRF protection.

`except` accepts exact paths such as `/webhooks/provider` and trailing segment prefixes such as `/hooks/*`. The latter matches `/hooks/item` and deeper descendants, but not `/hooks` or `/hooksmith/admin`. Query strings do not affect matching. Encoded, traversal, backslash, and duplicate-separator paths do not match exclusions. Invalid patterns fail during Application boot instead of disabling protection. SqueHub does not provide a route-level exclusion API.

## Session and legacy behavior

The token lives in SessionStore's reserved `_squehub` metadata and is omitted from `session()->all()`, old input, and flash data. A valid v1 `_token` value is adopted once into that internal location and the old public key is removed. `csrf_token()`, the legacy `@csrf` directive, `CsrfTokenValidator()`, and `validateCsrfToken()` all use the same token manager. `@csrf` resolves the token at render time so a compiled template cannot capture a different visitor's token. The manual legacy validator still applies to POST; on failure `validateCsrfToken()` now raises the same safe 403 exception instead of terminating with an inline message.

The [form guide](Forms.md) covers validation redirects, error and old-input flashing, and safe redirect-back behavior. Authentication, authorization, and [rate limiting](RateLimiting.md) are separate Application pillars. Opt-in [CORS](Cors.md) can permit a browser origin to read an API response; it does not replace the session token check. [Webhook verification](Webhooks.md) can authenticate a SqueHub-profile server delivery on an explicitly excluded path; it does not disable CSRF elsewhere. Trusted proxies and Origin/Referer CSRF verification remain later work.

## Application-facing Plugins import

Application code may import `App\Plugins\Csrf`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
