<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Support\SecretRedactor;
use App\Support\SensitiveKey;
use PHPUnit\Framework\TestCase;

/** Provider credentials follow the shared logging and diagnostics redaction policy. */
final class ProviderSecretRedactionTest extends TestCase
{
    public function testConfiguredProviderCredentialsAreRedactedFromErrorText(): void
    {
        $values = ['S3_ACCESS_PRIVATE_10', 'S3_SECRET_PRIVATE_20',
            'S3_SESSION_PRIVATE_30', 'RESEND_PRIVATE_40', 'POSTMARK_PRIVATE_50',
            'MEMCACHED_PRIVATE_60'];
        $config = new Repository([
            'storage' => ['drives' => ['s3' => [
                'access_key' => $values[0], 'secret_key' => $values[1],
                'session_token' => $values[2],
            ]]],
            'mail' => ['transports' => [
                'resend' => ['api_key' => $values[3]],
                'postmark' => ['api_key' => $values[4]],
            ]],
            'cache' => ['memcached' => ['password' => $values[5]]],
        ]);
        $result = (new SecretRedactor($config))->redact(implode(' ', $values));
        foreach ($values as $value) {
            self::assertStringNotContainsString($value, $result);
        }
        self::assertSame(str_repeat('[REDACTED] ', count($values) - 1) . '[REDACTED]', $result);
    }

    public function testProviderCredentialKeysAreSensitiveWithoutBlanketValueRedaction(): void
    {
        foreach (['access_key', 'secret_key', 'session_token', 'api_key',
            'server_token', 'provider_token', 'memcached_password'] as $key) {
            self::assertTrue(SensitiveKey::matches($key), $key);
        }
        foreach (['storage_path', 'bucket', 'region', 'mail_driver', 'cache_driver'] as $key) {
            self::assertFalse(SensitiveKey::matches($key), $key);
        }
    }
}
