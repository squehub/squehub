# Multi-factor authentication (17C)

MFA is an optional second stage for session authentication. The v2 core supports RFC 6238 TOTP and one-time recovery codes. Passwords remain with Auth; personal access token guards keep their established behavior. Passkeys are deferred for the reasons below.

## Enable and migrate

Set `security.mfa.enabled` to `true` in `Config/Security.php`. Configure the issuer, repository (`database` or `array`), table names and optional connection, enrollment and challenge lifetimes, skew, recovery count, and attempt limit there. The array repository is Application-local and suitable only for tests or ephemeral use. Use the database repository for persistent accounts. Run `Database/Migrations/2026_09_30_create_mfa_tables.php` with the normal migration command before enabling MFA; that migration creates the default table names, so custom names need a matching custom migration. Configure a valid `APP_KEY` for Crypt; keep previous keys configured during rotation until every encrypted MFA secret has been migrated or its enrollment replaced. Bootstrap validates configuration without opening the database or decoding the key when MFA is disabled.

The defaults use a 20-byte CSPRNG secret encoded as unpadded Base32 for provisioning; TOTP uses HMAC-SHA1, six digits, 30-second periods, and a one-step clock allowance in each direction. The skew setting is limited to zero or one. The active and pending secrets are authenticated-encrypted with the Application's Crypt service and an identity-bound purpose. No raw secret or provisioning URI is written to Session, diagnostics, or the database. `MfaEnrollment` returns the Base32 secret and URI only at enrollment generation; the application must display them privately once. Its debug representation redacts both.

## Enrollment

An authenticated session can call `Mfa::forGuard('web')->beginEnrollment($accountLabel)`. The returned `secret()` and `provisioningUri()` are for the enrollment page. The issuer and account label must be valid UTF-8 without a colon; each is URL encoded around the literal separator. The application may render a QR code with its own chosen tooling; core does not require a QR package. A pending encrypted enrollment expires after `enrollment_ttl` seconds, and an existing enabled enrollment cannot be replaced through this call. The enrollment value cannot be serialized or safely logged.

After the user enters a current authenticator code, call `confirmEnrollment($code)`. It returns `null` on an invalid, expired, or already claimed confirmation. On success it atomically enables the enrollment, records the accepted TOTP counter, and returns the new raw recovery codes **once**. The confirmation counter cannot also complete a challenge. Store those codes with the user; only SHA-256 digests remain in storage. They contain 128 bits of randomness each and are presented as eight groups of four hexadecimal characters. Entry accepts their generated hyphen grouping or the ungrouped form and ASCII letter case; spaces or other transformations are rejected.

```php
use App\Plugins\Mfa;

$mfa = Mfa::forGuard('web');
$enrollment = $mfa->beginEnrollment($accountLabel);
// Privately display $enrollment->secret() or $enrollment->provisioningUri().
$codes = $mfa->confirmEnrollment($submittedTotp); // null until proven
```

## Login challenge

On a successful primary factor for an enrolled identity, Auth invalidates the old session, creates a private pending challenge with guard, typed identity scope digest, credential fingerprint, expiry, and remember intent, and returns a guest result. `Auth::attempt()` returns `false` while pending even though the password was correct; `Mfa::pending()` or `Mfa::forGuard('web')->pending()` distinguishes that state from an ordinary failed login. All session guards' `user()` and `check()` methods remain guest while any MFA challenge is pending, including when a different guard had cached a user earlier in the request. Authorization middleware therefore cannot treat the pending identity as signed in. The pending session contains no TOTP secret or submitted proof.

Submit TOTP or a recovery code with `Mfa::forGuard('web')->completeChallenge($proof)`. A success atomically advances the last accepted TOTP counter or deletes one recovery hash, clears the pending challenge, regenerates the session, and only then exposes the fully authenticated identity. A proof consumed concurrently or replayed later fails. A challenge expires after `challenge_ttl` seconds; a removed account, a changed password hash, or a changed identity scope abandons it. Failed attempts use the configured `RateLimiter` bucket keyed only by the scope digest. If session rotation fails after a proof is consumed, the user remains a guest and must start a fresh primary login. Neither proof nor cryptographic secret appears in rate-limit keys.

Remembered browser credentials prove only the primary stage. If MFA is enabled, recall creates a pending challenge and does not publish an authenticated session or remember cookie. A requested remember credential is issued after full MFA completion. There is no trusted-device exemption in 17C.

```php
if (Auth::attempt($credentials, remember: true)) {
    // Fully authenticated when MFA is not enabled for this account.
} elseif (Mfa::pending()) {
    // Render the challenge form; Auth::check() is false here.
}

if (Mfa::forGuard('web')->completeChallenge($submittedProof)) {
    // The session has rotated and Auth::check() is now true.
}
```

The application should put enrollment, challenge, recovery, and management forms behind its existing Validation and CSRF controls, send responses without caching, and avoid logging submitted proofs. An MFA challenge endpoint must not require `auth` middleware because the challenge is intentionally a guest state.

## Recovery and disable

`regenerateRecoveryCodes($currentProof)` requires a fully authenticated session and consumes a valid TOTP or unused recovery code. It returns a fresh set once and transactionally removes every previous unused code. `disable($currentProof)` also requires a fully authenticated session and consumes a valid proof before removing the enrollment and its recovery codes. There is no manager API to disable MFA for an arbitrary identity without the current session and proof. Application endpoints must enforce their own CSRF, account policy, and any desired recent-password check. Password change and reset do not silently disable MFA; a pending challenge bound to the old credential fingerprint becomes invalid. Account recovery without either factor is an application-specific support policy.

Identity deletion should remove the MFA row in the same database transaction where possible. The scope digest includes the guard, identity class, and a typed identifier. Canonical decimal strings produced from integer primary keys share the integer scope; noncanonical strings stay distinct. This avoids case-insensitive database collation ambiguity and keeps reused IDs from inheriting another guard's enrollment, but a newly created account reusing the exact same ID and class should not inherit a deleted account's MFA state. Remove the old state when deleting the account.

## WebAuthn evaluation

The current browser and auth contracts do not supply WebAuthn binary credential parsing, RP ID and origin policy, attestation and assertion validation, public-key signature and authenticator counter handling, or browser ceremony integration. The pending-session challenge concept could later support a WebAuthn factor, with dedicated credential records and per-ceremony challenges. Implementing that protocol securely requires a separate audited platform and frontend contract, so 17C deliberately does not claim passkey support. TOTP and recovery codes are the complete v2 core MFA path.
