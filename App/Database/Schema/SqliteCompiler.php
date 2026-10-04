<?php

declare(strict_types=1);

namespace App\Database\Schema;

/** Generates SQLite DDL, including separate statements for ordinary indexes. */
final class SqliteCompiler implements SchemaCompiler
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
            if ($index['unique']) {
                $definitions[] = 'CONSTRAINT ' . Sql::name($index['name'])
                    . ' UNIQUE (' . Sql::names($index['columns']) . ')';
            }
        }
        foreach ($table->foreignKeys() as $key) {
            $definitions[] = 'CONSTRAINT ' . Sql::name($key->name())
                . ' FOREIGN KEY (' . Sql::names($key->columns()) . ')'
                . ' REFERENCES ' . Sql::name($key->referencedTable())
                . ' (' . Sql::names($key->referencedColumns()) . ')'
                . ' ON DELETE ' . $key->deleteAction()
                . ' ON UPDATE ' . $key->updateAction();
        }

        $statements = ['CREATE TABLE ' . Sql::name($table->tableName()) . ' (' . implode(', ', $definitions) . ')'];
        foreach ($table->indexes() as $index) {
            if (!$index['unique']) {
                $statements[] = 'CREATE INDEX ' . Sql::name($index['name'])
                    . ' ON ' . Sql::name($table->tableName())
                    . ' (' . Sql::names($index['columns']) . ')';
            }
        }
        return $statements;
    }

    public function compileDrop(string $table, bool $ifExists = false): string
    {
        return 'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . Sql::name($table);
    }

    private function column(Column $column): string
    {
        $name = Sql::name($column->name());
        if ($column->type() === 'id') {
            return $name . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }
        $type = match ($column->type()) {
            'integer', 'foreign_id', 'boolean' => 'INTEGER',
            'string' => 'VARCHAR(' . $column->length() . ')',
            'text', 'datetime' => 'TEXT',
            default => throw new SchemaException("Column '{$column->name()}' has an unsupported type."),
        };
        $sql = $name . ' ' . $type . ($column->isNullable() ? ' NULL' : ' NOT NULL');
        if ($column->hasDefault()) {
            $sql .= ' DEFAULT ' . Sql::literal($column->defaultValue());
        }
        if ($column->type() === 'boolean') {
            $sql .= ' CHECK (' . $name . ' IN (0, 1))';
        }
        return $sql;
    }
}
