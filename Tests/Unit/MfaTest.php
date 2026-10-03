<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Container\Container;
use App\Logging\LogContextNormalizer;
use App\Mfa\MfaConfigurationException;
use App\Mfa\MfaEnrollment;
use App\Mfa\MfaManager;
use App\Mfa\Repositories\MfaIdentity;
use App\Mfa\Totp;
use App\Support\SecretRedactor;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Protocol vectors and privacy rules need no database or Application boot. */
final class MfaTest extends TestCase
{
    public function testRfc6238Sha1VectorsTruncateToSixDigits(): void
    {
        $secret = Totp::encodeSecret('12345678901234567890');
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        foreach ([
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            20000000000 => '353130',
        ] as $timestamp => $expected) {
            $counter = intdiv($timestamp, 30);
            self::assertSame($expected, Totp::at($secret, $counter));
            self::assertSame($counter, Totp::match($secret, $expected, $timestamp, 0));
        }
    }

    public function testTotpRequiresCanonicalSecretAndExactSixDigitProof(): void
    {
        $secret = Totp::generateSecret();
        self::assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/D', $secret);
        self::assertNull(Totp::match($secret, '12345', 59));
        self::assertNull(Totp::match($secret, '123456 ', 59));
        self::assertNull(Totp::match($secret, '１２３４５６', 59));

        $vector = Totp::encodeSecret('12345678901234567890');
        self::assertSame(1, Totp::match($vector, '287082', 30, 1));
        self::assertNull(Totp::match($vector, '287082', 120, 1));
        foreach ([strtolower($vector), $vector . '=', $vector . 'A', 'A', 'MZ'] as $invalid) {
            try {
                Totp::at($invalid, 1);
                self::fail('A noncanonical secret was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Totp::generateSecret(15);
    }

    public function testProvisioningValueRedactsDebugOutputAndRejectsSerialization(): void
    {
        $value = new MfaEnrollment('TOPSECRET', 'otpauth://totp/TOPSECRET', 123);
        self::assertSame('TOPSECRET', $value->secret());
        self::assertSame('otpauth://totp/TOPSECRET', $value->provisioningUri());
        $debug = var_export($value->__debugInfo(), true);
        self::assertStringNotContainsString('TOPSECRET', $debug);
        $this->expectException(LogicException::class);
        serialize($value);
    }

    public function testIdentityScopeIncludesGuardClassAndTypedIdentifier(): void
    {
        $one = MfaIdentity::fromIdentifier('web', 'Example\\User', 7);
        self::assertTrue($one->sameAs(MfaIdentity::fromIdentifier('web', 'Example\\User', '7')));
        foreach ([
            MfaIdentity::fromIdentifier('admin', 'Example\\User', 7),
            MfaIdentity::fromIdentifier('web', 'Other\\User', 7),
            MfaIdentity::fromIdentifier('web', 'Example\\User', '007'),
        ] as $other) {
            self::assertFalse($one->sameAs($other));
            self::assertNotSame($one->digest, $other->digest);
        }
    }

    public function testMfaProofsAndProvisioningValuesAreRedactedFromLogs(): void
    {
        $normalizer = new LogContextNormalizer(new SecretRedactor(new Repository()));
        $totp = '287082';
        $recovery = 'ABCD-1234-EF56-7890-ABCD-1234-EF56-7890';
        $uri = 'otpauth://totp/SqueHub%20Test:Ada?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $context = ['nested' => [
            'totp_code' => $totp,
            'recovery_code' => $recovery,
            'mfa_proof' => $recovery,
            'provisioning_uri' => $uri,
            'code' => 'ordinary-status',
            'note' => 'A user copied ' . $recovery,
        ]];
        $data = $normalizer->normalize($context);
        foreach (['totp_code', 'recovery_code', 'mfa_proof', 'provisioning_uri'] as $key) {
            self::assertSame('[REDACTED]', $data['nested'][$key]);
        }
        self::assertSame('ordinary-status', $data['nested']['code']);
        $message = $normalizer->message('proof ' . $totp . ' recovery ' . $recovery
            . ' provisioning ' . $uri, $context);
        $freeForm = $normalizer->message('copied ' . $recovery . ' and ' . $uri, []);
        $serialized = json_encode([$data, $message, $freeForm], JSON_THROW_ON_ERROR);
        foreach ([$totp, $recovery, $uri] as $private) {
            self::assertStringNotContainsString($private, $serialized);
        }
        self::assertStringContainsString('[REDACTED]', $freeForm);
    }

    public function testMalformedMfaConfigurationFailsBeforeStorageUse(): void
    {
        foreach ([
            ['driver' => 'redis'],
            ['enabled' => 'true'],
            ['credentials_table' => 'bad;name'],
            ['recovery_table' => str_repeat('x', 65)],
            ['issuer' => "bad\nissuer"],
            ['issuer' => 'ambiguous:issuer'],
            ['enrollment_ttl' => 29],
            ['challenge_ttl' => 901],
            ['skew' => 2],
            ['recovery_count' => 21],
            ['max_attempts' => 0],
            ['attempt_window' => '300'],
        ] as $setting) {
            $container = new Container();
            $container->instance(Repository::class, new Repository(['security' => ['mfa' => $setting]]));
            try {
                new MfaManager($container);
                self::fail('Malformed MFA configuration was accepted.');
            } catch (MfaConfigurationException) {
                self::assertTrue(true);
            }
        }
    }
}
