<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Database\Connection;
use App\Database\ConnectionFactory;
use App\Database\Exception\InvalidIdentifierException;
use App\Database\Schema\MysqlCompiler;
use App\Database\Schema\Schema;
use App\Database\Schema\SchemaException;
use App\Database\Schema\SqliteCompiler;
use App\Database\Schema\Table;
use PHPUnit\Framework\TestCase;

/** SQL compilation checks that require neither PDO nor a database service. */
final class SchemaCompilationTest extends TestCase
{
    public function testCompilersMapGeneratedKeysForeignIdsAndIndexesDifferently(): void
    {
        $table = new Table('child');
        $table->id();
        $table->foreignId('parent_id');
        $table->string('order', 190)->default("O'Reilly");
        $table->boolean('active')->default(true);
        $table->datetime('created_at')->nullable();
        $table->unique(['parent_id', 'order']);
        $table->index('created_at');
        $table->foreign('parent_id', 'parent', 'id')->onDelete('cascade')->onUpdate('restrict');

        $sqlite = (new SqliteCompiler())->compileCreate($table);
        self::assertCount(2, $sqlite);
        self::assertStringContainsString('`id` INTEGER PRIMARY KEY AUTOINCREMENT', $sqlite[0]);
        self::assertStringContainsString('`parent_id` INTEGER NOT NULL', $sqlite[0]);
        self::assertStringContainsString("`order` VARCHAR(190) NOT NULL DEFAULT 'O''Reilly'", $sqlite[0]);
        self::assertStringContainsString('`active` INTEGER NOT NULL DEFAULT 1 CHECK (`active` IN (0, 1))', $sqlite[0]);
        self::assertStringContainsString('UNIQUE (`parent_id`, `order`)', $sqlite[0]);
        self::assertStringContainsString('ON DELETE CASCADE ON UPDATE RESTRICT', $sqlite[0]);
        self::assertSame('CREATE INDEX `child_created_at_index` ON `child` (`created_at`)', $sqlite[1]);

        $mysql = (new MysqlCompiler())->compileCreate($table);
        self::assertCount(1, $mysql);
        self::assertStringContainsString('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $mysql[0]);
        self::assertStringContainsString('`parent_id` BIGINT UNSIGNED NOT NULL', $mysql[0]);
        self::assertStringContainsString('`active` TINYINT(1) NOT NULL DEFAULT 1', $mysql[0]);
        self::assertStringContainsString('INDEX `child_created_at_index` (`created_at`)', $mysql[0]);
        self::assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $mysql[0]);
    }

    public function testPrimaryCompositeIndexesAndDropsCompile(): void
    {
        $table = new Table('pivot');
        $table->foreignId('left_id');
        $table->foreignId('right_id');
        $table->primary(['left_id', 'right_id']);
        $table->index(['right_id', 'left_id'], 'pivot_reverse_index');
        $sql = (new MysqlCompiler())->compileCreate($table)[0];
        self::assertStringContainsString('PRIMARY KEY (`left_id`, `right_id`)', $sql);
        self::assertStringContainsString('INDEX `pivot_reverse_index` (`right_id`, `left_id`)', $sql);
        self::assertSame('DROP TABLE `pivot`', (new MysqlCompiler())->compileDrop('pivot'));
        self::assertSame('DROP TABLE IF EXISTS `pivot`', (new SqliteCompiler())->compileDrop('pivot', true));
    }

    public function testInvalidDefinitionFailsBeforeOpeningConnection(): void
    {
        $connection = new Connection('test', ['driver' => 'sqlite', 'database' => ':memory:'], new ConnectionFactory());
        $schema = new Schema($connection);
        self::assertFalse($connection->isConnected());

        try {
            $schema->create('children', static function (Table $table): void {
                $table->id();
                $table->foreignId('parent_id');
                $table->foreign('parent_id', 'parents', 'id')->onDelete('set null');
            });
            self::fail('SET NULL on a required column must be rejected.');
        } catch (SchemaException $exception) {
            self::assertStringContainsString('nullable', $exception->getMessage());
            self::assertFalse($connection->isConnected());
        }

        try {
            $schema->create('children', static function (Table $table): void {
                $table->id();
                $table->index('missing');
            });
            self::fail('Unknown index columns must be rejected.');
        } catch (SchemaException $exception) {
            self::assertStringContainsString('missing', $exception->getMessage());
            self::assertFalse($connection->isConnected());
        }
    }

    public function testUnsafeIdentifiersAndDefaultExpressionsAreRejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new Table('users; DROP TABLE users');
    }

    public function testSchemaServiceReportsOperationWithoutEchoingUnsafeIdentifier(): void
    {
        $connection = new Connection('test', ['driver' => 'sqlite', 'database' => ':memory:'], new ConnectionFactory());
        $schema = new Schema($connection);
        $unsafe = 'users; DROP TABLE private_data';

        foreach ([
            ['define table', static fn (): mixed => $schema->create($unsafe, static function (Table $table): void {
                $table->id();
            })],
            ['inspect table', static fn (): bool => $schema->hasTable($unsafe)],
            ['inspect table column', static fn (): bool => $schema->hasColumn('users', $unsafe)],
            ['drop table', static fn (): mixed => $schema->drop($unsafe)],
        ] as [$operation, $call]) {
            try {
                $call();
                self::fail('Unsafe identifier should have failed.');
            } catch (SchemaException $exception) {
                self::assertStringContainsString($operation, $exception->getMessage());
                self::assertStringNotContainsString($unsafe, $exception->getMessage());
                self::assertInstanceOf(InvalidIdentifierException::class, $exception->getPrevious());
            }
        }
        self::assertFalse($connection->isConnected());
    }

    public function testUnsupportedDefaultsTypesAndActionsAreRejected(): void
    {
        $table = new Table('records');
        $table->id();
        $table->string('name')->default("x\\'; DROP TABLE records;--");
        try {
            (new SqliteCompiler())->compileCreate($table);
            self::fail('Ambiguous backslash literal must be rejected.');
        } catch (SchemaException $exception) {
            self::assertStringContainsString('default literal', $exception->getMessage());
        }

        $table = new Table('records');
        $table->id();
        $table->text('body')->default('unsupported');
        $this->expectException(SchemaException::class);
        (new MysqlCompiler())->compileCreate($table);
    }

    public function testForeignRequiresCompatibleLocalTypeAndAllowlistedActions(): void
    {
        $table = new Table('children');
        $table->id();
        $table->integer('parent_id');
        $foreign = $table->foreign('parent_id', 'parents', 'id');
        try {
            $foreign->onDelete('execute');
            self::fail('Unsupported action must be rejected.');
        } catch (SchemaException $exception) {
            self::assertStringContainsString('unsupported action', $exception->getMessage());
        }
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('foreignId()');
        (new MysqlCompiler())->compileCreate($table);
    }

    public function testTextIndexAndInvalidStringLengthFailExplicitly(): void
    {
        $table = new Table('documents');
        $table->id();
        $table->text('body');
        $table->index('body');
        try {
            (new MysqlCompiler())->compileCreate($table);
            self::fail('An index on text needs driver-specific prefix syntax.');
        } catch (SchemaException $exception) {
            self::assertStringContainsString('cannot be indexed', $exception->getMessage());
        }

        $this->expectException(SchemaException::class);
        (new Table('short'))->string('name', 0);
    }
}
