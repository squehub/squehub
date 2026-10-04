<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Contracts\Authenticatable;
use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Mfa\Repositories\DatabaseMfaRepository;
use App\Mfa\Repositories\MfaIdentity;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Qualifies MFA compare-and-swap writes on a confirmed empty MySQL test database.
 * No ordinary Application or developer .env database settings are read.
 *
 * @group mysql
 */
final class MySqlMfaOptInTest extends TestCase
{
    public function testGenerationBoundCounterAndRecoveryClaimsOnMySql(): void
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

        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'disposable',
            'connections' => ['disposable' => [
                'driver' => 'mysql', 'host' => $host, 'port' => $port,
                'database' => $database, 'username' => $user,
                'password' => $password, 'charset' => 'utf8mb4',
            ]],
        ]]));
        $pdo = $manager->connection()->pdo();
        self::assertSame($database, $pdo->query('SELECT DATABASE()')->fetchColumn());
        $version = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        $comment = strtolower((string) $pdo->query('SELECT @@version_comment')->fetchColumn());
        self::assertStringNotContainsString('mariadb', $version . $comment,
            'This test verifies MySQL semantics.');
        self::assertSame('innodb', strtolower((string)
            $pdo->query('SELECT @@default_storage_engine')->fetchColumn()));

        // Other opt-in suites use this lock and also demand an empty schema.
        $lockName = 'squehub_phase6a_' . substr(hash('sha256', $database), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        self::assertSame(1, (int) $lock->fetchColumn(), 'Another SqueHub MySQL test is using this database.');
        try {
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM),
                'The confirmed test database must be empty before this test may create tables.');
            try {
                require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_30_create_mfa_tables.php';
                (new \CreateMfaTables())->up($pdo, $manager->schema());
                self::assertTrue($manager->schema()->hasTable('mfa_credentials'));
                self::assertTrue($manager->schema()->hasTable('mfa_recovery_codes'));
                $this->assertClaims($manager);
            } finally {
                // Emptiness under the advisory lock established ownership even
                // if one of the two CREATE TABLE statements failed partway.
                $pdo->exec('DROP TABLE IF EXISTS `mfa_recovery_codes`');
                $pdo->exec('DROP TABLE IF EXISTS `mfa_credentials`');
            }
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (\Throwable) {
                // Closing PDO also releases the lock. Preserve an earlier test failure.
            }
        }
    }

    private function assertClaims(DatabaseManager $database): void
    {
        $first = new DatabaseMfaRepository($database);
        $second = new DatabaseMfaRepository($database);
        $identity = MfaIdentity::from('web', new MySqlMfaIdentity(7));
        $now = time();
        $oldSecret = 'mysql-encrypted-old';
        $newSecret = 'mysql-encrypted-new';
        $oldHash = hash('sha256', $oldSecret);
        $newHash = hash('sha256', $newSecret);
        $sharedCode = hash('sha256', 'same-recovery-code');

        $first->savePending($identity, $oldSecret, $now + 600);
        self::assertTrue($first->activate($identity, $oldSecret, 100, [$sharedCode], $now));
        self::assertSame('0000000000000000100',
            $database->table('mfa_credentials')->first()['last_counter']);
        self::assertTrue($first->claimCounter($identity, 101, $oldHash));
        self::assertFalse($second->claimCounter($identity, 101, $oldHash));
        self::assertTrue($second->claimRecoveryCode($identity, $sharedCode, $oldHash));
        self::assertFalse($first->claimRecoveryCode($identity, $sharedCode, $oldHash));

        self::assertTrue($first->disable($identity, $oldHash));
        $second->savePending($identity, $newSecret, $now + 600);
        self::assertTrue($second->activate($identity, $newSecret, 200, [$sharedCode], $now));
        self::assertFalse($first->claimCounter($identity, 201, $oldHash));
        self::assertFalse($first->claimRecoveryCode($identity, $sharedCode, $oldHash));
        self::assertFalse($first->replaceRecoveryCodes($identity,
            [hash('sha256', 'stale-code')], $oldHash));
        self::assertFalse($first->disable($identity, $oldHash));
        self::assertSame(200, $second->find($identity)['last_counter']);
        self::assertSame(1, $database->table('mfa_recovery_codes')->count());

        self::assertTrue($second->claimCounter($identity, 201, $newHash));
        self::assertFalse($first->claimCounter($identity, 201, $newHash));
        self::assertTrue($first->claimRecoveryCode($identity, $sharedCode, $newHash));
        self::assertFalse($second->claimRecoveryCode($identity, $sharedCode, $newHash));
    }

    private function requiredEnvironment(string $key): string
    {
        $value = getenv($key);
        self::assertNotFalse($value, "Set {$key} for the disposable MySQL test.");
        self::assertNotSame('', $value, "Set {$key} for the disposable MySQL test.");
        return $value;
    }
}

final readonly class MySqlMfaIdentity implements Authenticatable
{
    public function __construct(private int|string $identifier) {}
    public function authIdentifier(): int|string { return $this->identifier; }
    public function authPasswordHash(): string { return 'unused'; }
}
