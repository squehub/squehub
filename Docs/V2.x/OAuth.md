# OpenID Connect login client

[Generated API clients](SdkGeneration.md) consume an application's declared local Session or personal access token requirements after authentication. They do not implement OIDC discovery, authorization-code redirects, PKCE, state, nonce, or external provider login; this subsystem remains the owner of those flows.

The [Application Contract](ApplicationContract.md) does not infer public API Bearer security from an OIDC login provider. An external OIDC login flow and SqueHub's own PAT guard have different roles; applications document any deliberately public callback route explicitly, without publishing provider secrets or tokens.

The wider [API verification gate](ApiVerification.md) retains the deterministic Phase 12E fake-provider and loopback tests. Contract verification does not initiate live login with a public provider, and a passing local test is not a provider interoperability qualification.

Phase 12E provides an opt-in **OpenID Connect (OIDC) client** for browser sign-in with a configured external identity provider. Application code starts an authorization request with `App\Plugins\OAuth::provider('company')->redirect()` and receives a verified `App\Plugins\ExternalIdentity` from `->callback($request)`. The client does not create an application user, link accounts, or sign a user in. After an application-owned mapping decision, call the existing `Auth::login($user)` to establish a SqueHub session.

This is a client for the authorization code flow, not an OAuth authorization server or a general-purpose provider API client. It does not issue SqueHub personal access tokens, persist provider access or refresh tokens, call UserInfo, or grant application permissions. Ordinary SqueHub applications do not need a provider configuration or a live identity service to boot. The [feature status](FeatureStatus.md) describes the working-tree and verification boundary; no live provider has been qualified by the local tests.

## Configure a provider

Register the application with an OIDC provider. Its registered redirect URI must match the value in `Config/OAuth.php` exactly; the configured URI cannot contain a query or fragment. Use a dedicated callback URL on your application and an issuer URL supplied by the provider. The callback verifies the request method and configured path; enforce the public scheme and host through your web-server and proxy configuration. For example:

```php
<?php

declare(strict_types=1);

/** @var \App\Foundation\Environment $environment */
return [
    'transaction_ttl' => 600,
    'max_outstanding' => 8,
    'providers' => [
        'company' => [
            'issuer' => 'https://id.example.com',
            'client_id' => $environment->get('OIDC_COMPANY_CLIENT_ID'),
            'client_secret' => $environment->get('OIDC_COMPANY_CLIENT_SECRET'),
            'redirect_uri' => 'https://app.example.com/auth/company/callback',
            'scopes' => ['openid', 'profile', 'email'],
            'trusted_hosts' => ['id.example.com'],
            'allow_local_http' => false,
            'clock_skew' => 60,
            'signing_algorithm' => 'RS256',
        ],
    ],
];
```

`App/Config/Loader.php` loads this file under the lowercased `oauth` configuration key. `providers` is empty by default. Each name selects application-owned configuration; a request cannot supply an arbitrary issuer or discovery URL. `transaction_ttl` defaults to 600 seconds and accepts 60–900; `max_outstanding` defaults to eight pending attempts per browser session and accepts 1–16. Older attempts are evicted at capacity. `client_secret` may be `null` for a provider registration that supports an unauthenticated token endpoint; with a secret, the client uses `client_secret_basic`. Keep credentials in protected environment configuration, not source control. The scopes must contain `openid`; omit `profile` and `email` if the application does not need them. The initial client supports only `RS256` ID-token signatures and the `code` response type. Select a provider that supports OIDC discovery, PKCE `S256`, and the authorization-response `iss` parameter from [RFC 9207](https://www.rfc-editor.org/rfc/rfc9207.html). The callback rejects a missing or mismatched `iss` **before** any code is sent to the token endpoint. An OIDC provider that omits this response parameter is incompatible even if its ID tokens contain an issuer claim.

The issuer and discovered authorization, token, and JWKS endpoints use HTTPS. `trusted_hosts` defaults to the issuer host; explicitly list any additional authorization, token, or JWKS host used by the provider. It is an allowlist for outbound discovery, token, and signing-key URLs. Do not place user-controlled or wildcard hosts there. The callback host belongs to your application and need not be on this outbound allowlist; deploy routing and host validation at the web-server boundary. `allow_local_http => true` is only for an intentional localhost or loopback development setup. `clock_skew` is an integer from 0 to 300 seconds; the default is 60. Set production session cookies to HTTPS-only with `SESSION_SECURE=true` and use the configured `SESSION_SAME_SITE=Lax` for a top-level GET callback. A `Strict` cookie may be absent on the cross-site return; an `array` session cannot carry the transaction across independent requests. See [Sessions](Sessions.md).

The host allowlist, HTTPS checks, endpoint size limits, and disabled outbound redirects narrow server-side request forgery exposure. Hostname validation by itself is **not** a DNS-rebinding or infrastructure-level network guarantee. Restrict egress and DNS according to your deployment, and protect outbound proxy settings. Review a provider's discovery endpoints before trusting them.

## Add start and callback routes

These routes show the framework handoff. `Project\Services\ExternalIdentityLinks` represents application code that you must implement; its lookup uses a unique `(issuer, subject)` pair and returns a persisted local `Authenticatable` user only after your linking policy is satisfied.

```php
use App\Plugins\{Auth, OAuth, RedirectResponse, Request, Route};
use Project\Services\ExternalIdentityLinks;

Route::path('/sign-in/company')->get(
    static fn () => OAuth::provider('company')->redirect()
);

Route::path('/auth/company/callback')->get(
    static function (Request $request) {
        $external = OAuth::provider('company')->callback($request);
        $user = (new ExternalIdentityLinks())->find($external->issuer(), $external->subject());

        if ($user === null) {
            // Your application decides whether to reject, invite, or explicitly link.
            return new RedirectResponse('/sign-in/help', 303);
        }

        Auth::login($user);
        return new RedirectResponse('/dashboard', 303);
    }
);
```

The `oauth()->provider('company')` helper reaches the same Application-owned manager as `OAuth::provider('company')`. `redirect()` returns a response that sends the browser to the configured provider. `callback(Request)` consumes the one-time browser transaction and returns `ExternalIdentity` with `provider()`, `issuer()`, `subject()`, `claims()`, and convenience accessors such as `email()`, `emailVerified()`, `name()`, `preferredUsername()`, and `picture()`. Verified means the returned claims came from an ID token whose signature and required protocol claims passed validation; it does **not** make every profile attribute a current authorization fact. Provider `access_denied` becomes `App\OAuth\OAuthCancelledException`. The existing exception handler renders that cancellation as a safe HTTP 400 response (or `oauth_cancelled` in an API error scope), without echoing provider text. Other protocol failures use the ordinary production-safe error boundary. Do not display a raw callback query or provider token response to users.

`redirect()` also accepts an optional array containing only `prompt` and `login_hint`, such as `redirect(['prompt' => 'login'])`. Treat a hint as display input for the provider, not a local account identity. Security parameters such as issuer, redirect URI, state, nonce, scope, response type, and PKCE values cannot be overridden by this array.

The callback is a top-level `GET` navigation with query parameters; the initial client does not support a `form_post` response mode. Keep it available without an `auth` middleware requirement, since it precedes local sign-in. Global [CSRF](Csrf.md) guards unsafe methods; it does not need an exception for this GET callback. The one-time `state` and OIDC `nonce` checks protect this redirect flow. Never change application account data or create local authentication state before `callback()` succeeds. Do not put an unrestricted return URL in `state` or redirect to an arbitrary callback query parameter; choose an application-controlled post-login path.

## Link an external identity deliberately

Store a link between the provider's exact issuer and subject and one local identity, with an appropriate uniqueness constraint. Require an authenticated, authorized account-linking flow or another explicit enrollment policy before creating that link. Check the currently signed-in account when linking; the callback does not decide which local account should own a new external subject. A verified `email` claim is useful profile data, but even `email_verified: true` is not permission to attach the identity to an existing account by matching email. Email addresses can change or be reassigned and providers differ in their verification rules. Review profile claims before displaying them and escape untrusted strings in HTML.

`Auth::login()` rotates the SqueHub session ID and stores the local model identity through the existing configured session guard. Application [Authorization](Authorization.md) still decides permissions after login. An OIDC ID token is not accepted as a SqueHub Bearer credential; [personal access tokens](ApiTokens.md) retain their separate issuance, ability, expiration, and revocation lifecycle. A provider sign-out, local logout, local password reset, and PAT revocation are separate operations unless your application coordinates them.

## Protocol and security boundaries

The client generates a fresh authorization `state`, PKCE verifier/challenge using `S256`, and OIDC `nonce` for each attempt. The short-lived transaction is bound to the browser session, provider name, and one-time state. Session metadata holds a SHA-256 state digest, verifier, and nonce outside ordinary session data and flash values. Multiple tabs can have separate pending attempts; stale or replayed state fails. The provider's authorization response must carry an `iss` value equal to the configured issuer, including on provider error responses. The client exchanges a valid code at the discovered token endpoint using the registered redirect URI and original PKCE verifier, then verifies an `RS256` ID token against the provider's JWKS. It checks issuer, subject, audience and authorized party where applicable, nonce, and token timing with the configured skew. It returns only the verified identity; provider access and refresh tokens are not stored or exposed through `ExternalIdentity`.

Provider metadata must identify the configured issuer and compatible code/signature capabilities. Discovered endpoints must pass the trusted-host policy. There is no automatic provider registration, arbitrary issuer selection from request input, implicit or password grant, unsigned ID token, or configurable weaker signature algorithm. `firebase/php-jwt` is the one direct production dependency added for JWT verification; the development lockfile resolves BSD-3-Clause `v7.2.0` with no new transitive runtime packages. The 2026-09-26 `composer audit` found no advisories at that checkpoint. Applications using OIDC need this Composer dependency and PHP OpenSSL for `RS256` verification. No provider contact is required for ordinary application boot.

Keep the authorization code and callback query out of logs, analytics, referrer leakage, exception text, and long-lived browser history where possible. The shared secret redactor masks configured provider `client_secret` values in framework log and debug text, but it is a defensive safeguard, not permission to log credentials or callback parameters. Protect session storage and HTTPS termination, apply an application-specific [rate limit](RateLimiting.md) to sign-in entry points if needed, and review provider availability and key rotation in the target environment. The local tests use simulated provider responses; they do not prove interoperability, DNS behavior, TLS setup, proxy rules, or a provider's production policy. [CORS](Cors.md) governs browser JavaScript access to cross-origin API responses; it does not authenticate the callback or replace state, nonce, session-cookie, or CSRF protections.

`Tests/Integration/OidcLoopbackHttpTest.php` is an optional local transport check. With `SQUEHUB_TEST_OIDC_LOOPBACK=1`, it starts a test-only provider on `127.0.0.1` and exercises real cURL discovery, code exchange, and JWKS requests, including redirect and oversized-response rejection. It needs cURL, OpenSSL, and a usable loopback socket. Without the opt-in variable, its four tests skip. This is a local fixture, not qualification against a public or enterprise provider.

The design follows [OpenID Connect Core](https://openid.net/specs/openid-connect-core-1_0.html), [OpenID Connect Discovery](https://openid.net/specs/openid-connect-discovery-1_0.html), [PKCE (RFC 7636)](https://www.rfc-editor.org/rfc/rfc7636.html), [authorization-response issuer identification (RFC 9207)](https://www.rfc-editor.org/rfc/rfc9207.html), and the [OAuth security best current practice (RFC 9700)](https://www.rfc-editor.org/rfc/rfc9700.html). The phrase **OAuth 2.1** in the roadmap refers to the direction of the [OAuth 2.1 Internet-Draft](https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/), which was still a draft on 2026-09-26; this implementation is the narrower OIDC client described above.
