<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\AccountSecurity\SecurityTokenGenerator;
use App\Config\Repository;
use App\Cryptography\CryptConfigurationException;
use App\Cryptography\CryptException;
use App\Cryptography\CryptManager;
use App\Cryptography\DecryptException;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\Support\SecretRedactor;
use App\Support\SensitiveKey;
use App\Support\SecureRandom;
use PHPUnit\Framework\TestCase;

/** Exercises real installed AEAD primitives, strict envelopes, and key privacy. */
final class CryptManagerTest extends TestCase
{
    private static function key(string $byte): string
    {
        return 'base64:' . base64_encode(str_repeat($byte, 32));
    }

    private static function manager(string $driver = 'auto', string $current = 'primary',
        ?array $keys = null, int $maximum = 1048576, ?Diagnostics $diagnostics = null): CryptManager
    {
        return new CryptManager(['driver' => $driver, 'current' => $current,
            'keys' => $keys ?? ['primary' => self::key('a')],
            'max_plaintext_bytes' => $maximum], $diagnostics);
    }

    private static function decodeEnvelope(string $payload): array
    {
        $encoded = substr($payload, 5);
        return json_decode(base64_decode(strtr($encoded, '-_', '+/')), true, 16, JSON_THROW_ON_ERROR);
    }

    private static function encodeEnvelope(array $entry): string
    {
        return 'shc1.' . rtrim(strtr(base64_encode(json_encode($entry, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @dataProvider backend */
    public function testRealBackendRoundTripsBinaryAndUsesFreshNonces(string $driver): void
    {
        if ($driver === 'sodium' && !extension_loaded('sodium')) self::markTestSkipped('Sodium unavailable.');
        if ($driver === 'openssl' && !extension_loaded('openssl')) self::markTestSkipped('OpenSSL unavailable.');
        $crypt = self::manager($driver);
        self::assertSame($driver, $crypt->driver());
        foreach (['', 'Lagos é 🐘', "\x00\xff\x01", str_repeat('x', 65536)] as $value) {
            $first = $crypt->encrypt($value);
            $second = $crypt->encrypt($value);
            self::assertNotSame($first, $second);
            self::assertSame($value, $crypt->decrypt($first));
            self::assertSame($value, $crypt->decrypt($second));
            self::assertStringStartsWith('shc1.', $first);
        }
    }

    public static function backend(): array
    {
        return [['sodium'], ['openssl']];
    }

    public function testAutoPrefersSodiumThenOpenSsl(): void
    {
        $expected = extension_loaded('sodium') ? 'sodium' : 'openssl';
        self::assertSame($expected, self::manager()->driver());
    }

    /** @dataProvider backend */
    public function testPurposeAndWrongKeyFailAuthentication(string $driver): void
    {
        if ($driver === 'sodium' && !extension_loaded('sodium')) self::markTestSkipped('Sodium unavailable.');
        if ($driver === 'openssl' && !extension_loaded('openssl')) self::markTestSkipped('OpenSSL unavailable.');
        $crypt = self::manager($driver);
        $payload = $crypt->encrypt('secret', 'payment.credentials');
        self::assertSame('secret', $crypt->decrypt($payload, 'payment.credentials'));
        foreach ([$crypt, self::manager($driver, 'primary', ['primary' => self::key('b')])] as $index => $candidate) {
            try {
                $candidate->decrypt($payload, $index === 0 ? 'mail.credentials' : 'payment.credentials');
                self::fail('Unauthenticated ciphertext was accepted.');
            } catch (DecryptException $exception) {
                self::assertStringNotContainsString('secret', $exception->getMessage());
            }
        }
    }

    /** @dataProvider backend */
    public function testEnvelopeTamperingFailsAtEveryAuthenticatedField(string $driver): void
    {
        if ($driver === 'sodium' && !extension_loaded('sodium')) self::markTestSkipped('Sodium unavailable.');
        if ($driver === 'openssl' && !extension_loaded('openssl')) self::markTestSkipped('OpenSSL unavailable.');
        $crypt = self::manager($driver, 'primary', ['primary' => self::key('a'), 'other' => self::key('b')]);
        $original = self::decodeEnvelope($crypt->encrypt('SQUEHUB_CRYPT_SECRET_DO_NOT_LEAK'));
        foreach (['v', 'alg', 'kid', 'n', 'ct', 'tag'] as $field) {
            $changed = $original;
            $changed[$field] = match ($field) {
                'v' => 2,
                'alg' => $driver === 'sodium' ? 'aes-256-gcm' : 'xchacha20-poly1305',
                'kid' => 'other',
                default => rtrim(strtr(base64_encode(str_repeat('x',
                    $field === 'n' ? ($driver === 'sodium' ? 24 : 12)
                        : ($field === 'tag' ? 16 : strlen('SQUEHUB_CRYPT_SECRET_DO_NOT_LEAK')))), '+/', '-_'), '='),
            };
            try {
                $crypt->decrypt(self::encodeEnvelope($changed));
                self::fail('Tampered envelope field was accepted.');
            } catch (DecryptException $exception) {
                self::assertStringNotContainsString('SQUEHUB_CRYPT_SECRET_DO_NOT_LEAK', $exception->getMessage());
            }
        }
    }

    public function testMalformedEnvelopeAndBoundsFailSafely(): void
    {
        $crypt = self::manager(maximum: 10);
        foreach (['', 'shc1.***', 'shc1.' . str_repeat('a', 5000),
            self::encodeEnvelope(['v' => 1]),
            self::encodeEnvelope(['v' => 2, 'alg' => 'aes-256-gcm', 'kid' => 'primary',
                'n' => '', 'ct' => '', 'tag' => ''])] as $payload) {
            try { $crypt->decrypt($payload); self::fail('Malformed envelope accepted.'); }
            catch (DecryptException) { self::assertTrue(true); }
        }
        $this->expectException(CryptException::class);
        $crypt->encrypt(str_repeat('a', 11));
    }

    public function testKeyRotationAndOldBackendDecryption(): void
    {
        $old = self::manager('openssl', 'v1', ['v1' => self::key('a')]);
        $oldPayload = $old->encrypt('old');
        $new = self::manager('auto', 'v2', ['v2' => self::key('b'), 'v1' => self::key('a')]);
        self::assertSame('old', $new->decrypt($oldPayload));
        self::assertSame('v2', self::decodeEnvelope($new->encrypt('new'))['kid']);
        try { self::manager('auto', 'v2', ['v2' => self::key('b')])->decrypt($oldPayload);
            self::fail('Removed previous key was accepted.');
        } catch (DecryptException $exception) {
            self::assertStringNotContainsString('v1', $exception->getMessage());
        }
    }

    public function testSigningUsesKeyedMacPurposeAndConstantTimeComparison(): void
    {
        $crypt = self::manager();
        $message = "binary\x00\xff";
        $signature = $crypt->sign($message, 'webhook.internal');
        self::assertStringStartsWith('shs1.', $signature);
        self::assertTrue($crypt->verify($message, $signature, 'webhook.internal'));
        self::assertFalse($crypt->verify($message . 'x', $signature, 'webhook.internal'));
        self::assertFalse($crypt->verify($message, $signature, 'other'));
        self::assertFalse($crypt->verify($message, $signature . 'a', 'webhook.internal'));
        self::assertFalse(self::manager(keys: ['primary' => self::key('b')])
            ->verify($message, $signature, 'webhook.internal'));
        self::assertFalse($crypt->verify($message, 'invalid'));
    }

    public function testSecureTokenReusesAccountEncodingAndKeyGeneration(): void
    {
        $crypt = self::manager();
        $tokens = [];
        for ($i = 0; $i < 32; ++$i) {
            $token = $crypt->randomToken();
            self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $token);
            $tokens[$token] = true;
        }
        self::assertCount(32, $tokens);
        self::assertTrue(SecurityTokenGenerator::valid((new SecurityTokenGenerator())->generate()));
        self::assertMatchesRegularExpression('~\Abase64:[A-Za-z0-9+/]{43}=\z~D', SecureRandom::applicationKey());
        $this->expectException(CryptException::class);
        $crypt->randomToken(15);
    }

    public function testStrictKeyConfigurationAndDebugPrivacy(): void
    {
        $secret = self::key('z');
        $crypt = self::manager(keys: ['primary' => $secret]);
        ob_start();
        var_dump($crypt);
        self::assertStringNotContainsString($secret, (string) ob_get_clean());
        self::assertStringNotContainsString($secret, print_r($crypt, true));
        self::assertStringNotContainsString($secret, json_encode($crypt));
        foreach (['', 'human-password', 'base64:wrong'] as $invalid) {
            try { self::manager(keys: ['primary' => $invalid])->encrypt('data');
                self::fail('Invalid key accepted.');
            } catch (CryptConfigurationException $exception) {
                if ($invalid !== '') self::assertStringNotContainsString($invalid, $exception->getMessage());
            }
        }
        self::assertTrue(SensitiveKey::matches('APP_KEY'));
        self::assertTrue(SensitiveKey::matches('APP_PREVIOUS_KEY'));
        self::assertTrue(SensitiveKey::matches('signing_key'));
        $redactor = new SecretRedactor(new Repository(['crypt' => ['keys' => ['primary' => $secret]]]));
        self::assertStringNotContainsString($secret, $redactor->redact('key=' . $secret));
    }

    public function testAggregateDiagnosticsExcludeSensitiveInputsAndReset(): void
    {
        $secret = 'SQUEHUB_CRYPT_SECRET_DO_NOT_LEAK';
        $diagnostics = new Diagnostics(new Repository([]));
        $diagnostics->begin(new Request('GET', '/'));
        $crypt = self::manager(diagnostics: $diagnostics);
        $payload = $crypt->encrypt($secret, 'private.purpose');
        self::assertSame($secret, $crypt->decrypt($payload, 'private.purpose'));
        $signature = $crypt->sign($secret);
        self::assertTrue($crypt->verify($secret, $signature));
        self::assertFalse($crypt->verify('wrong', $signature));
        try { $crypt->decrypt($payload, 'wrong.purpose'); self::fail('Wrong purpose accepted.'); }
        catch (DecryptException) {}
        $metrics = $diagnostics->snapshot()['crypt'];
        self::assertSame(['encryptions' => 1, 'decryptions' => 1, 'signatures' => 1,
            'verifications' => 2, 'failures' => 1], array_diff_key($metrics, ['time_ms' => true]));
        self::assertGreaterThanOrEqual(0, $metrics['time_ms']);
        self::assertStringNotContainsString($secret, json_encode($diagnostics->snapshot()));
        self::assertStringNotContainsString($payload, json_encode($diagnostics->snapshot()));
        $diagnostics->begin(new Request('GET', '/next'));
        self::assertSame(0, $diagnostics->snapshot()['crypt']['encryptions']);
    }
}
