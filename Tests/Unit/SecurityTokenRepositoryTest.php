<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\AccountSecurity\CredentialFingerprint;
use App\AccountSecurity\Repositories\ArraySecurityTokenRepository;
use App\AccountSecurity\SecurityTokenGenerator;
use App\AccountSecurity\SecurityTokenRecord;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** Hash-only records and array claims exercise one-time token semantics. */
final class SecurityTokenRepositoryTest extends TestCase
{
    private function record(string $hash, string $purpose = 'password_reset', string $guard = 'web',
        string $identity = 'i:1', string $expires = '2030-01-01 00:01:00'): SecurityTokenRecord
    {
        $zone = new DateTimeZone('UTC');
        return new SecurityTokenRecord($hash, $purpose, $guard, $identity, str_repeat('a', 64),
            new DateTimeImmutable($expires, $zone), new DateTimeImmutable('2030-01-01 00:00:00', $zone));
    }

    public function testRandomTokenShapeAndFingerprintPrivacy(): void
    {
        $generator = new SecurityTokenGenerator();
        $first = $generator->generate();
        $second = $generator->generate();
        self::assertSame(43, strlen($first));
        self::assertTrue(SecurityTokenGenerator::valid($first));
        self::assertNotSame($first, $second);
        self::assertFalse(SecurityTokenGenerator::valid(str_repeat('x', 257)));
        self::assertSame(64, strlen(CredentialFingerprint::fromHash('stored hash')));
        self::assertNotSame('stored hash', CredentialFingerprint::fromHash('stored hash'));
    }

    public function testArrayRepositoryReplacementIsolationExpiryAndSingleClaim(): void
    {
        $repository = new ArraySecurityTokenRepository();
        $now = new DateTimeImmutable('2030-01-01 00:00:10', new DateTimeZone('UTC'));
        $repository->replace($this->record('one'));
        $repository->replace($this->record('other', 'email_verification'));
        $repository->replace($this->record('admin', 'password_reset', 'admin'));
        $repository->replace($this->record('user2', 'password_reset', 'web', 'i:2'));
        $repository->replace($this->record('two')); // Replaces only web/i:1/reset.
        self::assertNull($repository->claim('one', 'password_reset', 'web', $now));
        self::assertNull($repository->claim('two', 'email_verification', 'web', $now));
        self::assertNotNull($repository->claim('two', 'password_reset', 'web', $now));
        self::assertNull($repository->claim('two', 'password_reset', 'web', $now));
        $repository->revoke('password_reset', 'admin', 'i:1');
        self::assertNull($repository->claim('admin', 'password_reset', 'admin', $now));
        self::assertNotNull($repository->claim('other', 'email_verification', 'web', $now));
        self::assertNotNull($repository->claim('user2', 'password_reset', 'web', $now));
        $repository->replace($this->record('expired', expires: '2030-01-01 00:00:10'));
        self::assertNull($repository->claim('expired', 'password_reset', 'web', $now));
        self::assertNull($repository->claim('expired', 'password_reset', 'web', $now));
    }

    public function testIdentityKeyRetainsIdentifierType(): void
    {
        self::assertSame(42, $this->record('x', identity: SecurityTokenRecord::key(42))->identifier());
        self::assertSame('0042', $this->record('x', identity: SecurityTokenRecord::key('0042'))->identifier());
    }
}
