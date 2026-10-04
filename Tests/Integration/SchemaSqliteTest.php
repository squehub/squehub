<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\Connection;
use App\Database\ConnectionFactory;
use App\Database\Schema\Schema;
use App\Database\Schema\SchemaException;
use App\Database\Schema\Table;
use PDO;
use PHPUnit\Framework\TestCase;

/** Exercises real SQLite tables and foreign-key behavior in memory only. */
final class SchemaSqliteTest extends TestCase
{
    private Connection $connection;
    private Schema $schema;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for schema execution tests.');
        }
        $this->connection = new Connection('isolated', ['driver' => 'sqlite', 'database' => ':memory:'], new ConnectionFactory());
        $this->schema = new Schema($this->connection);
    }

    public function testCreateInspectIndexesAndDrop(): void
    {
        self::assertFalse($this->connection->isConnected());
        $this->schema->create('users', static function (Table $table): void {
            $table->id();
            $table->string('email', 190);
            $table->boolean('active')->default(true);
            $table->datetime('created_at')->nullable();
            $table->unique('email');
            $table->index(['active', 'created_at']);
        });
        self::assertTrue($this->schema->hasTable('users'));
        self::assertTrue($this->schema->hasColumn('users', 'email'));
        self::assertFalse($this->schema->hasColumn('users', 'missing'));
        self::assertSame(2, count($this->connection->raw('PRAGMA index_list(`users`)')->fetchAll()));
        $this->connection->table('users')->insert(['email' => 'a@example.test']);
        self::assertSame(1, (int) $this->connection->table('users')->first()['active']);
        $this->schema->drop('users');
        self::assertFalse($this->schema->hasTable('users'));
        $this->schema->dropIfExists('users');
    }

    public function testForeignKeyRestrictCascadeAndSetNullAreEnforced(): void
    {
        self::assertSame(1, (int) $this->connection->raw('PRAGMA foreign_keys')->fetchColumn());
        $this->schema->create('parents', static function (Table $table): void {
            $table->id();
            $table->string('name');
        });
        $this->schema->create('restrict_children', static function (Table $table): void {
            $table->id();
            $table->foreignId('parent_id');
            $table->foreign('parent_id', 'parents', 'id')->onDelete('restrict');
        });
        $this->schema->create('cascade_children', static function (Table $table): void {
            $table->id();
            $table->foreignId('parent_id');
            $table->foreign('parent_id', 'parents', 'id')->onDelete('cascade');
        });
        $this->schema->create('nullable_children', static function (Table $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable();
            $table->foreign('parent_id', 'parents', 'id')->onDelete('set null');
        });
        $this->connection->table('parents')->insert(['name' => 'first']);
        $this->connection->table('parents')->insert(['name' => 'second']);
        $this->connection->table('restrict_children')->insert(['parent_id' => 1]);
        $this->connection->table('cascade_children')->insert(['parent_id' => 2]);
        $this->connection->table('nullable_children')->insert(['parent_id' => 2]);

        try {
            $this->connection->table('parents')->filter('id', 1)->delete();
            self::fail('RESTRICT should reject removal of a referenced parent.');
        } catch (\App\Database\Exception\QueryException) {
            self::assertSame(1, $this->connection->table('restrict_children')->count());
        }
        try {
            $this->connection->table('cascade_children')->insert(['parent_id' => 999]);
            self::fail('Foreign-key enforcement should reject a missing parent.');
        } catch (\App\Database\Exception\QueryException) {
            self::assertSame(1, $this->connection->table('cascade_children')->count());
        }
        $this->connection->table('parents')->filter('id', 2)->delete();
        self::assertSame(0, $this->connection->table('cascade_children')->count());
        self::assertNull($this->connection->table('nullable_children')->first()['parent_id']);
    }

    public function testSchemaChangesShareAnOuterSqliteTransaction(): void
    {
        $this->connection->begin();
        $this->schema->create('temporary', static function (Table $table): void {
            $table->id();
            $table->index('id');
        });
        self::assertTrue($this->schema->hasTable('temporary'));
        $this->connection->rollback();
        self::assertFalse($this->schema->hasTable('temporary'));
    }

    public function testForeignKeyUpdateActionsAreEnforced(): void
    {
        self::assertSame(1, (int) $this->connection->raw('PRAGMA foreign_keys')->fetchColumn());
        $this->schema->create('parents', static function (Table $table): void {
            $table->id();
            $table->string('name');
        });
        $this->schema->create('cascade_children', static function (Table $table): void {
            $table->id();
            $table->foreignId('parent_id');
            $table->foreign('parent_id', 'parents', 'id')->onUpdate('cascade');
        });
        $this->schema->create('nullable_children', static function (Table $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable();
            $table->foreign('parent_id', 'parents', 'id')->onUpdate('set null');
        });
        $this->connection->table('parents')->insert(['name' => 'parent']);
        $this->connection->table('cascade_children')->insert(['parent_id' => 1]);
        $this->connection->table('nullable_children')->insert(['parent_id' => 1]);
        $this->connection->table('parents')->filter('id', 1)->update(['id' => 2]);
        self::assertSame(2, (int) $this->connection->table('cascade_children')->first()['parent_id']);
        self::assertNull($this->connection->table('nullable_children')->first()['parent_id']);
    }

    public function testCompositeUniqueConstraintAndOrdinaryIndexExist(): void
    {
        $this->schema->create('memberships', static function (Table $table): void {
            $table->id();
            $table->string('tenant');
            $table->string('username');
            $table->unique(['tenant', 'username']);
            $table->index(['username', 'tenant']);
        });
        $this->connection->table('memberships')->insert(['tenant' => 'a', 'username' => 'val']);
        $this->connection->table('memberships')->insert(['tenant' => 'b', 'username' => 'val']);
        self::assertSame(2, $this->connection->table('memberships')->count());
        self::assertSame(2, count($this->connection->raw('PRAGMA index_list(`memberships`)')->fetchAll()));
        try {
            $this->connection->table('memberships')->insert(['tenant' => 'a', 'username' => 'val']);
            self::fail('The composite unique constraint must reject a repeated pair.');
        } catch (\App\Database\Exception\QueryException) {
            self::assertSame(2, $this->connection->table('memberships')->count());
        }
    }

    public function testSeparateIndexFailureRollsBackCreatedTable(): void
    {
        $this->schema->create('existing', static function (Table $table): void {
            $table->id();
            $table->index('id', 'shared_index');
        });
        try {
            $this->schema->create('new_table', static function (Table $table): void {
                $table->id();
                $table->index('id', 'shared_index');
            });
            self::fail('The second index should conflict globally in SQLite.');
        } catch (SchemaException $exception) {
            self::assertStringContainsString("create table 'new_table'", $exception->getMessage());
            self::assertFalse($this->schema->hasTable('new_table'));
            self::assertTrue($this->schema->hasTable('existing'));
        }
    }
}
