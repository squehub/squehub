# Browser security policy

Browser headers are configured under `browser` in `Config/Security.php`. The
policy is **disabled by default** so existing HTTP deployments and Views with
inline content keep working. Enable it after checking the public HTTPS origin,
reverse proxy trust, and the rendered pages in the target application. An
invalid policy fails Application boot; changing configuration in a long-lived
Application is validated again on the next request.

```php
'browser' => [
    'enabled' => true,
    'csp' => ['directives' => [
        'default-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'frame-ancestors' => ["'none'"],
    ]],
    'hsts' => [
        'enabled' => true,
        'max_age' => 31536000,
        'include_subdomains' => false,
        'preload' => false,
    ],
    'referrer_policy' => 'strict-origin-when-cross-origin',
    'frame_options' => 'DENY',
    'nosniff' => true,
],
```

This is a **strict example**, not a drop-in default. `Project/Views/Home/Welcome*.squehub.php`
and default error Views contain inline `<style>`, and application Views may
contain inline `<script>`. The example CSP blocks those inline elements. Move
them to external assets before using it, or configure the relevant `style-src`
and `script-src` sources for the application. Allowing `'unsafe-inline'` keeps
inline content working but weakens CSP's protection against injected content.
The View asset renderer has an internal deduplication token, not a CSP nonce
shared with templates and headers. Request-scoped CSP nonce support is deferred;
do not put a static `'nonce-...'` source in configuration.

## Settings and response scope

| Setting | Accepted values and effect |
| --- | --- |
| `enabled` | Boolean; false emits no additional browser-policy headers. |
| `csp.directives` | Map of lowercase directive names to lists of source tokens. At most 32 directives and 32 sources each; one token per source. An empty map disables CSP. CR/LF, whitespace within a token, and semicolons are rejected. The rendered header is limited to 8 KiB. |
| `hsts.enabled` | Boolean. When true, `Strict-Transport-Security` is emitted only for effective HTTPS. |
| `hsts.max_age` | Integer from 0 to 63,072,000 seconds. `0` requests removal of an existing HSTS policy on an HTTPS response. |
| `hsts.include_subdomains` | Boolean. Includes `includeSubDomains` when true. |
| `hsts.preload` | Boolean. Requires HSTS enabled, `include_subdomains=true`, and at least 31,536,000 seconds. This flag alone does not submit a domain to a preload list. |
| `referrer_policy` | One of `no-referrer`, `no-referrer-when-downgrade`, `origin`, `origin-when-cross-origin`, `same-origin`, `strict-origin`, `strict-origin-when-cross-origin`, or `unsafe-url`; `null` disables the global header. A response's explicit `no-referrer` is preserved, including the OIDC authorization redirect. |
| `frame_options` | `DENY`, `SAMEORIGIN`, or `null`. It applies to HTML responses. If `frame-ancestors` is also set, `DENY` requires exactly `'none'`, and `SAMEORIGIN` requires exactly `'self'`. |
| `nosniff` | Boolean. When true, emits `X-Content-Type-Options: nosniff` on all Kernel responses. |

The policy adds CSP and X-Frame-Options to HTML and XHTML responses. A browser
route's ordinary string response has no explicit Content-Type, so it is treated
as HTML unless it is an API response or a deferred body. CSP and X-Frame-Options
are omitted from redirects, attachment downloads, binary files, and non-HTML
streams. Referrer-Policy and `nosniff` can apply to these responses. HTML 404
and 500 pages pass through the same Kernel boundary. JSON API responses do not
receive CSP or frame restrictions. HSTS applies to all response types on
effective HTTPS, including redirects and errors.

Configured CSP and frame settings replace headers supplied by an application
response on HTML. If `frame-ancestors` is configured without X-Frame-Options,
an existing X-Frame-Options response header is removed to avoid contradictory
frame instructions. Review endpoint-specific policies when enabling a global
policy. The OIDC `no-referrer` response policy is deliberately retained because
the generic referrer setting must not weaken that redirect.

HSTS is a browser policy for the whole host. `includeSubDomains` and preload
can affect sibling applications and subdomains outside SqueHub, possibly long
after a setting is reverted. Start with a reviewed HTTPS-only deployment and a
short max age before lengthening it. SqueHub uses the request's direct HTTPS
state or the effective scheme from a configured trusted proxy. An untrusted
`X-Forwarded-Proto: https` cannot enable HSTS. Configure the proxy to replace
client forwarding headers, restrict direct bypass, and set allowed public hosts;
see [Deployment](Deployment.md#reverse-proxies-and-allowed-hosts).

## Cookies and operational responses

Browser policy does not rewrite `Cookie` objects. In production, configure
`SESSION_SECURE=true`, `SESSION_HTTP_ONLY=true`, an appropriate
`SESSION_SAME_SITE`, and the narrowest `SESSION_PATH` and `SESSION_DOMAIN` that
fit the deployment. A Session cookie's `Secure` setting is explicit; trusted
HTTPS does not silently change it. `SameSite=None` requires `Secure`. For an
application mounted at `/app`, choose `SESSION_PATH=/app` if only that mount
needs the Session. The default path remains `/`. A null Domain keeps the cookie
host-only. The optional remember cookie inherits the URL mount (`/app`) when
its `auth.remember.cookie.path` is null, uses HttpOnly and SameSite Lax by
default, and becomes Secure on effective trusted HTTPS unless explicitly
overridden. Cookie deletion uses the same Path and Domain. Explicit application
cookies keep their own flags and scopes; review them individually. See
[Security](Security.md) and
[subdirectory deployment](Deployment.md#subdirectory-mounts-and-shared-hosting).

Setup-required output occurs before Application configuration is available, and
a session-close failure after Kernel handling is emitted by the outer web
bootstrap. Those rare responses are outside this configured Kernel policy.
Health routes that pass through Kernel receive general applicable headers, but
HTML-specific headers are not added to their JSON or text probe output.
Permissions-Policy is deferred because SqueHub has no feature-specific default
to express. `nosniff` is included because it is a small, widely useful header
for responses that already declare a content type; it does not change the
response body, download metadata, or streaming producer.
