<?php

declare(strict_types=1);

namespace App\Cryptography;

use App\Diagnostics\Diagnostics;
use App\Support\SecureRandom;
use JsonException;
use Random\RandomException;
use Throwable;

/**
 * Application-owned authenticated encryption and keyed MAC service.
 *
 * Master keys are decoded only on first keyed use. Encryption and signing
 * derive separate 32-byte subkeys with HKDF; neither stores its input in
 * diagnostics. An envelope declares its algorithm so a rotated backend can
 * still read older supported values.
 */
final class CryptManager
{
    private const PREFIX = 'shc1.';
    private const SIGN_PREFIX = 'shs1.';
    private const VERSION = 1;
    private const TAG_BYTES = 16;

    private string $driver;
    private string $current;
    private int $maxPlaintextBytes;
    /** @var array<string,string>|null Decoded master keys, never included in debug output. */
    private ?array $keys = null;

    /** @param array<string,mixed> $settings */
    public function __construct(private array $settings = [], private ?Diagnostics $diagnostics = null)
    {
        $requested = $settings['driver'] ?? 'auto';
        $current = $settings['current'] ?? 'primary';
        $maximum = $settings['max_plaintext_bytes'] ?? 1048576;
        if (!is_string($requested) || !in_array($requested, ['auto', 'sodium', 'openssl'], true)
            || !is_string($current) || !self::validKeyId($current)
            || !is_int($maximum) || $maximum < 1 || $maximum > 16777216) {
            throw new CryptConfigurationException('Cryptographic configuration is invalid.');
        }
        $this->current = $current;
        $this->maxPlaintextBytes = $maximum;
        $this->driver = $requested === 'auto' ? self::autoDriver() : $requested;
        if (!self::available($this->driver)) {
            throw new CryptConfigurationException('Requested cryptographic backend is unavailable.');
        }
    }

    public function __debugInfo(): array
    {
        return ['driver' => $this->driver, 'keys' => '[REDACTED]'];
    }

    /** Current backend is fixed for this Application's manager lifetime. */
    public function driver(): string { return $this->driver; }

    public function encrypt(#[\SensitiveParameter] string $plaintext,
        #[\SensitiveParameter] string $purpose = ''): string
    {
        return $this->measure('encryptions', function () use ($plaintext, $purpose): string {
            self::validatePurpose($purpose);
            if (strlen($plaintext) > $this->maxPlaintextBytes) {
                throw new CryptException('Plaintext exceeds the configured size limit.');
            }
            $algorithm = $this->driver === 'sodium' ? 'xchacha20-poly1305' : 'aes-256-gcm';
            $key = $this->subkey($this->current, 'encryption/' . $algorithm);
            $nonceLength = $this->driver === 'sodium' ? 24 : 12;
            try { $nonce = random_bytes($nonceLength); }
            catch (RandomException $exception) {
                throw new CryptException('Secure randomness is unavailable.', 0, $exception);
            }
            $aad = self::associatedData($algorithm, $this->current, $purpose);
            if ($this->driver === 'sodium') {
                $combined = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
                $ciphertext = substr($combined, 0, -self::TAG_BYTES);
                $tag = substr($combined, -self::TAG_BYTES);
            } else {
                $tag = '';
                $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
                    $nonce, $tag, $aad, self::TAG_BYTES);
                if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
                    throw new CryptException('Encryption failed.');
                }
            }
            return self::PREFIX . self::encodeJson([
                'v' => self::VERSION, 'alg' => $algorithm, 'kid' => $this->current,
                'n' => self::encode($nonce), 'ct' => self::encode($ciphertext), 'tag' => self::encode($tag),
            ]);
        });
    }

    public function decrypt(#[\SensitiveParameter] string $payload,
        #[\SensitiveParameter] string $purpose = ''): string
    {
        return $this->measure('decryptions', function () use ($payload, $purpose): string {
            self::validatePurpose($purpose);
            $entry = $this->parseEnvelope($payload);
            $algorithm = $entry['alg'];
            $backend = $algorithm === 'xchacha20-poly1305' ? 'sodium' : 'openssl';
            if (!self::available($backend)) {
                throw new DecryptException('Encrypted value uses an unavailable format.');
            }
            $key = $this->subkey($entry['kid'], 'encryption/' . $algorithm, true);
            $aad = self::associatedData($algorithm, $entry['kid'], $purpose);
            if ($backend === 'sodium') {
                $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $entry['ct'] . $entry['tag'], $aad, $entry['n'], $key);
            } else {
                $plaintext = openssl_decrypt($entry['ct'], 'aes-256-gcm', $key,
                    OPENSSL_RAW_DATA, $entry['n'], $entry['tag'], $aad);
            }
            if ($plaintext === false) throw new DecryptException('Encrypted value authentication failed.');
            if (strlen($plaintext) > $this->maxPlaintextBytes) {
                throw new DecryptException('Encrypted value exceeds the configured size limit.');
            }
            return $plaintext;
        });
    }

    /**
     * A keyed HMAC provides authenticity, not asymmetric digital signatures.
     * The signature embeds only a non-secret key ID and MAC, never input data.
     */
    public function sign(#[\SensitiveParameter] string $data,
        #[\SensitiveParameter] string $purpose = ''): string
    {
        return $this->measure('signatures', function () use ($data, $purpose): string {
            self::validatePurpose($purpose);
            if (strlen($data) > $this->maxPlaintextBytes) {
                throw new CryptException('Signing input exceeds the configured size limit.');
            }
            $mac = hash_hmac('sha256', self::signingData($data, $purpose, $this->current),
                $this->subkey($this->current, 'signing'), true);
            return self::SIGN_PREFIX . self::encodeJson([
                'v' => self::VERSION, 'kid' => $this->current, 'mac' => self::encode($mac),
            ]);
        });
    }

    /** Invalid submitted signatures are ordinary false results, not exceptions. */
    public function verify(#[\SensitiveParameter] string $data,
        #[\SensitiveParameter] string $signature, #[\SensitiveParameter] string $purpose = ''): bool
    {
        return $this->measure('verifications', function () use ($data, $signature, $purpose): bool {
            self::validatePurpose($purpose);
            if (strlen($data) > $this->maxPlaintextBytes) {
                throw new CryptException('Signing input exceeds the configured size limit.');
            }
            if (!str_starts_with($signature, self::SIGN_PREFIX) || strlen($signature) > 256) return false;
            $encoded = substr($signature, strlen(self::SIGN_PREFIX));
            try { $entry = self::decodeJson($encoded); }
            catch (DecryptException) { return false; }
            if (self::encodeJson($entry) !== $encoded) return false;
            if (array_keys($entry) !== ['v', 'kid', 'mac'] || $entry['v'] !== self::VERSION
                || !is_string($entry['kid']) || !self::validKeyId($entry['kid'])
                || !is_string($entry['mac'])) return false;
            $submitted = self::decode($entry['mac']);
            if ($submitted === null || strlen($submitted) !== 32) return false;
            $keys = $this->keys();
            if (!isset($keys[$entry['kid']])) return false;
            $expected = hash_hmac('sha256', self::signingData($data, $purpose, $entry['kid']),
                self::derive($keys[$entry['kid']], 'signing'), true);
            return hash_equals($expected, $submitted);
        });
    }

    /** URL-safe token; the requested byte count is the entropy source size. */
    public function randomToken(int $bytes = 32): string
    {
        return $this->measure(null, static function () use ($bytes): string {
            if ($bytes < 16 || $bytes > 128) {
                throw new CryptException('Secure token length must be between 16 and 128 bytes.');
            }
            try { return SecureRandom::token($bytes); }
            catch (Throwable $exception) {
                throw new CryptException('Secure randomness is unavailable.', 0, $exception);
            }
        });
    }

    /** Decode strict Base64 keys once; weak passwords are never padded or hashed into keys. */
    private function keys(): array
    {
        if ($this->keys !== null) return $this->keys;
        $configured = $this->settings['keys'] ?? [];
        if (!is_array($configured) || $configured === []) {
            throw new CryptConfigurationException('Cryptographic key is unavailable.');
        }
        $decoded = [];
        foreach ($configured as $id => $encoded) {
            if (!is_string($id) || !self::validKeyId($id) || !is_string($encoded)
                || !preg_match('~\Abase64:[A-Za-z0-9+/]{43}=\z~D', $encoded)) {
                throw new CryptConfigurationException('Cryptographic key configuration is invalid.');
            }
            $raw = base64_decode(substr($encoded, 7), true);
            if ($raw === false || strlen($raw) !== 32 || 'base64:' . base64_encode($raw) !== $encoded) {
                throw new CryptConfigurationException('Cryptographic key configuration is invalid.');
            }
            $decoded[$id] = $raw;
        }
        if (!isset($decoded[$this->current])) {
            throw new CryptConfigurationException('Current cryptographic key is unavailable.');
        }
        $this->keys = $decoded;
        unset($this->settings['keys']);
        return $this->keys;
    }

    private function subkey(string $id, string $use, bool $decrypt = false): string
    {
        $keys = $this->keys();
        if (!isset($keys[$id])) {
            throw $decrypt ? new DecryptException('Encrypted value references an unavailable key.')
                : new CryptConfigurationException('Current cryptographic key is unavailable.');
        }
        return self::derive($keys[$id], $use);
    }

    private static function derive(#[\SensitiveParameter] string $master, string $use): string
    {
        return hash_hkdf('sha256', $master, 32, 'squehub-crypt-v1/' . $use);
    }

    /** @return array{alg:string,kid:string,n:string,ct:string,tag:string} */
    private function parseEnvelope(#[\SensitiveParameter] string $payload): array
    {
        if (!str_starts_with($payload, self::PREFIX)
            || strlen($payload) > ($this->maxPlaintextBytes * 2 + 4096)) {
            throw new DecryptException('Encrypted value format is invalid.');
        }
        $encoded = substr($payload, strlen(self::PREFIX));
        $entry = self::decodeJson($encoded);
        // Canonical JSON forbids duplicate fields and alternate encodings of
        // the same authenticated fields from producing a second valid text.
        if (self::encodeJson($entry) !== $encoded) {
            throw new DecryptException('Encrypted value format is invalid.');
        }
        if (array_keys($entry) !== ['v', 'alg', 'kid', 'n', 'ct', 'tag']
            || $entry['v'] !== self::VERSION || !is_string($entry['alg'])
            || !in_array($entry['alg'], ['xchacha20-poly1305', 'aes-256-gcm'], true)
            || !is_string($entry['kid']) || !self::validKeyId($entry['kid'])
            || !is_string($entry['n']) || !is_string($entry['ct']) || !is_string($entry['tag'])) {
            throw new DecryptException('Encrypted value format is invalid.');
        }
        $nonce = self::decode($entry['n']);
        $ciphertext = self::decode($entry['ct']);
        $tag = self::decode($entry['tag']);
        $nonceSize = $entry['alg'] === 'xchacha20-poly1305' ? 24 : 12;
        if ($nonce === null || strlen($nonce) !== $nonceSize || $ciphertext === null
            || strlen($ciphertext) > $this->maxPlaintextBytes
            || $tag === null || strlen($tag) !== self::TAG_BYTES) {
            throw new DecryptException('Encrypted value format is invalid.');
        }
        return ['alg' => $entry['alg'], 'kid' => $entry['kid'], 'n' => $nonce,
            'ct' => $ciphertext, 'tag' => $tag];
    }

    private static function associatedData(string $algorithm, string $id,
        #[\SensitiveParameter] string $purpose): string
    {
        return "squehub-crypt-v1\0" . $algorithm . "\0" . $id . "\0" . $purpose;
    }

    private static function signingData(#[\SensitiveParameter] string $data,
        #[\SensitiveParameter] string $purpose, string $id): string
    {
        return "squehub-sign-v1\0" . $id . "\0" . $purpose . "\0" . $data;
    }

    private static function validKeyId(string $id): bool
    {
        return preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/D', $id) === 1;
    }

    private static function validatePurpose(string $purpose): void
    {
        if (strlen($purpose) > 128 || ($purpose !== ''
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $purpose) !== 1)) {
            throw new CryptConfigurationException('Cryptographic purpose is invalid.');
        }
    }

    private static function autoDriver(): string
    {
        if (self::available('sodium')) return 'sodium';
        if (self::available('openssl')) return 'openssl';
        throw new CryptConfigurationException('No supported cryptographic backend is available.');
    }

    private static function available(string $backend): bool
    {
        return match ($backend) {
            'sodium' => function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
                && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt'),
            'openssl' => function_exists('openssl_encrypt') && function_exists('openssl_decrypt')
                && in_array('aes-256-gcm', openssl_get_cipher_methods(), true),
            default => false,
        };
    }

    private static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        if ($encoded !== '' && preg_match('/\A[A-Za-z0-9_-]+\z/D', $encoded) !== 1) return null;
        $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        return $decoded !== false && self::encode($decoded) === $encoded ? $decoded : null;
    }

    private static function encodeJson(#[\SensitiveParameter] array $value): string
    {
        try { return self::encode(json_encode($value, JSON_THROW_ON_ERROR)); }
        catch (JsonException $exception) {
            throw new CryptException('Cryptographic envelope could not be encoded.', 0, $exception);
        }
    }

    private static function decodeJson(#[\SensitiveParameter] string $encoded): array
    {
        $json = self::decode($encoded);
        if ($json === null) throw new DecryptException('Encrypted value format is invalid.');
        try { $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new DecryptException('Encrypted value format is invalid.'); }
        if (!is_array($value) || array_is_list($value)) {
            throw new DecryptException('Encrypted value format is invalid.');
        }
        return $value;
    }

    private function measure(?string $successCounter, callable $operation): mixed
    {
        $started = hrtime(true);
        try {
            $result = $operation();
            if ($successCounter !== null) $this->diagnostics?->crypt($successCounter);
            return $result;
        } catch (Throwable $exception) {
            $this->diagnostics?->crypt('failures');
            throw $exception;
        } finally {
            $this->diagnostics?->cryptTime((hrtime(true) - $started) / 1_000_000);
        }
    }
}
