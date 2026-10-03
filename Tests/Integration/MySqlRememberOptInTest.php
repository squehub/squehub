<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Remember\RememberTokenRecord;
use App\Auth\Remember\Repositories\DatabaseRememberTokenRepository;
use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Schema\Schema;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Qualifies persistent remember credentials only on a confirmed empty MySQL schema.
 * The shared advisory lock serializes cooperating opt-in suites before any DDL.
 *
 * @group mysql
 */
final class MySqlRememberOptInTest extends TestCase
{
    public function testMigrationAndAtomicRememberRotationOnDisposableMySql(): void
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

        // Never use the normal Application or its .env database connection.
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'disposable',
            'connections' => ['disposable' => [
                'driver' => 'mysql', 'host' => $host, 'port' => $port,
                'database' => $database, 'username' => $user,
                'password' => $password, 'charset' => 'utf8mb4',
            ]],
        ]]));
        $connection = $manager->connection();
        $pdo = $connection->pdo();
        self::assertSame($database, $pdo->query('SELECT DATABASE()')->fetchColumn());
        $version = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        $comment = strtolower((string) $pdo->query('SELECT @@version_comment')->fetchColumn());
        self::assertStringNotContainsString('mariadb', $version . $comment,
            'This test verifies MySQL semantics.');
        self::assertSame('innodb', strtolower((string)
            $pdo->query('SELECT @@default_storage_engine')->fetchColumn()));

        $lockName = 'squehub_phase6a_' . substr(hash('sha256', $database), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        self::assertSame(1, (int) $lock->fetchColumn(),
            'Another SqueHub MySQL test is using this database.');
        try {
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM),
                'The confirmed test database must be empty before this test may create tables.');
            fwrite(STDERR, "Opt-in MySQL remember server: {$version} ({$comment}), default engine InnoDB.\n");

            try {
                require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_30_create_remember_tokens.php';
                $migration = new \CreateRememberTokens();
                $schema = $connection->schema();
                $migration->up($pdo, $schema);
                $this->assertStorage($pdo, $schema);
                $this->forceCaseInsensitiveTextColumns($pdo);
                $this->assertRepositoryLifecycle($manager, $pdo);
                $migration->down($pdo, $schema);
                self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
            } finally {
                // The empty-schema check under this lock established ownership
                // even if migration DDL succeeded only partway through.
                $pdo->exec('DROP TABLE IF EXISTS `remember_tokens`');
            }
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
                // Closing PDO also releases the lock; preserve the first error.
            }
        }
    }

    private function assertStorage(PDO $pdo, Schema $schema): void
    {
        self::assertTrue($schema->hasTable('remember_tokens'));
        foreach (['selector', 'validator_hash', 'guard', 'identity_identifier',
            'identity_scope_hash', 'credential_fingerprint', 'created_at', 'expires_at'] as $column) {
            self::assertTrue($schema->hasColumn('remember_tokens', $column), $column);
        }
        $engine = $pdo->prepare('SELECT ENGINE AS engine FROM INFORMATION_SCHEMA.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $engine->execute(['remember_tokens']);
        self::assertSame('innodb', strtolower((string) $engine->fetchColumn()));

        // Explicit aliases avoid server-dependent INFORMATION_SCHEMA key casing.
        $statement = $pdo->prepare('SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, '
            . 'SEQ_IN_INDEX AS seq_in_index, COLUMN_NAME AS column_name '
            . 'FROM INFORMATION_SCHEMA.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $statement->execute(['remember_tokens']);
        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['index_name'];
            $indexes[$name]['unique'] = (int) $row['non_unique'] === 0;
            $indexes[$name]['columns'][] = (string) $row['column_name'];
        }
        foreach ([
            'PRIMARY' => [true, ['selector']],
            'remember_tokens_identity' => [false, ['identity_scope_hash']],
            'remember_tokens_expires' => [false, ['expires_at']],
        ] as $name => [$unique, $columns]) {
            self::assertArrayHasKey($name, $indexes);
            self::assertSame($unique, $indexes[$name]['unique'], $name);
            self::assertSame($columns, $indexes[$name]['columns'], $name);
        }
    }

    private function forceCaseInsensitiveTextColumns(PDO $pdo): void
    {
        // Qualification must exercise exact credential and identity matching
        // even when the server uses a case-insensitive application collation.
        $pdo->exec('ALTER TABLE `remember_tokens` MODIFY `selector` VARCHAR(22) '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        $pdo->exec('ALTER TABLE `remember_tokens` MODIFY `identity_identifier` VARCHAR(255) '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    }

    private function assertRepositoryLifecycle(DatabaseManager $database, PDO $pdo): void
    {
        $first = new DatabaseRememberTokenRepository($database, 'remember_tokens', 'disposable');
        $second = new DatabaseRememberTokenRepository($database, 'remember_tokens', 'disposable');
        $now = self::utc('2030-01-01 00:00:00');
        $rawValidator = 'SQUEHUB_REMEMBER_MYSQL_SECRET_DO_NOT_STORE';
        $original = self::record(str_repeat('A', 22), $rawValidator,
            'web', 's:CaseUser', $now, $now->modify('+1 hour'));
        $first->insert($original);
        $row = $database->table('remember_tokens', 'disposable')->first();
        self::assertSame($original->validatorHash, $row['validator_hash']);
        self::assertSame(RememberTokenRecord::scopeHash('web', 's:CaseUser'),
            $row['identity_scope_hash']);
        self::assertFalse(str_contains(json_encode($row, JSON_THROW_ON_ERROR), $rawValidator),
            'Persistent storage must never contain a raw browser validator.');
        self::assertNull($second->find(str_repeat('a', 22)),
            'Case-insensitive MySQL lookup must not accept a different selector.');

        $stale = $second->find($original->selector);
        self::assertNotNull($stale);
        self::assertSame($original->selector, $stale->selector);
        self::assertSame('s:CaseUser', $stale->identityKey);
        self::assertSame($now->getTimestamp(), $stale->createdAt->getTimestamp());
        self::assertFalse($stale->expired($now->modify('+59 minutes 59 seconds')));
        self::assertTrue($stale->expired($now->modify('+1 hour')),
            'A credential expires at the exact stored UTC boundary.');

        $winner = self::record(str_repeat('B', 22), 'new-validator',
            'web', 's:CaseUser', $now->modify('+1 minute'), $now->modify('+2 hours'));
        $loser = self::record(str_repeat('C', 22), 'stale-validator',
            'web', 's:CaseUser', $now->modify('+1 minute'), $now->modify('+2 hours'));
        self::assertTrue($first->rotate($original->selector, $original->validatorHash, $winner));
        self::assertFalse($second->rotate($stale->selector, $stale->validatorHash, $loser),
            'A stale reader must lose the conditional rotation.');
        self::assertNull($first->find($original->selector));
        self::assertNull($second->find($loser->selector));
        self::assertSame($winner->validatorHash, $second->find($winner->selector)?->validatorHash);
        self::assertFalse($second->rotate($winner->selector, hash('sha256', 'incorrect'), $loser));

        $caseVariant = self::record(str_repeat('D', 22), 'case-validator',
            'web', 's:caseuser', $now, $now->modify('+1 day'));
        $otherGuard = self::record(str_repeat('E', 22), 'other-guard-validator',
            'admin', 's:CaseUser', $now, $now->modify('+1 day'));
        $first->insert($caseVariant);
        $first->insert($otherGuard);
        $second->revokeIdentity('web', 's:CaseUser');
        self::assertNull($first->find($winner->selector));
        self::assertNotNull($first->find($caseVariant->selector));
        self::assertNotNull($first->find($otherGuard->selector));
        $first->revokeSelector($caseVariant->selector);
        self::assertNull($second->find($caseVariant->selector));
        self::assertNotNull($second->find($otherGuard->selector));

        try {
            $database->transaction(static function () use ($first, $now): void {
                $first->insert(self::record(str_repeat('F', 22), 'rollback-validator',
                    'web', 's:CaseUser', $now, $now->modify('+1 day')));
                throw new RuntimeException('roll back remember credential');
            }, 'disposable');
            self::fail('The remember credential transaction must roll back.');
        } catch (RuntimeException $failure) {
            self::assertSame('roll back remember credential', $failure->getMessage());
        }
        self::assertNull($second->find(str_repeat('F', 22)));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM `remember_tokens`')->fetchColumn());
    }

    private static function record(string $selector, string $validator, string $guard,
        string $identityKey, DateTimeImmutable $created, DateTimeImmutable $expires): RememberTokenRecord
    {
        return new RememberTokenRecord($selector, hash('sha256', $validator), $guard,
            $identityKey, hash('sha256', 'credential-fingerprint'), $created, $expires);
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
