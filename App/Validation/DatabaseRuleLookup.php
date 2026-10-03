<?php

declare(strict_types=1);

namespace App\Validation;

use App\Database\Database;
use App\Database\Identifier;
use PDO;

/** Batch lookup returns each candidate that the database itself matched. */
final class DatabaseRuleLookup
{
    public static function matching(array $values, string $table, string $column,
        ?string $ignoreColumn = null, int|string|null $ignoreValue = null): array
    {
        $tableSql = Identifier::table(Identifier::simple($table));
        $columnSql = Identifier::column(Identifier::simple($column));
        $ignoreSql = $ignoreColumn === null ? null : Identifier::column(Identifier::simple($ignoreColumn));
        $found = [];
        foreach (array_chunk($values, 500) as $batch) {
            if ($batch === []) continue;
            // Returning the bound candidate preserves column collation and affinity semantics.
            $candidates = 'SELECT ? AS candidate' . str_repeat(' UNION ALL SELECT ?', count($batch) - 1);
            $sql = "SELECT DISTINCT v.candidate FROM ({$candidates}) AS v"
                . " INNER JOIN {$tableSql} AS t ON t.{$columnSql} = v.candidate";
            $bindings = $batch;
            if ($ignoreSql !== null) {
                $sql .= " WHERE t.{$ignoreSql} != ?";
                $bindings[] = $ignoreValue;
            }
            foreach (Database::manager()->raw($sql, $bindings)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[(string) $row['candidate']] = true;
            }
        }
        return $found;
    }
}
