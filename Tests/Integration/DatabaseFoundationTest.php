<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Core\Database as LegacyDatabase;
use App\Core\Model as LegacyModel;
use App\Database\ConnectionFactory;
use App\Database\Database as DatabaseBridge;
use App\Database\DatabaseManager;
use App\Database\Exception\ConnectionException;
use App\Database\Exception\InvalidIdentifierException;
use App\Database\Exception\DatabaseException;
use PHPUnit\Framework\TestCase;
use PDO;

require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

final class DatabaseFoundationTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        $this->manager = new DatabaseManager(new Repository([
            'database' => [
                'default' => 'main',
                'connections' => [
                    'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                    'reporting' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]), new ConnectionFactory());
        DatabaseBridge::setResolver(fn (): DatabaseManager => $this->manager);
    }

    protected function tearDown(): void
    {
        DatabaseBridge::setResolver(null);
    }

    public function testConnectionsAreNamedCachedAndLazy(): void
    {
        $main = $this->manager->connection();
        $reporting = $this->manager->connection('reporting');
        self::assertSame('main', $main->name());
        self::assertSame($main, $this->manager->connection('main'));
        self::assertNotSame($main, $reporting);
        self::assertFalse($main->isConnected());
        self::assertFalse($reporting->isConnected());
        $firstPdo = $main->pdo();
        self::assertTrue($main->isConnected());
        self::assertFalse($reporting->isConnected());
        self::assertSame('sqlite', $main->driver());
        self::assertSame(PDO::ERRMODE_EXCEPTION, $firstPdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame(PDO::FETCH_ASSOC, $firstPdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        self::assertSame(1, (int) $firstPdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->manager->disconnect('main');
        self::assertFalse($main->isConnected());
        self::assertNotSame($firstPdo, $main->pdo());
    }

    public function testSchemaHelperKeepsNamedConnectionSelectionLazy(): void
    {
        $main = $this->manager->connection('main');
        $reporting = $this->manager->connection('reporting');

        $schema = \schema('reporting');
        self::assertFalse($main->isConnected());
        self::assertFalse($reporting->isConnected());
        self::assertFalse($schema->hasTable('not_created'));
        self::assertFalse($main->isConnected());
        self::assertTrue($reporting->isConnected());

        $schema->create('reports', static function (\App\Database\Schema\Table $table): void {
            $table->id();
        });
        self::assertTrue(\schema('reporting')->hasTable('reports'));
        self::assertFalse(\schema()->hasTable('reports'));
    }

    public function testTransactionCommitsAndRollsBackWhileRethrowingFailure(): void
    {
        $this->manager->raw('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $result = $this->manager->transaction(function (): string {
            $this->manager->raw('INSERT INTO items (name) VALUES (?)', ['first']);
            return 'committed';
        });
        self::assertSame('committed', $result);
        self::assertSame(1, (int) $this->manager->raw('SELECT COUNT(*) FROM items')->fetchColumn());

        try {
            $this->manager->transaction(function (): void {
                $this->manager->raw('INSERT INTO items (name) VALUES (?)', ['second']);
                throw new \RuntimeException('intentional failure');
            });
            self::fail('Transaction should have rethrown the callback failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('intentional failure', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->manager->raw('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    public function testUnsupportedDriverAndMissingConnectionFailClearly(): void
    {
        try {
            $this->manager->connection('missing');
            self::fail('Expected missing connection to fail.');
        } catch (ConnectionException $exception) {
            self::assertStringContainsString('not configured', $exception->getMessage());
        }

        $manager = new DatabaseManager(new Repository([
            'database' => [
                'default' => 'unsafe',
                'connections' => ['unsafe' => ['driver' => 'unknown', 'password' => 'secret-value']],
            ],
        ]));
        try {
            $manager->connection()->pdo();
            self::fail('Expected unsupported driver to fail.');
        } catch (ConnectionException $exception) {
            self::assertStringContainsString('unsupported', $exception->getMessage());
            self::assertStringNotContainsString('secret-value', $exception->getMessage());
        }
    }

    public function testManualTransactionsAndDisconnectGuard(): void
    {
        $this->manager->raw('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->manager->begin();
        $this->manager->raw('INSERT INTO items (name) VALUES (?)', ['rolled back']);
        try {
            $this->manager->disconnect('main');
            self::fail('Disconnecting an active transaction should fail.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('transaction', $exception->getMessage());
        }
        $this->manager->rollback();
        self::assertSame(0, (int) $this->manager->raw('SELECT COUNT(*) FROM items')->fetchColumn());

        $this->manager->begin();
        $this->manager->raw('INSERT INTO items (name) VALUES (?)', ['committed']);
        $this->manager->commit();
        self::assertSame(1, (int) $this->manager->raw('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    public function testLegacyStaticDatabaseAndModelUseSameConnection(): void
    {
        $this->manager->raw('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        self::assertSame($this->manager->connection()->pdo(), LegacyDatabase::connect());
        self::assertSame($this->manager->connection()->pdo(), LegacyDatabase::getInstance());

        $id = LegacyItem::create(['name' => 'legacy']);
        self::assertSame('1', $id);
        self::assertSame(['id' => 1, 'name' => 'legacy'], LegacyItem::find($id));
        self::assertSame(['id' => 1, 'name' => 'legacy'], LegacyItem::where('name', 'legacy'));
        self::assertSame(1, LegacyItem::update($id, ['name' => 'changed']));
        self::assertSame('changed', LegacyItem::find($id)['name']);
        self::assertSame(1, LegacyItem::delete($id));
        self::assertSame([], LegacyItem::all());
    }

    public function testLegacyModelRejectsUnsafeIdentifiers(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        LegacyItem::where('name; DELETE FROM items', 'anything');
    }

    public function testLegacyUpdateKeepsDataColumnDistinctFromCustomPrimaryKey(): void
    {
        $this->manager->raw('CREATE TABLE uuid_items (uuid TEXT PRIMARY KEY, id TEXT)');
        $this->manager->raw('INSERT INTO uuid_items (uuid, id) VALUES (?, ?)', ['abc', 'old']);
        self::assertSame(1, LegacyUuidItem::update('abc', ['id' => 'new']));
        self::assertSame('new', LegacyUuidItem::find('abc')['id']);
    }
}

final class LegacyItem extends LegacyModel
{
    protected static $table = 'items';
}

final class LegacyUuidItem extends LegacyModel
{
    protected static $table = 'uuid_items';
    protected static $primaryKey = 'uuid';
}
