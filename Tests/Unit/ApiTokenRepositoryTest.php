<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\IdentityProvider;
use App\Auth\Identity\ModelIdentityProvider;
use App\Auth\Tokens\Repositories\ArrayTokenRepository;
use App\Auth\Tokens\Repositories\DatabaseTokenRepository;
use App\Auth\Tokens\TokenException;
use App\Auth\Tokens\TokenFormat;
use App\Auth\Tokens\TokenManager;
use App\Auth\Tokens\TokenRecord;
use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Database;
use App\Database\Model;
use App\Database\ModelClock;
use App\Database\Schema\Table;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

/** PAT repository contracts run against isolated memory and real disposable SQLite. */
final class ApiTokenRepositoryTest extends TestCase
{
    private static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /** @return array{TokenManager,TokenTestIdentity,TokenTestClock,ArrayTokenRepository} */
    private function arrayManager(array $settings = []): array
    {
        $identity = new TokenTestIdentity(42);
        $clock = new TokenTestClock(self::utc('2030-01-01 00:00:00'));
        $repository = new ArrayTokenRepository();
        $manager = new TokenManager('api', new TokenTestProvider([$identity]),
            $repository, $clock, $settings);
        return [$manager, $identity, $clock, $repository];
    }

    public function testFormatUsesOpaqueIndexedIdentifierAndIndependentSecret(): void
    {
        $first = TokenFormat::generate();
        $second = TokenFormat::generate();
        self::assertSame(68, strlen($first['token']));
        self::assertSame(16, strlen($first['identifier']));
        self::assertSame(64, strlen($first['hash']));
        self::assertSame(['identifier' => $first['identifier'], 'hash' => $first['hash']],
            TokenFormat::parse($first['token']));
        self::assertNotSame($first['identifier'], $second['identifier']);
        self::assertNotSame($first['hash'], $second['hash']);
        foreach (['', 'Bearer ' . $first['token'], $first['token'] . ' ', 'sqh_pat_' . str_repeat('x', 16),
            str_replace('sqh_pat_', 'other___', $first['token'])] as $invalid) {
            self::assertNull(TokenFormat::parse($invalid));
        }
    }

    public function testSettingsRejectUnsafeOrAmbiguousLifetimes(): void
    {
        $settings = TokenManager::settings([]);
        self::assertSame('database', $settings['driver']);
        self::assertSame('api_tokens', $settings['table']);
        self::assertSame(2592000, $settings['default_ttl']);
        self::assertNull(TokenManager::settings(['allow_non_expiring' => true,
            'default_ttl' => null])['default_ttl']);
        foreach ([['driver' => 'redis'], ['table' => '../tokens'], ['connection' => 'a/b'],
            ['default_ttl' => null], ['default_ttl' => 0], ['max_ttl' => 0],
            ['default_ttl' => 100, 'max_ttl' => 99], ['allow_non_expiring' => 'yes'],
            ['last_used_interval' => -1], ['unknown' => true]] as $bad) {
            try {
                TokenManager::settings($bad);
                self::fail('Invalid token configuration was accepted.');
            } catch (TokenException) {
                self::assertTrue(true);
            }
        }
    }

    public function testArrayIssueAuthenticateAbilitiesAndOneTimeSecretBoundary(): void
    {
        [$manager, $identity, , $repository] = $this->arrayManager();
        $issued = $manager->issue($identity, 'Laptop', ['orders.read']);
        $raw = $issued->token();
        $metadata = $issued->metadata();
        $record = $repository->find($metadata->identifier());
        self::assertNotNull($record);
        self::assertSame(TokenFormat::parse($raw)['hash'], $record->tokenHash);
        self::assertStringNotContainsString($raw, json_encode($record, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($record->tokenHash, json_encode($record, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($raw, var_export($issued->__debugInfo(), true));
        try {
            serialize($issued);
            self::fail('Issued token serialization should be blocked.');
        } catch (TokenException $failure) {
            self::assertStringNotContainsString($raw, $failure->getMessage());
        }
        try {
            serialize($record);
            self::fail('Internal record serialization should be blocked.');
        } catch (TokenException $failure) {
            self::assertStringNotContainsString($record->tokenHash, $failure->getMessage());
        }
        self::assertTrue($metadata->allows('orders.read'));
        self::assertFalse($metadata->allows('orders.write'));
        self::assertFalse($metadata->allows(str_repeat('x', 129)));
        self::assertFalse($metadata->allows('bad/ability'));
        $authenticated = $manager->authenticate($raw);
        self::assertNotNull($authenticated);
        self::assertSame($identity, $authenticated->identity());
        self::assertSame($metadata->identifier(), $authenticated->metadata()->identifier());
        self::assertNotNull($authenticated->metadata()->lastUsedAt());
        self::assertNull($manager->authenticate($raw . 'x'));
        self::assertNull($manager->authenticate('sqh_pat_' . str_repeat('x', 16)
            . '_' . str_repeat('x', 43)));
        $last = substr($raw, -1);
        self::assertNull($manager->authenticate(substr($raw, 0, -1) . ($last === 'A' ? 'B' : 'A')));
        self::assertCount(1, $manager->listFor($identity));
    }

    public function testArrayExpiryRevocationRotationPruningAndIsolation(): void
    {
        [$manager, $identity, $clock, $repository] = $this->arrayManager([
            'default_ttl' => 60, 'max_ttl' => 120, 'prune_retention' => 0,
        ]);
        $first = $manager->issue($identity, 'First', ['read']);
        self::assertSame('2030-01-01 00:01:00', $first->metadata()->expiresAt()?->format('Y-m-d H:i:s'));
        $rotated = $manager->rotate($first->metadata()->identifier());
        self::assertNotSame($first->token(), $rotated->token());
        self::assertNull($manager->authenticate($first->token()));
        self::assertNotNull($manager->authenticate($rotated->token()));
        self::assertSame('First', $rotated->metadata()->name());
        self::assertSame(['read'], $rotated->metadata()->abilities());
        self::assertSame($first->metadata()->expiresAt(), $rotated->metadata()->expiresAt());
        $foreign = TokenFormat::generate();
        $repository->insert(new TokenRecord($foreign['identifier'], $foreign['hash'], 'other',
            42, 'Other guard', [], $clock->now(), $clock->now()));
        self::assertSame(1, $manager->prune()); // The revoked predecessor is eligible now.
        self::assertNotNull($repository->find($foreign['identifier']));
        self::assertTrue($manager->revoke($rotated->metadata()->identifier()));
        self::assertFalse($manager->revoke($rotated->metadata()->identifier()));
        self::assertNull($manager->authenticate($rotated->token()));
        self::assertSame(1, $manager->prune());
        self::assertSame([], $manager->listFor($identity));
        $expiring = $manager->issue($identity, 'Second');
        $clock->at = self::utc('2030-01-01 00:01:00');
        self::assertNull($manager->authenticate($expiring->token()));
        self::assertSame(1, $manager->prune());
        self::assertNotNull($repository->find($foreign['identifier']));

        $other = new TokenManager('api', new TokenTestProvider([$identity]),
            new ArrayTokenRepository(), $clock);
        self::assertNull($other->authenticate($expiring->token()));
        self::assertSame([], $other->listFor($identity));
    }

    public function testLastUsedWritesAreThrottledAndDeletedIdentityCannotAuthenticate(): void
    {
        $identity = new TokenTestIdentity(42);
        $provider = new TokenTestProvider([$identity]);
        $clock = new TokenTestClock(self::utc('2030-01-01 00:00:00'));
        $repository = new ArrayTokenRepository();
        $manager = new TokenManager('api', $provider, $repository, $clock,
            ['last_used_interval' => 300]);
        $issued = $manager->issue($identity, 'Phone');
        self::assertNotNull($manager->authenticate($issued->token()));
        $identifier = $issued->metadata()->identifier();
        $firstUse = $repository->find($identifier)?->lastUsedAt;
        $clock->at = self::utc('2030-01-01 00:04:59');
        self::assertNotNull($manager->authenticate($issued->token()));
        self::assertSame($firstUse, $repository->find($identifier)?->lastUsedAt);
        $clock->at = self::utc('2030-01-01 00:05:00');
        self::assertNotNull($manager->authenticate($issued->token()));
        self::assertEquals($clock->at, $repository->find($identifier)?->lastUsedAt);
        $provider->remove($identity);
        self::assertNull($manager->authenticate($issued->token()));
    }

    public function testNameAbilityAndExpiryValidationNeverPersistFailedIssue(): void
    {
        [$manager, $identity, , $repository] = $this->arrayManager();
        foreach ([['', ['read'], null], ['bad' . "\n" . 'name', ['read'], null],
            ['good', ['bad/ability'], null], ['good', [123], null],
            ['good', ['read'], self::utc('2030-01-01 00:00:00')],
            ['good', ['read'], self::utc('2032-01-01 00:00:00')]] as [$name, $abilities, $expires]) {
            try {
                $manager->issue($identity, $name, $abilities, $expires);
                self::fail('Invalid issuance was accepted.');
            } catch (TokenException) {
                self::assertTrue(true);
            }
        }
        self::assertSame([], $repository->listFor('api', 42));
        $denyAll = $manager->issue($identity, 'Deny all', []);
        self::assertFalse($denyAll->metadata()->allows('read'));
        $wildcard = $manager->issue($identity, 'Wildcard');
        self::assertTrue($wildcard->metadata()->allows('read'));
    }

    public function testNonExpiringPolicyIsExplicitAndGuardNameIsBounded(): void
    {
        [$manager, $identity, $clock] = $this->arrayManager([
            'allow_non_expiring' => true, 'default_ttl' => null,
        ]);
        $issued = $manager->issue($identity, 'Long-lived');
        self::assertNull($issued->metadata()->expiresAt());
        $clock->at = self::utc('2040-01-01 00:00:00');
        self::assertNotNull($manager->authenticate($issued->token()));
        self::assertSame(0, $manager->prune());
        $this->expectException(TokenException::class);
        new TokenManager(str_repeat('a', 65), new TokenTestProvider([$identity]),
            new ArrayTokenRepository(), $clock);
    }

    public function testDatabaseMigrationAndPersistenceUseHashOnlyRecords(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        $database = new DatabaseManager(new Repository(['database' => ['default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']]]]));
        $connection = $database->connection();
        self::assertFalse($connection->isConnected());
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_26_create_api_tokens.php';
        $migration = new \CreateApiTokens();
        $migration->up($connection->pdo(), $connection->schema());
        self::assertTrue($connection->schema()->hasTable('api_tokens'));
        $identity = new TokenTestIdentity('user-uuid');
        $provider = new TokenTestProvider([$identity]);
        $clock = new TokenTestClock(self::utc('2030-01-01 00:00:00'));
        $repository = new DatabaseTokenRepository($database, 'api_tokens', 'test');
        $manager = new TokenManager('api', $provider, $repository, $clock,
            ['default_ttl' => 60, 'prune_retention' => 0]);
        $issued = $manager->issue($identity, 'Phone', ['profile.read']);
        $row = $database->table('api_tokens', 'test')->first();
        self::assertNotNull($row);
        self::assertSame('s', $row['identity_kind']);
        self::assertSame('user-uuid', $row['identity_identifier']);
        self::assertSame('api', $row['guard']);
        self::assertStringNotContainsString($issued->token(), json_encode($row, JSON_THROW_ON_ERROR));
        self::assertNotSame($issued->token(), $row['token_hash']);
        self::assertNotNull($manager->authenticate($issued->token()));
        self::assertSame('2030-01-01 00:00:00', $database->table('api_tokens', 'test')
            ->first()['last_used_at']);
        self::assertCount(1, $manager->listFor($identity));
        $replacement = $manager->rotate($issued->metadata()->identifier());
        self::assertNull($manager->authenticate($issued->token()));
        self::assertNotNull($manager->authenticate($replacement->token()));
        self::assertSame(1, $manager->revokeAll($identity));
        self::assertNull($manager->authenticate($replacement->token()));
        $foreign = TokenFormat::generate();
        $repository->insert(new TokenRecord($foreign['identifier'], $foreign['hash'], 'other',
            'user-uuid', 'Other guard', [], $clock->now(), $clock->now()));
        self::assertSame(2, $manager->prune());
        self::assertNotNull($repository->find($foreign['identifier']));
        self::assertSame([], $manager->listFor($identity));
        $migration->down($connection->pdo(), $connection->schema());
        self::assertFalse($connection->schema()->hasTable('api_tokens'));
    }

    public function testCorruptAbilitiesFailClosedAndDatabaseRotationRollsBack(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        $database = new DatabaseManager(new Repository(['database' => ['default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']]]]));
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_26_create_api_tokens.php';
        (new \CreateApiTokens())->up($database->connection()->pdo(), $database->schema());
        $identity = new TokenTestIdentity(7);
        $clock = new TokenTestClock(self::utc('2030-01-01 00:00:00'));
        $repository = new DatabaseTokenRepository($database);
        $manager = new TokenManager('api', new TokenTestProvider([$identity]), $repository, $clock);
        $old = $manager->issue($identity, 'Old');
        $other = $manager->issue($identity, 'Other');
        $existing = $repository->find($other->metadata()->identifier());
        self::assertNotNull($existing);
        $replacement = new TokenRecord($existing->identifier, hash('sha256', 'new-secret'), 'api',
            7, 'Replacement', ['*'], $clock->now(), null);
        try {
            $repository->rotate('api', $old->metadata()->identifier(), $replacement, $clock->now());
            self::fail('Duplicate replacement identifier should fail.');
        } catch (\Throwable) {
            self::assertNotNull($manager->authenticate($old->token())); // Old revocation rolled back.
        }
        $database->table('api_tokens')->filter('identifier', $old->metadata()->identifier())
            ->update(['abilities' => '{bad-json']);
        try {
            $manager->authenticate($old->token());
            self::fail('Corrupt abilities should fail closed.');
        } catch (TokenException $failure) {
            self::assertStringNotContainsString($old->token(), $failure->getMessage());
        }
    }

    public function testGeneratedModelIdStringMatchesHydratedIntegerWithoutMergingPaddedKey(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        $database = new DatabaseManager(new Repository(['database' => ['default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']]]]));
        Database::setResolver(static fn (): DatabaseManager => $database);
        try {
            $database->schema()->create('api_token_users', static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('password');
            });
            require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_26_create_api_tokens.php';
            (new \CreateApiTokens())->up($database->connection()->pdo(), $database->schema());
            $created = ApiTokenModelIdentity::create(['email' => 'model@example.test',
                'password' => 'stored-hash']);
            self::assertIsString($created->authIdentifier());
            $provider = new ModelIdentityProvider(ApiTokenModelIdentity::class, 'id', 'password', ['email']);
            $repository = new DatabaseTokenRepository($database);
            $clock = new TokenTestClock(self::utc('2030-01-01 00:00:00'));
            $manager = new TokenManager('api', $provider, $repository, $clock);
            $issued = $manager->issue($created, 'Model token');
            $hydrated = ApiTokenModelIdentity::find((int) $created->authIdentifier());
            self::assertNotNull($hydrated);
            self::assertIsInt($hydrated->authIdentifier());
            self::assertCount(1, $manager->listFor($hydrated));
            self::assertCount(1, $repository->listFor('api', (int) $created->authIdentifier()));
            self::assertNotNull($manager->authenticate($issued->token()));
            self::assertSame(1, $manager->revokeAll($hydrated));
            self::assertNull($manager->authenticate($issued->token()));

            $padded = TokenFormat::generate();
            $repository->insert(new TokenRecord($padded['identifier'], $padded['hash'], 'api',
                '007', 'Padded string', [], $clock->now(), null));
            self::assertCount(0, $repository->listFor('api', 7));
            self::assertCount(1, $repository->listFor('api', '007'));
        } finally {
            Database::setResolver(null);
        }
    }
}

/** A controlled clock keeps expiry, last-use, and prune assertions exact. */
final class TokenTestClock implements ModelClock
{
    public function __construct(public DateTimeImmutable $at)
    {
    }

    public function now(): DateTimeImmutable { return $this->at; }
}

/** Minimal identity with no Session dependency for direct token-service tests. */
final readonly class TokenTestIdentity implements Authenticatable
{
    public function __construct(private int|string $identifier)
    {
    }

    public function authIdentifier(): int|string { return $this->identifier; }
    public function authPasswordHash(): string { return 'unused'; }
}

/** Exact in-memory identity lookup mirrors the existing provider boundary. */
final class TokenTestProvider implements IdentityProvider
{
    /** @var array<string, TokenTestIdentity> */
    private array $identities = [];

    /** @param list<TokenTestIdentity> $identities */
    public function __construct(array $identities)
    {
        foreach ($identities as $identity) $this->identities[(string) $identity->authIdentifier()] = $identity;
    }

    public function retrieveById(int|string $identifier): ?Authenticatable
    {
        return $this->identities[(string) $identifier] ?? null;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable { return null; }
    public function updatePassword(Authenticatable $identity, string $passwordHash): void {}
    public function supports(Authenticatable $identity): bool
    {
        return $identity instanceof TokenTestIdentity
            && ($this->identities[(string) $identity->authIdentifier()] ?? null) === $identity;
    }

    public function remove(TokenTestIdentity $identity): void
    {
        unset($this->identities[(string) $identity->authIdentifier()]);
    }
}

/** Generated IDs begin as strings, then hydrate as integers through PDO. */
final class ApiTokenModelIdentity extends Model implements Authenticatable
{
    protected string $table = 'api_token_users';
    protected array $fillable = ['email', 'password'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}
