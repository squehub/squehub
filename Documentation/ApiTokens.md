# API and personal access tokens

An explicitly declared [Application Contract](ApplicationContract.md) can describe a token-protected route with `->pat(['orders.read'])`. OpenAPI represents the credential as HTTP Bearer and records SqueHub token abilities as SqueHub metadata, never as OAuth scopes. This declaration does not install `RequireToken` or `RequireTokenAbility` middleware; the route must attach its actual enforcement separately. Real token values must never appear in contract examples.

The [API verification gate](ApiVerification.md) can identify clear drift between a PAT declaration and known token middleware, then exercise selected missing-token, invalid-token, and ability cases through the Kernel. It treats custom middleware as unknown rather than assuming the same enforcement. Verification reports never include token values.

Personal access tokens authenticate requests made by mobile clients, command-line tools, and other clients that deliberately send an `Authorization: Bearer` header. They use the existing SqueHub identity provider and Auth manager. A browser application can keep using session authentication; adding a token guard does not replace the session guard or create login and token-management endpoints.

A [generated client](SdkGeneration.md) can call an explicitly contracted PAT-protected route by receiving a token callback at runtime. It does not issue or store PATs, install `RequireToken` middleware, or treat a SqueHub PAT as an OAuth access token. Never place a raw token in a contract declaration, generated source, or its manifest.

Use a token when a client can store and send it deliberately. Use a session and CSRF protection for a conventional browser login. A token is a credential: possession can grant access until it expires or is revoked. Send it only over HTTPS and store it in a client secret store, never in a URL, analytics event, application log, or browser page source.

## Configure a named guard

Configure the same `users` identity source used by the session guard, then select a named token guard in `Config/Auth.php`:

```php
return [
    'default' => 'web',
    'guards' => [
        'web' => ['driver' => 'session', 'identity' => 'users'],
        'api' => [
            'driver' => 'token',
            'identity' => 'users',
            'repository' => 'database',
        ],
    ],
    'identities' => [
        'users' => [
            'driver' => 'model',
            'model' => \Project\Models\User::class,
            'identifier' => 'id',
            'password' => 'password',
            'credentials' => ['email'],
        ],
    ],
    'tokens' => [
        'driver' => 'database',
        'table' => 'api_tokens',
        'connection' => null,
        'default_ttl' => 2592000,
        'allow_non_expiring' => false,
        'max_ttl' => 31536000,
        'last_used_interval' => 300,
        'prune_retention' => 2592000,
    ],
    // Keep the existing password and browser settings as needed.
];
```

The `api` name is an application choice; it is not a required global guard name. The `database` repository persists hashed tokens across requests. The `array` repository is for isolated tests and one process lifetime. A token guard does not start Session or check passwords while authenticating a Bearer request. Its identity reference is resolved through the configured modern Model provider, so deleted or missing identities do not authenticate.

The source tree includes `Database/Migrations/2026_09_26_create_api_tokens.php`. Apply migrations deliberately with `php squehub migrate` before issuing a database-backed token. Application boot does not create or query the table. The default migration uses this portable Schema shape:

```php
use App\Plugins\Table;

$schema->create('api_tokens', static function (Table $table): void {
    $table->string('identifier', 16);
    $table->string('token_hash', 64);
    $table->string('guard', 64);
    $table->string('identity_kind', 1);
    $table->string('identity_identifier', 255);
    $table->string('name', 128);
    $table->text('abilities');
    $table->datetime('created_at');
    $table->datetime('expires_at')->nullable();
    $table->datetime('last_used_at')->nullable();
    $table->datetime('revoked_at')->nullable();
    $table->unique('identifier', 'api_tokens_identifier');
    $table->unique('token_hash', 'api_tokens_hash');
    $table->index(['guard', 'identity_kind', 'identity_identifier'], 'api_tokens_identity');
    $table->index('expires_at', 'api_tokens_expires');
    $table->index('revoked_at', 'api_tokens_revoked');
});
```

The `identity_kind` field distinguishes integer and string identity keys; token rows do not duplicate an identity's profile or password. Abilities are stored as bounded JSON, not native PHP serialization. If `auth.tokens.table` is changed from `api_tokens`, supply a matching application migration; the bundled migration uses the default name. See [Migrations](Migrations.md) for execution and rollback rules. Protect database access, backups, and any administrator view of token metadata; the table contains a one-way token hash and identity references even though it contains no raw token.

## Credential format and lookup

An issued token has the form `sqh_pat_<16-character identifier>_<43-character secret>`. The identifier is opaque, random, and non-secret; it selects an indexed candidate without scanning the table. The secret is generated from 32 cryptographically random bytes and is the Bearer credential. The database stores SHA-256 of that independent high-entropy secret in `token_hash`. Verification compares fixed-length digests with a timing-safe comparison, including a dummy comparison for an unknown identifier. This reduces obvious timing differences; it is not a promise of identical end-to-end request timing.

The identifier is safe to show as token metadata, but it is not an authorization key. The token never encodes the identity, expiry, or abilities. The database remains authoritative for those values, revocation, and current identity resolution. Do not attempt to reconstruct a token from its identifier or hash.

## Issue and manage tokens

Issuance is an explicit trusted application action. The framework does not register an unauthenticated token-creation route or decide which abilities a user may grant. Authenticate and authorize any application endpoint that issues tokens, bound names and lifetimes, and select abilities no broader than the user's actual permissions.

```php
$issued = auth()->tokens('api')->issue(
    identity: $user,
    name: 'Personal laptop',
    abilities: ['orders.read', 'orders.create'],
    expiresAt: new DateTimeImmutable('+30 days')
);

$rawToken = $issued->token(); // Show or hand off once, through a trusted channel.
$metadata = $issued->metadata();
```

`issue()` returns an `IssuedToken` containing the raw value once and a safe `TokenMetadata` view. The raw value cannot be recovered from storage later. Present it only to the trusted client in the issuance response and do not return it from listing or diagnostics. SqueHub stores only its cryptographic digest, not a reversible encrypted copy. Password hashing is designed for human-chosen low-entropy secrets; a token secret comes from secure random bytes.

Application code normally uses `auth()->tokens('api')`. A provider or typed service may import the exact `App\Plugins\TokenManager` alias; framework internals use the canonical `App\Auth\Tokens\TokenManager` type. `App\Plugins\IssuedToken` and `App\Plugins\TokenMetadata` are exact aliases for the public return types. Repository and hashing implementation classes are not Plugins APIs.

For example, an application can issue a narrow token from an authenticated browser session after an explicit `tokens.create` Authorization rule. The route stays under normal CSRF protection; the application selects the abilities instead of accepting an arbitrary client-supplied ability list:

```php
use App\Plugins\{JsonResponse, Request, RequireAbility, Route};

final class TokenController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:128']);
        $identity = auth()->user();
        if ($identity === null) {
            throw new \LogicException('A configured authentication guard is required.');
        }

        $issued = auth()->tokens('api')->issue(
            $identity,
            $data['name'],
            ['orders.read']
        );

        return new JsonResponse([
            'token' => $issued->token(),
            'identifier' => $issued->metadata()->identifier(),
        ], 201, ['Cache-Control' => 'no-store']);
    }
}

Route::path('/account/tokens')
    ->post([TokenController::class, 'store'])
    ->through(['auth', RequireAbility::named('tokens.create')]);
```

Register `tokens.create` in `Config/Authorization.php` or through `Gate` before using this example. An application may add stricter reauthentication or approval before issuance. The response intentionally contains the one raw credential and prevents HTTP caching; it must not be logged or replayed to another party.

```php
$tokens = auth()->tokens('api')->listFor($user); // Safe TokenMetadata entries.
$revoked = auth()->tokens('api')->revoke($metadata->identifier());
$count = auth()->tokens('api')->revokeAll($user);
$replacement = auth()->tokens('api')->rotate($otherIdentifier);
$pruned = auth()->tokens('api')->prune();
```

`revoke()` returns whether an unrevoked stored token record was marked revoked, including a retained record whose expiry already passed; `revokeAll()` returns the number marked. Both invalidate otherwise usable credentials for the next request. `rotate()` issues a new secret while atomically revoking the selected old credential through the selected repository; deliver the replacement's raw value once. Its name, abilities, and expiry are retained. Invalid, expired, or revoked tokens cannot be rotated. `prune()` returns the number of retained expired or revoked rows removed for this named guard according to configuration; expiry and revocation checks do not depend on pruning. Listing returns `TokenMetadata` entries with `identifier()`, `name()`, `abilities()`, `createdAt()`, `expiresAt()`, `lastUsedAt()`, and `revokedAt()`, without hash or secret material. Treat token names as untrusted display text and escape them in HTML.

A token-list endpoint can use an application [API resource](ApiResources.md) to select metadata fields explicitly. It must never reuse the one-time issuance response shape for a list:

```php
use App\Plugins\{ApiResource, TokenMetadata};

final class PersonalTokenResource extends ApiResource
{
    public function toArray(): array
    {
        /** @var TokenMetadata $token */
        $token = $this->resource;

        return [
            'identifier' => $token->identifier(),
            'name' => $token->name(),
            'abilities' => $token->abilities(),
            'created_at' => $token->createdAt()->format(DATE_ATOM),
            'expires_at' => $token->expiresAt()?->format(DATE_ATOM),
            'last_used_at' => $token->lastUsedAt()?->format(DATE_ATOM),
            'revoked_at' => $token->revokedAt()?->format(DATE_ATOM),
        ];
    }
}

return PersonalTokenResource::collection(auth()->tokens('api')->listFor($user))->response();
```

The default TTL is 30 days, with a configured maximum of 365 days in the example. `expiresAt: null` means use that default. To issue non-expiring tokens, set both `allow_non_expiring => true` and `default_ttl => null` deliberately. An explicit expiry must be later than the current framework time and no later than `max_ttl`. Dates are normalized to UTC second precision at the persistence boundary. `last_used_at` is updated at most once per configured interval so a busy API does not write on every request; an interval of zero allows every successful request to update it. No IP or User-Agent history is stored by default. Rotation and revocation require an authorized application operation; a token identifier by itself is not permission to administer another user's token.

Ability names are case-sensitive, bounded identifiers using letters, digits, `.`, `_`, or `-`, with an initial letter or underscore; `*` is the only wildcard. A token can hold up to 64 abilities, each at most 128 bytes. An empty ability list is valid and grants no token ability. `$metadata->allows('orders.read')` checks this boundary without querying a policy. There is no glob matching or implicit hierarchy: `orders.*` is an ordinary exact name, not a wildcard pattern.

## Authenticate an API route

Attach the named guard middleware before an ability requirement:

```php
use App\Plugins\{RequireAbility, RequireToken, RequireTokenAbility, Route};
use Project\Controllers\OrderApiController;

Route::path('/api/orders')
    ->get([OrderApiController::class, 'index'])
    ->through([
        RequireToken::guard('api'),
        RequireTokenAbility::named('orders.read'),
        RequireAbility::named('orders.view'),
    ]);
```

The first middleware authenticates `Authorization: Bearer <token>` against the named guard. It selects the request identity for `auth()->user()` and normal Authorization checks. The second checks only the token's ability boundary. The third asks the application's authorization rule about the identity. **A token ability narrows access; it does not grant an identity permission that Authorization denies.** Route middleware runs in the order listed. `auth:api` is not SqueHub's route alias syntax.

If a request carries both a session cookie and a Bearer header, the selected route guard determines the identity. `RequireToken::guard('api')` uses the token identity and never falls back to the cookie. An ordinary route using the configured `web` guard uses its session identity. SqueHub does not merge the two identities or pick whichever credential happens to succeed first.

Within that request, `auth()->token()` returns safe `TokenMetadata`; it returns `null` for a session guard or an unauthenticated token guard. The configured default can itself be a token guard, although keeping a separate browser default is usually clearer. `auth()->tokenAllows('orders.read')` checks the active token boundary without making an application Authorization decision. A token with a specific ability or `*` passes this token check; `*` does not bypass a Gate or policy. The token identity and metadata are cleared at the next Kernel request and are not process-global state.

Bearer parsing accepts one well-formed Authorization header and a case-insensitive `Bearer` scheme. It rejects missing, empty, malformed, oversized, or repeated or combined Authorization values visible to `Request`, even when repeated values agree. PHP and a fronting server may collapse or discard duplicate lines before `Request::capture()` sees them; configure the reverse proxy and web server to reject ambiguous duplicate Authorization headers at that boundary. Tokens are not accepted from query strings, body fields, or cookies. Missing, unknown, expired, revoked, and orphaned tokens all fail authentication without exposing which condition occurred. A token-authenticated API 401 carries `WWW-Authenticate: Bearer`; the [API error contract](ApiResponses.md) keeps a generic public message and a trusted request ID. Insufficient token ability returns the managed 403 `forbidden` response.

## Browser security and middleware order

Global [CSRF](Csrf.md) middleware runs **before** route middleware. It protects unsafe requests by default, including `/api` paths. For a dedicated stateless Bearer-only endpoint that accepts an unsafe method, explicitly list that path in `Config/Csrf.php` `except` and require the token guard on the route. A path exclusion applies before the guard and therefore must not be used on a route that also permits session/cookie authentication. A mixed browser/session endpoint keeps CSRF protection. Neither a Bearer header nor a CORS grant automatically disables CSRF.

A cross-origin browser client that sends `Authorization` must have that header explicitly listed in its [CORS](Cors.md) policy. CORS governs whether browser JavaScript may use the response; it is not authentication. For a session-backed browser API, allow the configured CSRF header instead and keep the synchronizer token check. Do not broaden origins or credentials merely because a token guard exists.

Existing [rate limiting](RateLimiting.md) composes with token routes. Choose a key deliberately, such as a trusted authenticated identity or safe opaque token identifier; do not use the raw token string. Place the limiter where the intended rejected requests should consume permits. No automatic token, login, or per-IP rate limit is attached by this feature.

## Database verification and guarded MySQL qualification

The API token unit and integration suites exercise token issuance, lookup, expiry, abilities, revocation, rotation, last-used metadata, listing, and pruning with SQLite and array stores. The separate `Tests/Integration/MySqlApiTokenOptInTest.php` exercises the database token repository and the bundled `api_tokens` migration against a real disposable MySQL/InnoDB database. It is an **opt-in qualification test**, not part of ordinary application setup. Its presence and a safe skip on a host without test credentials do not establish a live MySQL PASS.

Run it only against a dedicated database whose name matches `squehub_test_[A-Za-z0-9_]+` and whose name is repeated exactly in `SQUEHUB_TEST_MYSQL_CONFIRM_DATABASE`. It reads the `SQUEHUB_TEST_MYSQL_*` variables below, not the application's `.env` database credentials. The test must never point at an application database. It owns and may drop its `api_tokens` table; it does not drop a database. The test uses a database advisory lock to prevent cooperating test runs from changing the same table concurrently. If the expected table already exists after an interrupted run, inspect that disposable database and resolve the state deliberately before retrying.

In an isolated test checkout, set the dedicated test connection and enter its password without placing it in shell history:

```bash
cd /path/to/squehub-v2

export SQUEHUB_TEST_MYSQL_ENABLED=1
export SQUEHUB_TEST_MYSQL_HOST=127.0.0.1
export SQUEHUB_TEST_MYSQL_PORT=3306
export SQUEHUB_TEST_MYSQL_DATABASE=squehub_test_tokens
export SQUEHUB_TEST_MYSQL_USER=squehub_test
export SQUEHUB_TEST_MYSQL_CONFIRM_DATABASE=squehub_test_tokens

read -s -p "MySQL test password: " SQUEHUB_TEST_MYSQL_PASSWORD
export SQUEHUB_TEST_MYSQL_PASSWORD
echo

php vendor/bin/phpunit Tests/Integration/MySqlApiTokenOptInTest.php
php vendor/bin/phpunit --group mysql
composer test
```

The guarded test expects genuine MySQL rather than MariaDB. It covers migration shape, opaque issuance and hash-only storage, authentication, bounded abilities, expiry, revocation, transactional rotation and rollback, last-used writes, safe metadata listing, pruning, and owned-table cleanup. The guarded path has been exercised on Linux with MySQL 8.4.11, PDO MySQL, and InnoDB. Without explicit opt-in credentials, the test skips; that skip does not verify a MySQL server. Run it again against a confirmed disposable database and the exact source revision selected for deployment.

## Diagnostics and failure privacy

`diagnostics()->snapshot()['token_auth']` contains only `attempts`, `successes`, `failures`, `issued`, `revoked`, `rotated`, `pruned`, and `errors`. Authentication attempts count only when a syntactically accepted raw token reaches the token manager; a missing or malformed Authorization header is rejected by the guard without a token lookup. The counts reset per Kernel request. They contain no guard name, token identifier, name, identity, ability, raw credential, hash, or header value. Normal authentication and ability denial follows the existing quiet HTTP error policy; infrastructure failures cross the normal production-safe exception boundary. The framework's shared redactor also masks strings shaped like issued `sqh_pat_` credentials in normal log and debug text, including before a token has appeared in an HTTP request. This is a defensive safeguard, not permission to log credentials or include them in arbitrary third-party telemetry. See [Diagnostics](Diagnostics.md), [Logging](Logging.md), and [API responses](ApiResponses.md).

## Security and operational limits

- Protect the original issuance response. SqueHub cannot recover the raw value after it leaves that response.
- Keep token abilities distinct from application gates and policies. `*` grants all **token** abilities, not all application authorization decisions.
- Expiry and revocation are checked against the authoritative token repository on authentication. A missing identity cannot be reconstructed from stale token metadata.
- Treat database token rows and backups as sensitive, even though the stored secret is hashed. Do not put raw tokens in exceptions, logs, Diagnostics, Session, Cache, or Queue payloads.
- `array` storage is not cross-request persistence. The database repository requires the application token table and a reachable configured connection; Redis is not required.
- Account Security reset and verification tokens are separate one-time credentials with different purpose and persistence semantics. They do not authenticate API requests. See [Account Security](AccountSecurity.md).

Password change/reset does not automatically revoke personal access tokens in this foundation. Applications that require that policy should call `revokeAll($identity)` for each relevant token guard after a successful credential change. `prune()` is a service operation; no automatic pruning schedule or token-management CLI command is installed.

The current API token feature does not implement JWT, refresh tokens, an authorization server, a token management UI, or automatic application permission assignment. The separate [OIDC login client](OAuth.md) returns a verified external identity for application mapping; it never issues or accepts a SqueHub personal access token. Applications own token issuance and revocation endpoints. Token-specific diagnostics remain aggregate and must not expose identities, abilities, names, identifiers, secrets, hashes, or Bearer header values.
