<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Schema\SchemaException;
use App\Database\Schema\Table;
use PDO;
use PHPUnit\Framework\TestCase;

/** Covers bounded schema metadata and backend-specific alteration boundaries. */
final class SchemaIntrospectionTest extends TestCase
{
    public function testSqliteIndexesAndForeignKeysCanBeInspectedWithoutUnsafeAlteration(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for isolated schema tests.');
        }
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main', 'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        $schema = $manager->schema();
        $schema->create('schema_owners', static function (Table $table): void {
            $table->id();
        });
        $schema->create('schema_children', static function (Table $table): void {
            $table->id();
            $table->foreignId('owner_id');
            $table->string('code');
            $table->string('region');
            $table->index('code', 'children_code_index');
            $table->unique(['code', 'region'], 'children_code_region_unique');
            $table->foreign('owner_id', 'schema_owners', 'id', 'children_owner_foreign')
                ->onDelete('CASCADE');
        });

        $indexes = $schema->indexes('schema_children');
        self::assertContains('children_code_index', array_column($indexes, 'name'));
        self::assertTrue($schema->hasIndex('schema_children', 'children_code_index'));
        $composite = current(array_filter($indexes,
            static fn (array $index): bool => $index['columns'] === ['code', 'region']));
        self::assertSame(['code', 'region'], $composite['columns']);
        self::assertTrue($composite['unique']);
        self::assertStringStartsWith('sqlite_autoindex_', $composite['name']);

        self::assertSame([[
            'name' => null,
            'columns' => ['owner_id'],
            'referenced_table' => 'schema_owners',
            'referenced_columns' => ['id'],
            'on_update' => 'RESTRICT',
            'on_delete' => 'CASCADE',
        ]], $schema->foreignKeys('schema_children'));

        $schema->dropIndex('schema_children', 'children_code_index');
        self::assertFalse($schema->hasIndex('schema_children', 'children_code_index'));
        self::assertContains(['code', 'region'], array_column($schema->indexes('schema_children'), 'columns'));
        try {
            $schema->dropForeignKey('schema_children', 'children_owner_foreign');
            self::fail('SQLite FK changes require an explicit table rebuild.');
        } catch (SchemaException $exception) {
            self::assertStringContainsString('unsupported', $exception->getMessage());
        }
        self::assertCount(1, $schema->foreignKeys('schema_children'));
    }

    public function testSqliteGeneratedIndexNameMayExceedPortableDeclarationLimit(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for isolated schema tests.');
        }
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main', 'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        $tableName = 'table_' . str_repeat('a', 54);
        $schema = $manager->schema();
        $schema->create($tableName, static function (Table $table): void {
            $table->id();
            $table->string('code');
            $table->unique('code', 'short_unique');
        });

        $indexes = $schema->indexes($tableName);
        self::assertCount(1, $indexes);
        self::assertStringStartsWith('sqlite_autoindex_', $indexes[0]['name']);
        self::assertGreaterThan(64, strlen($indexes[0]['name']));
        self::assertSame(['code'], $indexes[0]['columns']);
    }
}
