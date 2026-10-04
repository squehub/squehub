# SqueHub v2 account and credential security

Account security supplies password changes and one-time account tokens using the configured Auth identity provider and PasswordHasher. These password-reset and email-verification tokens are separate from [personal access tokens](ApiTokens.md): they do not authenticate API requests or carry API abilities. This subsystem does not deliver them or add a controller, route, or form. Applications can compose the separate [Mail foundation](Mail.md) with token issuance. Public reset and verification request endpoints should use [rate limiting](RateLimiting.md); throttling is not automatic.

Password changes and resets invalidate older Sessions on their next identity resolution through the existing credential fingerprint behavior. They do not automatically revoke personal access tokens; applications that require PAT revocation on credential change should call the named token manager's `revokeAll($identity)` after successful change/reset, according to their security policy.

## Configuration and schema

`Config/Auth.php` selects a Session guard and a modern Model implementing `Authenticatable`. Optional email verification needs both `verification_address` and `verified_at` column names in the identity source, for example `'verification_address' => 'email'` and `'verified_at' => 'email_verified_at'`. The latter column must be nullable. A provider without these options remains valid for password operations; an email-verification call fails clearly.

`Config/AccountSecurity.php` supplies `tokens.driver` (`database` or ephemeral `array`), `tokens.table` (`account_security_tokens` by default), and `password_reset.ttl` / `email_verification.ttl` in **seconds**. Defaults are 3,600 and 86,400 seconds. The configuration key loaded from the capitalized filename is `accountSecurity`. Invalid drivers, table names, and non-positive/non-integer TTLs fail at bootstrap without opening a database connection. An unconfigured Auth guard and missing token table do not prevent ordinary bootstrap.

Create the token table in an application migration before using the database driver. This is the actual portable Schema API:

```php
schema()->create('account_security_tokens', static function (\App\Plugins\Table $table): void {
    $table->string('token_hash', 64);
    $table->string('purpose', 32);
    $table->string('guard', 64);
    $table->string('identity_identifier', 255);
    $table->string('context_hash', 64);
    $table->datetime('expires_at');
    $table->datetime('created_at');
    $table->unique('token_hash');
    $table->index(['guard', 'identity_identifier', 'purpose']);
    $table->index('expires_at');
});
```

No table is created during bootstrap. The table contains neither plaintext token, password hash, address, Model object, nor Session ID. A token is 32 cryptographically random bytes encoded as 43-character unpadded base64url. Its SHA-256 hash alone is stored. The immutable `SecurityToken` returns plaintext through `token()` once to trusted application code and an expiry through `expiresAt()`. Never log the plaintext.

## Change the current password

```php
$changed = accountSecurity()->changePassword($currentPassword, $newPassword);
```

An authenticated identity is required. A wrong current password returns `false` without changing the Session, password, or outstanding tokens. A guest or unchanged/empty new password raises `AccountSecurityException`. The PasswordHasher enforces its configured algorithm and technical byte limits. On success, the provider writes only the password field; SqueHub rotates the current Session ID, records the new credential fingerprint, keeps that Session authenticated, and revokes old reset tokens. A failed password write leaves Session state alone. If Session rotation fails after the write, SqueHub clears current auth metadata best-effort and propagates the failure. The user can log in again. Credential context makes old reset tokens invalid even if revocation cleanup fails.

Other Sessions retain their old fingerprint until they next resolve the identity. Then they become guests. This is **lazy invalidation**, not immediate deletion of other PHP Session files. Identifier-only Sessions from before account-security fingerprinting also need a new login. The fingerprint is SHA-256 of the stored password hash, kept only in reserved `_squehub` metadata and hidden from `session()->all()`; the password hash itself is never stored there. Login and `attempt()` after a rehash use the final hash.

## Reset a password

```php
$issued = accountSecurity()->issuePasswordReset(['email' => $email]); // SecurityToken|null
$reset = accountSecurity()->resetPassword($token, $newPassword);       // bool
```

Issuance uses the selected guard's existing IdentityProvider and replaces the prior token for the same identity and purpose. A missing or soft-deleted identity returns `null` without writing a row. A public request handler should give the same response either way, such as “If an account matches those details, reset instructions can be sent.” Core cannot prevent an application from revealing the return distinction.

The token is bound to a digest of the identity's credential state. Consumption claims it before loading and changing the identity. Unknown, expired, replaced, consumed, deleted-identity, or stale-context tokens return `false`. Successful reset writes only the password column and invalidates old Sessions when they next resolve the identity. It does not authenticate anyone or require an active browser Session. A provider failure after claim propagates and the token remains consumed; issue a fresh token after recovery. The same new-password rules apply as to current-password change.

## Verify an address

```php
$issued = accountSecurity()->issueEmailVerification($identity); // SecurityToken|null
$verified = accountSecurity()->verifyEmail($token);              // bool
```

Issuance requires the selected provider's optional verification capability and a supported active identity. Already verified identities return `null`. The context is SHA-256 of the provider's exact current address; changing that address makes the token invalid. A successful claim writes only the configured verification timestamp using the framework UTC clock. Unknown, expired, replaced, consumed, stale-address, deleted-identity, or already-verified claims return `false`. Verification never logs in the identity. A failed write leaves the claimed token consumed.

Use a named configured guard without changing the default Auth or Session state:

```php
$admin = accountSecurity()->forGuard('admin');
$issued = $admin->issuePasswordReset(['email' => $email]);
```

Tokens are bound to guard name, identity key, and purpose. Renaming a guard or changing an identity identifier can make outstanding tokens unusable. Reset and verification purposes remain independent. Database claims use a conditional delete: only a consumer whose delete affects the row may proceed. Array storage has the same logical replacement, expiry, and one-time behavior for tests but is not persistent.

## Privacy and limits

`diagnostics()->snapshot()['account_security']` contains only aggregate `password_changes`, `password_change_failures`, `reset_tokens_issued`, `password_resets`, `verification_tokens_issued`, `email_verifications`, and `errors`. It resets per request. Normal outcomes produce no automatic log or event. Unexpected failures crossing HTTP use the existing top-level logger. Application handlers must not place plaintext credentials or tokens in exception messages or log context. Plaintext method parameters use PHP 8.2 `SensitiveParameter` where applicable.

Account Security itself does not select or consume rate limits. For a public reset route, register a named `password-reset` limiter in an application provider and attach it before the controller:

```php
use App\Plugins\RateLimitRequests;
use App\Plugins\Route;

Route::path('/password/reset/request')
    ->post([PasswordResetController::class, 'request'])
    ->through(RateLimitRequests::named('password-reset'));
```

The controller may call `accountSecurity()->issuePasswordReset(['email' => $email])` after the permit is consumed. Trusted application code can then compose a [MailMessage](Mail.md) when a token was issued:

```php
use App\Plugins\MailMessage;

$issued = accountSecurity()->issuePasswordReset(['email' => $email]);
// $trustedIdentity is the matching account read from the application's
// trusted identity source; it is never built from request address fields.
if ($issued !== null && $trustedIdentity !== null) {
    mailer()->send((new MailMessage())
        ->to($trustedIdentity->email)
        ->subject('Password reset instructions')
        ->text('Use this one-time token: ' . $issued->token()));
}
// Return the same public response for known and unknown identities.
```

Verification issuance composes in the same way. An application may instead pass the issued token to an explicit [Notification](Notifications.md), whose Mail channel uses the trusted identity's `routeNotificationForMail()`. A Notification implementing `App\Plugins\ShouldQueue` can defer that delivery through Queue when the trusted identity implements `App\Plugins\QueueNotifiable`:

```php
$issued = accountSecurity()->issuePasswordReset(['email' => $email]);
if ($issued !== null && $trustedIdentity !== null) {
    $trustedIdentity->notify(new PasswordResetNotice($issued->token()));
    // PasswordResetNotice implements ShouldQueue and its explicit payload contract.
}
```

Token issuance itself sends nothing; the Notification enqueue also sends nothing on a persistent Queue connection. The worker later reconstructs the trusted identity and reads its current mail route. The token is sensitive Queue payload data and must be protected by Queue table access controls. Failed-job retry now retains that payload after the explicit Queue migration, so failed-job tables and backups require the same protection. Tokens never enter CLI listing or aggregate diagnostics. Queue retry can duplicate an already delivered message. Send only to an address obtained from a trusted identity source or properly verified against it; untrusted request input must not redirect a security token to another address. Never log plaintext tokens or place them in subjects/diagnostics. Delivery is not automatic. This foundation still has no reset or verification controllers, Session registry, device management, passkeys, or security audit history. Optional [MFA](MFA.md) keeps enrollment separate from password reset and change. The separate [OIDC login client](OAuth.md) does not change account reset or verification tokens. A caller-owned transaction rollback can leave in-memory identity state stale under existing Model rules; refresh it where appropriate.

When a token must be delivered only after its database transaction commits, make the queueable Notification return `true` from `queueAfterCommit()`. The NotificationManager delegates to Queue, which waits for the outermost commit and discards dispatch on rollback. Account Security remains delivery-independent. A post-commit Queue persistence error does not undo the already committed token state; applications needing atomic cross-resource delivery should use an outbox pattern.

## Application-facing Plugins import

Application code may import `App\Plugins\AccountSecurity`, `App\Plugins\SecurityToken`, `App\Plugins\Table`, `App\Plugins\Route`, `App\Plugins\RateLimitRequests`, and `App\Plugins\MailMessage`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.

## Cryptography boundary

Account Security continues to issue URL-safe random tokens and hash stored token values. Its secure-token encoding now shares the canonical CSPRNG utility with `App\Plugins\Crypt::randomToken()`; no account-token format changed. Passwords continue to use the Authentication password hasher. Use [Cryptography](Cryptography.md) only for application values that must later be recovered.
