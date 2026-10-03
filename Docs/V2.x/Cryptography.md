# Cryptography and application secrets

[Doctor](Health.md) validates the configured Crypt key and backend with an in-memory MAC/verify check. It never prints key material, signatures, or cryptographic payloads. Set `health.require_crypt=true` when Crypt is required for readiness.

`App\Plugins\Crypt` protects application values that must be recoverable. Passwords remain in the Authentication password hasher; never encrypt passwords for login storage. Existing Account Security, CSRF, Session, and Queue tokens keep their established formats. Crypt does not automatically encrypt a database, Queue payload, Session, Cache, Storage, or logs.

```php
use App\Plugins\Crypt;

$stored = Crypt::encrypt('merchant-secret', purpose: 'merchant.credentials');
$secret = Crypt::decrypt($stored, purpose: 'merchant.credentials');

$mac = Crypt::sign($payload, purpose: 'webhook.internal');
$valid = Crypt::verify($payload, $mac, purpose: 'webhook.internal');
$token = Crypt::randomToken();
```

`crypto()` resolves the same Application-owned `CryptManager`. Packages may use that helper or inject the canonical manager; framework internals depend on `App\Cryptography`, not the Plugins gateway. There is no `crypt()` helper because PHP already defines that function.

## Extensions and backend selection

The **sodium PHP extension is preferred**. With `CRYPT_DRIVER=auto`, Crypt selects sodium's XChaCha20-Poly1305 AEAD when available, then **falls back to the OpenSSL PHP extension's AES-256-GCM AEAD** if sodium is unavailable. At least one supported extension is needed to use Crypt. Explicit `CRYPT_DRIVER=sodium` or `CRYPT_DRIVER=openssl` requires that backend and never silently switches for new encryption. The selected backend is fixed for one Application manager lifetime. Existing envelopes are read using their declared supported algorithm, including after a backend migration when that extension remains installed.

Ordinary Application boot remains possible without a key or either extension because the service is lazy. The first Crypt use fails clearly if its selected backend or required key is unavailable. On shared/cPanel hosting, enable `ext-sodium` when possible or `ext-openssl` for the supported fallback. No external server or Composer production dependency is needed.

## Keys and rotation

Run `php squehub key:generate` to print a new 256-bit key in the required `base64:<44-character Base64>` format. Copy it into the private `.env` as `APP_KEY`. The command generates a fresh key each time; it does not read, print, or rewrite an existing key or `.env` file. Never commit a real key. An empty `APP_KEY` allows boot but keyed operations fail. Human passwords, wrong lengths, noncanonical Base64, and implicit truncation/padding are rejected.

The shipped `Config/Crypt.php` reads:

```env
CRYPT_DRIVER=auto
CRYPT_CURRENT_KEY_ID=primary
APP_KEY=base64:...
CRYPT_PREVIOUS_KEY_ID=previous
APP_PREVIOUS_KEY=
```

Key IDs contain only an ASCII letter followed by up to 63 letters, numbers, `_`, or `-`. They are labels, not key material. When rotating, give the new key a **new ID** and keep the old key under its **original ID** in the key ring. For example, change the current ID to `v2`, put the new key in `APP_KEY`, set `CRYPT_PREVIOUS_KEY_ID=v1`, and put the old key in `APP_PREVIOUS_KEY`. New ciphertext uses `v2`; old `v1` ciphertext still decrypts. Removing `v1` makes its ciphertext unavailable. More historical keys can be listed explicitly in `Config/Crypt.php`; there is no comma-separated secret parser or automatic rewrite. Decrypt is side-effect free. To migrate stored data, application code decrypts and explicitly encrypts it again under the current key.

Keep all active key values outside tracked source control and accessible only to trusted deployment processes. Hashing an encoded key is not a substitute for proper key storage.

## Envelope, purpose, and limits

Encrypted text has a `shc1.` prefix followed by Base64URL-encoded version 1 JSON. The envelope contains the algorithm ID, non-secret key ID, random nonce, ciphertext, and authentication tag. The key and purpose are **not** stored in it. All envelope metadata is authenticated as associated data. A wrong purpose, wrong key, changed envelope component, or invalid authentication tag raises `App\Cryptography\DecryptException`; no plaintext is returned. Purpose identifiers may be empty or use bounded letters, numbers, `.`, `_`, `:`, and `-`. Use a stable, specific purpose for values that should not be interchangeable across features.

Sodium uses a 24-byte random nonce and XChaCha20-Poly1305. OpenSSL uses a 12-byte random nonce, AES-256-GCM, and a 16-byte tag. Both derive a separate encryption subkey from the 32-byte master with HKDF-SHA-256. The default maximum plaintext is 1 MiB (`max_plaintext_bytes` in `Config/Crypt.php`, bounded to 16 MiB); encoded envelopes are bounded before decoding. Strings are byte-preserving, including empty values, UTF-8, and NUL bytes. Crypt does not perform charset conversion or PHP `serialize()`/`unserialize()`.

`sign()` returns a versioned `shs1.` keyed HMAC-SHA-256 value. Signing uses a separate HKDF-derived subkey and binds the key ID and purpose. `verify()` uses `hash_equals`; an invalid submitted signature returns `false`. This is **symmetric keyed authentication**, not public-key digital signing. `randomToken(32)` returns 43 Base64URL characters derived from 32 random bytes (256 bits); the accepted request is 16–128 bytes. The same encoding primitive is used by Account Security's 32-byte tokens.

## Diagnostics and failure safety

`diagnostics()->snapshot()['crypt']` contains only `encryptions`, `decryptions`, `signatures`, `verifications`, `failures`, and `time_ms`. Successful operations increment their counter. A false `verify()` counts as a verification, not an exceptional failure. Exceptions increment `failures`. No plaintext, ciphertext, key, key ID, signature, token, nonce, tag, or purpose is stored in that section. Metrics reset per incoming HTTP request.

Errors have safe messages without submitted data or keys. `#[SensitiveParameter]` protects method parameters in traces, `SensitiveKey` recognizes key names, and `SecretRedactor` masks configured key values in framework error/log text. The manager's normal debug display hides its keys. Do not intentionally dump application configuration, objects with `var_export()`, or process environment variables into public output; those PHP facilities can expose secret-bearing state outside this API's debug display.

Missing or invalid keys raise `CryptConfigurationException`; malformed, unsupported, or unauthenticated ciphertext raises `DecryptException`. Randomness failure has no weak fallback. Secret values remain sensitive after decryption and must be handled by the caller. [Signed URLs](SignedUrls.md) use Crypt's derived signing key through their separate application-facing service. Automatic encrypted Model casts, Webhooks, asymmetric signatures, KMS/Vault integration, and storage-wide encryption are outside this foundation.
