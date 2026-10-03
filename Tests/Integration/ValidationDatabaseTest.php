<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Validation\Rule;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use App\Database\Exception\QueryException;
use PDO;
use PDOStatement;

final class ValidationQueryCounter
{
    public int $executions = 0;
}

final class CountingValidationStatement extends PDOStatement
{
    protected function __construct(private ValidationQueryCounter $counter) {}

    public function execute(?array $params = null): bool
    {
        $this->counter->executions++;
        return parent::execute($params);
    }
}

final class ValidationDatabaseTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        $this->manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main', 'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $this->manager->raw('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, deleted_at TEXT NULL)');
        $this->manager->raw('INSERT INTO users (email, deleted_at) VALUES (?, ?)', ['used@example.test', null]);
        $this->manager->raw('INSERT INTO users (email, deleted_at) VALUES (?, ?)', ['deleted@example.test', '2024-01-01']);
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testUniqueExistsAndSoftDeletedRowsAreTableLevel(): void
    {
        $rules = ['email' => 'email|unique:users,email'];
        self::assertTrue((new Validator(['email' => 'fresh@example.test']))->check($rules)->passes());
        self::assertTrue((new Validator(['email' => 'used@example.test']))->check($rules)->fails());
        self::assertTrue((new Validator(['email' => 'deleted@example.test']))->check($rules)->fails());
        self::assertTrue((new Validator(['email' => 'used@example.test']))->check(['email' => 'exists:users,email'])->passes());
        self::assertTrue((new Validator(['email' => 'absent@example.test']))->check(['email' => 'exists:users,email'])->fails());
        self::assertTrue((new Validator(['email' => 'used@example.test']))->check([
            'email' => [Rule::unique('users', 'email')->ignore(1)],
        ])->passes());
    }

    public function testDatabaseConnectionRemainsLazyUntilRuleRuns(): void
    {
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main', 'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        Database::setResolver(fn (): DatabaseManager => $manager);
        $result = (new Validator([]))->check(['email' => 'unique:users,email']);
        self::assertTrue($result->passes());
        self::assertFalse($manager->connection()->isConnected());
    }

    public function testWildcardExistsAndUniqueUseBoundedBatches(): void
    {
        $this->manager->raw('CREATE TABLE products (id INTEGER PRIMARY KEY)');
        for ($i = 1; $i <= 500; $i++) {
            $this->manager->raw('INSERT INTO products (id) VALUES (?)', [$i]);
        }
        $counter = new ValidationQueryCounter();
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [CountingValidationStatement::class, [$counter]]);
        $items = [];
        for ($i = 0; $i < 501; $i++) {
            $items[] = ['product_id' => $i + 1];
        }
        $result = (new Validator(['items' => $items]))->check([
            'items.*.product_id' => 'integer|exists:products,id',
        ]);
        self::assertSame(['items.500.product_id'], array_keys($result->errors()));
        self::assertSame(2, $counter->executions); // 501 distinct values require two bounded batches.
        $unique = (new Validator(['items' => [['email' => 'used@example.test'],
            ['email' => 'fresh@example.test']]]))->check([
            'items.*.email' => 'email|unique:users,email',
        ]);
        self::assertSame(['items.0.email'], array_keys($unique->errors()));
        self::assertSame(3, $counter->executions);
        $objectUnique = (new Validator(['items' => [['email' => 'used@example.test'],
            ['email' => 'fresh@example.test']]]))->check([
            'items.*.email' => [Rule::unique('users', 'email')->ignore(999)],
        ]);
        self::assertSame(['items.0.email'], array_keys($objectUnique->errors()));
        self::assertSame(4, $counter->executions);
    }

    public function testBindingsAndInfrastructureErrorsAreDistinctFromValidationErrors(): void
    {
        $injection = "used@example.test' OR 1=1 --";
        self::assertTrue((new Validator(['email' => $injection]))->check([
            'email' => 'unique:users,email',
        ])->passes());
        $this->expectException(QueryException::class);
        (new Validator(['value' => 'x']))->check(['value' => 'exists:missing_table,id']);
    }

    public function testWildcardBatchUsesDatabaseCollationAndNumericAffinity(): void
    {
        $this->manager->raw('CREATE TABLE aliases (id INTEGER PRIMARY KEY, name TEXT COLLATE NOCASE)');
        $this->manager->raw('INSERT INTO aliases (id, name) VALUES (?, ?)', [1, 'Ada']);
        $names = (new Validator(['items' => [['name' => 'ada']]]))->check([
            'items.*.name' => 'unique:aliases,name',
        ]);
        self::assertSame(['items.0.name'], array_keys($names->errors()));
        $ids = (new Validator(['items' => [['id' => '01']]]))->check([
            'items.*.id' => 'exists:aliases,id',
        ]);
        self::assertTrue($ids->passes(), json_encode($ids->errors()));
    }
}
