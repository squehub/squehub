# SqueHub v2 signed URLs

Signed URLs give a **named route** a temporary, purpose-bound bearer link. `App\Plugins\SignedUrl` generates an application-relative public URL and verifies a Request that has already matched the route. Generation and verification use the current Application's routing and [Crypt](Cryptography.md) services. There is no global signed URL helper.

## Generate and require a link

Define a modern named route, then attach the middleware with the same server-chosen purpose used at generation:

```php
use App\Plugins\{Route, SignedUrl};
use Project\Controllers\ReportController;

Route::path('/reports/{report}/download')
    ->get([ReportController::class, 'download'])
    ->named('reports.download')
    ->through([SignedUrl::middleware('report.download')]);

$url = SignedUrl::temporary(
    routeName: 'reports.download',
    routeParams: ['report' => 42],
    query: ['format' => 'pdf'],
    expiresAt: time() + 15 * 60,
    purpose: 'report.download',
);
```

`temporary(string $routeName, array $routeParams, array $query, int $expiresAt, string $purpose, string $method = 'GET'): string` returns a **relative public path**, such as `/reports/42/download?format=pdf&sqh_expires=...&sqh_signature=...`. Under `APP_BASE_PATH=/app`, it starts with `/app/reports/42/download`; it does not infer a scheme, origin, or tenant host. Route parameters use the normal named-route encoding and constraints. The expiry is a Unix timestamp in seconds, strictly in the future and no more than 366 days ahead when generated. Verification applies the same future and 366-day ceiling at request time. Use an application-chosen lifetime appropriate to the action.

The route must be a currently registered, modern, named route that allows the requested method. Legacy and fallback routes cannot be signed. A route with a static `host()` condition is supported: the declared host is bound to the signature and must match the incoming request. Generation rejects a dynamic, parameterized host such as `{tenant}.example.com`, because a relative URL cannot choose its host. Select an explicit application route and origin for tenant-specific delivery instead. Named URL generation remains relative even for static-host routes; the application is responsible for delivering the link on the intended host.

The default `GET` signature accepts a `GET` request or a `HEAD` request dispatched to that named GET route. An explicit HEAD route takes precedence in routing and needs its own named HEAD signature. For a mutation, declare its method explicitly on both generation and middleware. Supported methods are `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, and `DELETE`:

```php
Route::path('/invitations/{invitation}/accept')
    ->post([InvitationController::class, 'accept'])
    ->named('invitations.accept')
    ->through([SignedUrl::middleware('invitation.accept', 'POST')]);

$url = SignedUrl::temporary(
    'invitations.accept',
    ['invitation' => $invitationId],
    [],
    time() + 600,
    'invitation.accept',
    'POST',
);
```

The requested mutation must use that transport method; method override does not turn a different transport method into a valid signature. Global [CSRF](Csrf.md) protection and any other global middleware still run before the route's signed URL middleware. A signed URL does not create a CSRF exemption.

When a controller needs to check a link itself, call `SignedUrl::valid(Request $request, string $purpose, string $method = 'GET'): bool` after routing has matched the named route. `valid()` returns `false` for an invalid submitted link; invalid purpose or unsupported method arguments are developer errors. The canonical `App\Security\SignedUrl\RequireSignedUrl` middleware is also available; `SignedUrl::middleware($purpose, $method)` creates it for normal route `through()` declarations. Middleware rejection returns the same generic HTTP 403 for an absent, expired, malformed, or incorrect link. API error responses use the `invalid_signed_url` code. The response does not disclose which check failed.

## Query and signature rules

Only `sqh_expires` and `sqh_signature` are reserved query names. Do not supply them in the `query` argument. The purpose and method are **server-side** inputs bound into the MAC; they are not caller-selectable query fields. The signature also binds the route name, its static host declaration when present, the application path, the canonical query values, and the expiry. Changing a path parameter, adding or changing a query value, using another purpose or method, or switching the static host makes verification fail.

Choose a stable purpose for each action. It must be 1–80 ASCII bytes, start with a letter or digit, and otherwise contain only letters, digits, `.`, `_`, `:`, or `-`. A different purpose cannot validate the same link even when the route and query are identical.

Application query keys use ASCII `[A-Za-z_][A-Za-z0-9_-]{0,63}`. Values must be strings or integers; valid UTF-8 Unicode string values are supported. Arrays, nested values, duplicate keys, controls, and invalid UTF-8 are rejected. Generation sorts keys and encodes values with RFC 3986 percent encoding (`rawurlencode`), so spaces appear as `%20`, not `+`. Verification accepts only that canonical encoding and compares the decoded fields with the Request's parsed query snapshot. This prevents a PHP key alias, array syntax, or another parser interpretation from giving the controller different values than the signer authenticated. Query order does not carry meaning; the verifier rebuilds the canonical query before checking the MAC.

A link can contain at most 64 application query fields. Each decoded value is limited to 4,096 bytes, and the encoded query is bounded to 16 KiB including the signature fields. Use the route path or server-side state for larger data. Treat the generated URL as a bearer capability: avoid putting it in logs, analytics, or places where an unintended recipient can read or forward it. HTTPS and an appropriate referrer policy remain deployment and application concerns.

## Keys and security boundary

The signer uses `CryptManager::sign()` and `verify()` with an internal signed URL purpose, and its own length-framed MAC input. Configure `APP_KEY` and the Crypt key ring as described in [Cryptography](Cryptography.md#keys-and-rotation). New links use the current key ID. During a key rotation, keep the prior key under its original ID in the configured previous-key ring until links issued under it expire; removing that key invalidates those links. The `shs1.` value in `sqh_signature` is a MAC envelope, not the key itself.

A valid signature proves that the URL fields were issued by an Application with the signing key and have not expired. It does **not** authenticate the current user, authorize access to the target resource, make a mutation safe to repeat, or make the link single-use. Keep Auth, authorization, resource state checks, rate limits, and a separate atomic redemption or idempotency record where the action requires them. Existing account recovery, verification, personal access, and other tokens keep their own issuance and verification rules; signed URLs do not replace or migrate those credentials.
