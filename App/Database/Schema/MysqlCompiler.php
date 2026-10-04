<?php

declare(strict_types=1);

namespace App\Database\Schema;

/** Generates MySQL/InnoDB DDL with a parent-compatible unsigned foreign ID. */
final class MysqlCompiler implements SchemaCompiler
{
    public function compileCreate(Table $table): array
    {
        $table->validate();
        $definitions = [];
        foreach ($table->columns() as $column) {
            $definitions[] = $this->column($column);
        }
        if ($table->primaryColumns() !== []) {
            $definitions[] = 'PRIMARY KEY (' . Sql::names($table->primaryColumns()) . ')';
        }
        foreach ($table->indexes() as $index) {
            $definitions[] = $index['unique']
                ? 'CONSTRAINT ' . Sql::name($index['name']) . ' UNIQUE (' . Sql::names($index['columns']) . ')'
                : 'INDEX ' . Sql::name($index['name']) . ' (' . Sql::names($index['columns']) . ')';
        }
        foreach ($table->foreignKeys() as $key) {
            $definitions[] = 'CONSTRAINT ' . Sql::name($key->name())
                . ' FOREIGN KEY (' . Sql::names($key->columns()) . ')'
                . ' REFERENCES ' . Sql::name($key->referencedTable())
                . ' (' . Sql::names($key->referencedColumns()) . ')'
                . ' ON DELETE ' . $key->deleteAction()
                . ' ON UPDATE ' . $key->updateAction();
        }
        return ['CREATE TABLE ' . Sql::name($table->tableName()) . ' (' . implode(', ', $definitions)
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'];
    }

    public function compileDrop(string $table, bool $ifExists = false): string
    {
        return 'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . Sql::name($table);
    }

    private function column(Column $column): string
    {
        $name = Sql::name($column->name());
        if ($column->type() === 'id') {
            return $name . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        }
        $type = match ($column->type()) {
            'integer' => 'INT',
            'foreign_id' => 'BIGINT UNSIGNED',
            'string' => 'VARCHAR(' . $column->length() . ')',
            'text' => 'TEXT',
            'boolean' => 'TINYINT(1)',
            'datetime' => 'DATETIME',
            default => throw new SchemaException("Column '{$column->name()}' has an unsupported type."),
        };
        $sql = $name . ' ' . $type . ($column->isNullable() ? ' NULL' : ' NOT NULL');
        if ($column->hasDefault()) {
            $sql .= ' DEFAULT ' . Sql::literal($column->defaultValue());
        }
        return $sql;
    }
}
