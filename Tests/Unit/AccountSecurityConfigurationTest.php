<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\AccountSecurity\AccountSecurityConfigurationException;
use App\AccountSecurity\AccountSecurityManager;
use App\Auth\AuthManager;
use App\Auth\PasswordHasher;
use App\Config\Repository;
use App\Database\Connection;
use App\Database\ConnectionFactory;
use App\Database\DatabaseManager;
use App\Database\Schema\MysqlCompiler;
use App\Database\Schema\Table;
use App\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/** Configuration and MySQL SQL compilation need no live database or Session. */
final class AccountSecurityConfigurationTest extends TestCase
{
    private function manager(array $settings): AccountSecurityManager
    {
        $config = new Repository(['auth' => ['default' => null, 'guards' => [], 'identities' => []],
            'accountSecurity' => $settings]);
        return new AccountSecurityManager(new AuthManager($config, new SessionManager($config),
            new PasswordHasher($config)), new PasswordHasher($config), new DatabaseManager($config), $config);
    }

    public function testEmptyConfigurationBootsButGuardOperationsFailClearly(): void
    {
        $manager = $this->manager([]);
        $this->expectException(AccountSecurityConfigurationException::class);
        $manager->issuePasswordReset(['email' => 'someone@example.test']);
    }

    public function testInvalidDriverTableAndTtlsFailBeforeDatabaseUse(): void
    {
        foreach ([
            ['tokens' => ['driver' => 'redis']],
            ['tokens' => ['driver' => null]],
            ['tokens' => ['table' => 'bad;name']],
            ['tokens' => ['table' => str_repeat('x', 65)]],
            ['password_reset' => ['ttl' => 0]],
            ['password_reset' => ['ttl' => '60']],
            ['email_verification' => ['ttl' => -1]],
            ['email_verification' => ['ttl' => null]],
        ] as $settings) {
            try {
                $this->manager($settings);
                self::fail('Invalid account-security settings were accepted.');
            } catch (AccountSecurityConfigurationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTokenTableAndQueriesCompileForMysqlWithoutConnecting(): void
    {
        $table = new Table('account_security_tokens');
        $table->string('token_hash', 64);
        $table->string('purpose', 32);
        $table->string('guard', 64);
        $table->string('identity_identifier', 255);
        $table->string('context_hash', 64);
        $table->datetime('expires_at');
        $table->datetime('created_at');
        $table->unique('token_hash');
        $table->index(['guard', 'identity_identifier', 'purpose']);
        $table->index('expires_at');
        $sql = (new MysqlCompiler())->compileCreate($table)[0];
        self::assertStringContainsString('UNIQUE (`token_hash`)', $sql);
        self::assertStringContainsString('`context_hash` VARCHAR(64)', $sql);
        $connection = new Connection('test', ['driver' => 'mysql', 'host' => 'localhost',
            'database' => 'unused', 'username' => 'unused'], new ConnectionFactory());
        $query = $connection->table('account_security_tokens')->filter('token_hash', str_repeat('a', 64))
            ->filter('purpose', 'password_reset')->filter('guard', 'web');
        self::assertStringContainsString('WHERE `token_hash` = ? AND `purpose` = ? AND `guard` = ?', $query->toSql());
        self::assertFalse($connection->isConnected());
    }
}
