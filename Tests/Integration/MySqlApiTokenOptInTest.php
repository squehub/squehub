<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\IdentityProvider;
use App\Auth\Tokens\Repositories\DatabaseTokenRepository;
use App\Auth\Tokens\TokenException;
use App\Auth\Tokens\TokenFormat;
use App\Auth\Tokens\TokenManager;
use App\Auth\Tokens\TokenRecord;
use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Exception\QueryException;
use App\Database\ModelClock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Phase 12D token migration and repository on a real disposable
 * MySQL database. The explicit opt-in, empty-schema check, and shared advisory
 * lock are required before this test may create or remove any table.
 *
 * @group mysql
 */
final class MySqlApiTokenOptInTest extends TestCase
{
    public function testMigrationAndTokenLifecycleOnConfirmedDisposableMySql(): void
    {
        if (getenv('SQUEHUB_TEST_MYSQL_ENABLED') !== '1') {
            self::markTestSkipped('Set SQUEHUB_TEST_MYSQL_ENABLED=1 and explicit disposable test database settings.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_mysql is required for the opt-in MySQL integration test.');
        }

        $host = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_HOST');
        $port = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_PORT');
        $database = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_DATABASE');
        $user = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_USER');
        $password = getenv('SQUEHUB_TEST_MYSQL_PASSWORD');
        $confirmation = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_CONFIRM_DATABASE');
        self::assertNotFalse($password, 'Set SQUEHUB_TEST_MYSQL_PASSWORD explicitly, even when empty.');
        self::assertMatchesRegularExpression('/\Asquehub_test_[A-Za-z0-9_]+\z/D', $database);
        self::assertSame($database, $confirmation, 'Test database confirmation must match exactly.');

        // This manager is built only from the opt-in values; application .env
        // and the configured default database are never consulted.
        $manager = new DatabaseManager(new Repository([
            'database' => [
                'default' => 'disposable',
                'connections' => [
                    'disposable' => [
                        'driver' => 'mysql',
                        'host' => $host,
                        'port' => $port,
                        'database' => $database,
                        'username' => $user,
                        'password' => $password,
                        'charset' => 'utf8mb4',
                    ],
                ],
            ],
        ]));
        $connection = $manager->connection();
        $pdo = $connection->pdo();
        self::assertSame($database, $pdo->query('SELECT DATABASE()')->fetchColumn());
        $version = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        $comment = strtolower((string) $pdo->query('SELECT @@version_comment')->fetchColumn());
        self::assertStringNotContainsString('mariadb', $version . $comment, 'This test verifies MySQL semantics.');
        self::assertSame('innodb', strtolower((string) $pdo->query('SELECT @@default_storage_engine')->fetchColumn()));

        // Share Phase 6A's lock name: both opt-in suites require the same empty
        // schema, so two cooperating test processes must never enter together.
        $lockName = 'squehub_phase6a_' . substr(hash('sha256', $database), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        self::assertSame(1, (int) $lock->fetchColumn(), 'Another SqueHub MySQL test is using this database.');
        try {
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM),
                'The confirmed test database must be empty before this test may create tables.');
            fwrite(STDERR, "Opt-in MySQL API-token server: {$version} ({$comment}), default engine InnoDB.\n");

            try {
                require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_26_create_api_tokens.php';
                $migration = new \CreateApiTokens();
                $schema = $connection->schema();
                $migration->up($pdo, $schema);
                self::assertTrue($schema->hasTable('api_tokens'));
                foreach (['identifier', 'token_hash', 'guard', 'identity_kind',
                    'identity_identifier', 'name', 'abilities', 'created_at',
                    'expires_at', 'last_used_at', 'revoked_at'] as $column) {
                    self::assertTrue($schema->hasColumn('api_tokens', $column), $column);
                }
                $this->assertStorageIndexes($pdo);
                $this->assertTokenLifecycle($manager);
            } finally {
                // Emptiness under the advisory lock establishes ownership of
                // this one table even if MySQL DDL partially succeeds.
                $pdo->exec('DROP TABLE IF EXISTS `api_tokens`');
            }
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (\Throwable $releaseException) {
                // Closing PDO releases its lock. A secondary cleanup failure
                // must not hide the original schema or token failure.
            }
        }
    }

    private function assertStorageIndexes(PDO $pdo): void
    {
        $engine = $pdo->prepare('SELECT ENGINE AS engine FROM INFORMATION_SCHEMA.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $engine->execute(['api_tokens']);
        self::assertSame('innodb', strtolower((string) $engine->fetchColumn()));

        // MySQL can report INFORMATION_SCHEMA column labels in server-specific
        // casing. Explicit aliases keep the fetched keys stable for assertions.
        $statement = $pdo->prepare('SELECT INDEX_NAME AS index_name, '
            . 'NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq_in_index, '
            . 'COLUMN_NAME AS column_name FROM INFORMATION_SCHEMA.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $statement->execute(['api_tokens']);
        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['index_name'];
            $indexes[$name]['unique'] = (int) $row['non_unique'] === 0;
            $indexes[$name]['columns'][] = (string) $row['column_name'];
        }
        foreach ([
            'api_tokens_identifier' => [true, ['identifier']],
            'api_tokens_hash' => [true, ['token_hash']],
            'api_tokens_identity' => [false, ['guard', 'identity_kind', 'identity_identifier']],
            'api_tokens_expires' => [false, ['expires_at']],
            'api_tokens_revoked' => [false, ['revoked_at']],
        ] as $name => [$unique, $columns]) {
            self::assertArrayHasKey($name, $indexes);
            self::assertSame($unique, $indexes[$name]['unique'], $name);
            self::assertSame($columns, $indexes[$name]['columns'], $name);
        }
    }

    private function assertTokenLifecycle(DatabaseManager $database): void
    {
        $identity = new MySqlTokenIdentity('user-uuid');
        $clock = new MySqlTokenClock(self::utc('2030-01-01 00:00:00'));
        $repository = new DatabaseTokenRepository($database, 'api_tokens', 'disposable');
        $provider = new MySqlTokenIdentityProvider($identity);
        $tokens = new TokenManager('api', $provider, $repository, $clock, [
            'default_ttl' => 60, 'max_ttl' => 3600,
            'last_used_interval' => 300, 'prune_retention' => 0,
        ]);
        $rows = fn () => $database->table('api_tokens', 'disposable');

        $issued = $tokens->issue($identity, 'CLI access', ['orders.read']);
        $raw = $issued->token();
        $id = $issued->metadata()->identifier();
        self::assertStringStartsWith('sqh_pat_', $raw);
        $row = $rows()->filter('identifier', $id)->first();
        self::assertNotNull($row);
        self::assertSame('api', $row['guard']);
        self::assertSame('s', $row['identity_kind']);
        self::assertSame('user-uuid', $row['identity_identifier']);
        self::assertSame(TokenFormat::parse($raw)['hash'], $row['token_hash']);
        self::assertFalse(str_contains(json_encode($row, JSON_THROW_ON_ERROR), $raw),
            'A database row must never contain the raw bearer token.');
        self::assertSame('CLI access', $row['name']);
        self::assertSame(['orders.read'], json_decode($row['abilities'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('2030-01-01 00:01:00', $row['expires_at']);
        self::assertSame('2030-01-01 00:00:00', $row['created_at']);
        self::assertNull($row['last_used_at']);

        $auth = $tokens->authenticate($raw);
        self::assertNotNull($auth);
        self::assertInstanceOf(MySqlTokenIdentity::class, $auth->identity());
        self::assertSame($identity->authIdentifier(), $auth->identity()->authIdentifier());
        self::assertTrue($auth->metadata()->allows('orders.read'));
        self::assertFalse($auth->metadata()->allows('orders.write'));
        self::assertNull($tokens->authenticate($raw . 'x'));
        self::assertNull($tokens->authenticate(substr($raw, 0, -1) . (substr($raw, -1) === 'A' ? 'B' : 'A')));
        self::assertNull($tokens->authenticate(TokenFormat::generate()['token']));
        self::assertSame('2030-01-01 00:00:00', $rows()->filter('identifier', $id)->first()['last_used_at']);

        $clock->at = self::utc('2030-01-01 00:00:59');
        self::assertNotNull($tokens->authenticate($raw));
        self::assertSame('2030-01-01 00:00:00', $rows()->filter('identifier', $id)->first()['last_used_at']);
        $clock->at = self::utc('2030-01-01 00:01:00');
        self::assertNull($tokens->authenticate($raw)); // Exact expiry boundary.

        $clock->at = self::utc('2030-01-01 00:02:00');
        $long = $tokens->issue($identity, 'Long access', ['orders.read'], self::utc('2030-01-01 01:00:00'));
        $longId = $long->metadata()->identifier();
        self::assertNotNull($tokens->authenticate($long->token()));
        $clock->at = self::utc('2030-01-01 00:06:59');
        self::assertNotNull($tokens->authenticate($long->token()));
        self::assertSame('2030-01-01 00:02:00', $rows()->filter('identifier', $longId)->first()['last_used_at']);
        $clock->at = self::utc('2030-01-01 00:07:00');
        self::assertNull($tokens->authenticate(substr($long->token(), 0, -1)
            . (substr($long->token(), -1) === 'A' ? 'B' : 'A')));
        self::assertSame('2030-01-01 00:02:00', $rows()->filter('identifier', $longId)->first()['last_used_at']);
        self::assertNotNull($tokens->authenticate($long->token()));
        self::assertSame('2030-01-01 00:07:00', $rows()->filter('identifier', $longId)->first()['last_used_at']);
        self::assertNull($tokens->authenticate($long->token() . 'x'));
        self::assertSame('2030-01-01 00:07:00', $rows()->filter('identifier', $longId)->first()['last_used_at']);

        $listing = $tokens->listFor($identity);
        self::assertCount(2, $listing);
        self::assertSame($longId, $listing[0]->identifier());
        self::assertSame(['orders.read'], $listing[0]->abilities());
        self::assertFalse(str_contains(var_export($listing, true), $long->token()),
            'Safe token metadata must not expose bearer material.');
        self::assertFalse(str_contains(var_export($listing, true),
            (string) $rows()->filter('identifier', $longId)->first()['token_hash']),
            'Safe token metadata must not expose stored digests.');

        $wildcard = $tokens->issue($identity, 'Wildcard', ['*'], self::utc('2030-01-01 01:00:00'));
        $wildcardAuth = $tokens->authenticate($wildcard->token());
        self::assertNotNull($wildcardAuth);
        self::assertTrue($wildcardAuth->metadata()->allows('orders.write'));

        $rotated = $tokens->rotate($longId);
        self::assertNull($tokens->authenticate($long->token()));
        self::assertNotNull($tokens->authenticate($rotated->token()));
        // Database hydration creates another date object; rotation must keep
        // the original expiry instant, not the original PHP object reference.
        $originalExpiry = $long->metadata()->expiresAt();
        $rotatedExpiry = $rotated->metadata()->expiresAt();
        self::assertNotNull($originalExpiry);
        self::assertNotNull($rotatedExpiry);
        self::assertSame($originalExpiry->getTimestamp(), $rotatedExpiry->getTimestamp());
        self::assertNotNull($rows()->filter('identifier', $longId)->first()['revoked_at']);
        self::assertNotNull($rows()->filter('identifier', $rotated->metadata()->identifier())->first());

        // A duplicate replacement identifier forces the INSERT to fail after
        // revocation. InnoDB must roll back that earlier UPDATE atomically.
        $old = $tokens->issue($identity, 'Rollback', ['orders.read'], self::utc('2030-01-01 01:00:00'));
        $other = $tokens->issue($identity, 'Collision', ['orders.read'], self::utc('2030-01-01 01:00:00'));
        $replacement = new TokenRecord($other->metadata()->identifier(), hash('sha256', 'replacement'),
            'api', 'user-uuid', 'Replacement', ['orders.read'], $clock->now(),
            self::utc('2030-01-01 01:00:00'));
        try {
            $repository->rotate('api', $old->metadata()->identifier(), $replacement, $clock->now());
            self::fail('A duplicate replacement identifier must fail.');
        } catch (QueryException $failure) {
            self::assertFalse(str_contains($failure->getMessage(), $old->token()),
                'Database failures must not expose bearer material.');
        }
        self::assertNull($rows()->filter('identifier', $old->metadata()->identifier())->first()['revoked_at']);
        self::assertNotNull($tokens->authenticate($old->token()));

        $corrupt = $tokens->issue($identity, 'Corrupt', ['orders.read'], self::utc('2030-01-01 01:00:00'));
        $rows()->filter('identifier', $corrupt->metadata()->identifier())->update(['abilities' => '{bad-json']);
        try {
            $tokens->authenticate($corrupt->token());
            self::fail('Corrupt abilities must fail closed.');
        } catch (TokenException $failure) {
            self::assertFalse(str_contains($failure->getMessage(), $corrupt->token()),
                'Invalid storage metadata must not expose bearer material.');
        }
        $rows()->filter('identifier', $corrupt->metadata()->identifier())
            ->update(['abilities' => '["orders.read"]']);

        self::assertTrue($tokens->revoke($old->metadata()->identifier()));
        self::assertFalse($tokens->revoke($old->metadata()->identifier()));
        self::assertNull($tokens->authenticate($old->token()));
        self::assertNotNull($tokens->authenticate($other->token()));
        $foreign = TokenFormat::generate();
        $repository->insert(new TokenRecord($foreign['identifier'], $foreign['hash'], 'other',
            'user-uuid', 'Different guard', [], $clock->now(), $clock->now()));
        self::assertGreaterThanOrEqual(2, $tokens->revokeAll($identity));
        self::assertNull($tokens->authenticate($other->token()));
        self::assertNull($tokens->authenticate($rotated->token()));
        $foreignRecord = $repository->find($foreign['identifier']);
        self::assertNotNull($foreignRecord);
        self::assertNull($foreignRecord->revokedAt);

        self::assertGreaterThanOrEqual(1, $tokens->prune());
        self::assertNotNull($repository->find($foreign['identifier']));
        self::assertCount(0, $repository->listFor('api', 'user-uuid'));
    }

    private static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function requiredEnvironment(string $key): string
    {
        $value = getenv($key);
        self::assertIsString($value, "Set {$key} for the disposable MySQL test.");
        self::assertNotSame('', $value, "Set {$key} for the disposable MySQL test.");
        return $value;
    }
}

/** A stable identity fixture lets MySQL qualification focus on token storage. */
final readonly class MySqlTokenIdentity implements Authenticatable
{
    public function __construct(private int|string $identifier)
    {
    }

    public function authIdentifier(): int|string { return $this->identifier; }
    public function authPasswordHash(): string { return 'unused'; }
}

/** Identity lookup remains explicit and independent of application settings. */
final class MySqlTokenIdentityProvider implements IdentityProvider
{
    public function __construct(private MySqlTokenIdentity $identity)
    {
    }

    public function retrieveById(int|string $identifier): ?Authenticatable
    {
        return $identifier === $this->identity->authIdentifier() ? $this->identity : null;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable { return null; }
    public function updatePassword(Authenticatable $identity, string $passwordHash): void { }
    public function supports(Authenticatable $identity): bool { return $identity === $this->identity; }
}

/** Controlled UTC time proves expiry and last-used writes without sleeping. */
final class MySqlTokenClock implements ModelClock
{
    public function __construct(public DateTimeImmutable $at)
    {
    }

    public function now(): DateTimeImmutable { return $this->at; }
}
