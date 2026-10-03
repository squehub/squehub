<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Exception\QueryException;
use App\Database\Pagination\CursorCodec;
use App\Database\Pagination\CursorPage;
use App\Database\Pagination\Page;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Builds table queries with validated identifiers and bound values.
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'];

    private Connection $connection;
    private string $table;
    private array $columns = ['*'];
    /** @var array<string, array{sql: string, bindings: array}> Correlated read projections never affect writes or aggregate counts. */
    private array $countProjections = [];
    private bool $isDistinct = false;
    private array $joins = [];
    private array $filters = [];
    private array $groups = [];
    private array $havings = [];
    private array $sorts = [];
    /** @var list<array{column: string, direction: string}> */
    private array $sortTerms = [];
    private ?int $rowLimit = null;
    private int $rowSkip = 0;
    private bool $hasManualSkip = false;
    private bool $allRowsAllowed = false;
    private bool $hasRestrictiveFilter = false;
    private ?string $lockMode = null;
    private bool $readAlias = false;

    public function __construct(Connection $connection, string $table)
    {
        $this->connection = $connection;
        $this->table = Identifier::table($table);
    }

    public function select(array $columns): self
    {
        if ($columns === []) {
            throw new InvalidArgumentException('Select requires at least one column.');
        }

        $this->columns = array_map(
            static fn (string $column): string => Identifier::column($column, true, true),
            array_values($columns)
        );

        return $this;
    }

    /** @internal Add a framework-owned correlated COUNT without accepting raw SQL from application input. */
    public function countProjection(string $alias, self $subquery): self
    {
        $alias = Identifier::simple($alias);
        if ($subquery->connection !== $this->connection) {
            throw new LogicException('Relation counts must use the same database connection.');
        }
        if (isset($this->countProjections[$alias]) || in_array($alias, $this->columns, true)) {
            throw new LogicException('A relation count projection alias is already selected.');
        }
        $this->countProjections[$alias] = [
            'sql' => '(' . $subquery->countSql() . ') AS ' . $alias,
            'bindings' => $subquery->predicateBindings(),
        ];
        return $this;
    }

    public function distinct(): self
    {
        $this->isDistinct = true;

        return $this;
    }

    public function join(string $table, string $left, string $operator, string $right): self
    {
        return $this->addJoin('INNER', $table, $left, $operator, $right);
    }

    public function leftJoin(string $table, string $left, string $operator, string $right): self
    {
        return $this->addJoin('LEFT', $table, $left, $operator, $right);
    }

    public function filter(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        $this->addComparison($this->filters, 'AND', $column, $operatorOrValue, $value, func_num_args(), true);

        return $this;
    }

    public function orFilter(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        $this->addComparison($this->filters, 'OR', $column, $operatorOrValue, $value, func_num_args(), true);

        return $this;
    }

    /** Keep a caller's OR alternatives inside one parenthesized predicate. */
    public function filterGroup(callable $callback): self
    {
        return $this->addFilterGroup('AND', $callback);
    }

    public function orFilterGroup(callable $callback): self
    {
        return $this->addFilterGroup('OR', $callback);
    }

    /** Compare two validated identifiers without treating the right side as a value. */
    public function filterColumn(string $left, string $operator, string $right): self
    {
        $this->filters[] = ['AND', Identifier::column($left) . ' ' . self::operator($operator)
            . ' ' . Identifier::column($right), []];
        // A column comparison may be a tautology (id = id), so it does not
        // authorize an otherwise unfiltered UPDATE or DELETE.
        return $this;
    }

    public function filterExists(self $subquery): self
    {
        return $this->addSubquery('EXISTS', $subquery);
    }

    public function filterNotExists(self $subquery): self
    {
        return $this->addSubquery('NOT EXISTS', $subquery);
    }

    /** @internal Relation predicates must constrain every prior OR branch. */
    public function requireExists(self $subquery, bool $negative = false): self
    {
        // A failed subquery must not regroup predicates on the caller's query.
        $candidate = clone $this;
        $candidate->encloseCurrentFilters();
        $candidate->addSubquery($negative ? 'NOT EXISTS' : 'EXISTS', $subquery);
        $this->filters = $candidate->filters;
        return $this;
    }

    public function filterInQuery(string $column, self $subquery): self
    {
        return $this->addSubquery(Identifier::column($column) . ' IN', $subquery, true);
    }

    public function filterNotInQuery(string $column, self $subquery): self
    {
        return $this->addSubquery(Identifier::column($column) . ' NOT IN', $subquery, true);
    }

    /** A scalar subquery must explicitly select one column and one row. */
    public function filterSubquery(string $column, string $operator, self $subquery): self
    {
        if ($subquery->connection !== $this->connection) {
            throw new LogicException('Subqueries must use the same database connection.');
        }
        if (count($subquery->columns) !== 1 || $subquery->columns === ['*']
            || $subquery->rowLimit !== 1 || $subquery->hasManualSkip || $subquery->lockMode !== null) {
            throw new LogicException('A scalar subquery must select one column and use limit(1) without skip or locks.');
        }
        $this->filters[] = ['AND', Identifier::column($column) . ' ' . self::operator($operator)
            . ' (' . $subquery->toSql() . ')', $subquery->bindings()];
        // A scalar subquery can match every row; it does not authorize a write.
        return $this;
    }

    public function filterIn(string $column, array $values): self
    {
        $this->addIn($column, $values, false);

        return $this;
    }

    public function filterNotIn(string $column, array $values): self
    {
        $this->addIn($column, $values, true);

        return $this;
    }

    public function filterBetween(string $column, mixed $minimum, mixed $maximum): self
    {
        $column = Identifier::column($column);
        $this->filters[] = ['AND', $column . ' BETWEEN ? AND ?', [
            self::binding($minimum), self::binding($maximum),
        ]];
        $this->hasRestrictiveFilter = true;

        return $this;
    }

    public function filterNull(string $column): self
    {
        $this->filters[] = ['AND', Identifier::column($column) . ' IS NULL', []];
        $this->hasRestrictiveFilter = true;

        return $this;
    }

    public function filterNotNull(string $column): self
    {
        $this->filters[] = ['AND', Identifier::column($column) . ' IS NOT NULL', []];
        $this->hasRestrictiveFilter = true;

        return $this;
    }

    /** @internal Keep a Model's required predicate outside any caller OR chain. */
    public function requireNull(string $column, bool $notNull = false): self
    {
        $this->encloseCurrentFilters();
        return $notNull ? $this->filterNotNull($column) : $this->filterNull($column);
    }

    /** @internal Keep a relation's correlation outside caller-supplied OR predicates. */
    public function requireColumn(string $left, string $operator, string $right): self
    {
        $this->requirePredicate(Identifier::column($left) . ' ' . self::operator($operator)
            . ' ' . Identifier::column($right), []);
        return $this;
    }

    /** @internal Mandatory polymorphic type filters cannot be escaped by OR. */
    public function requireValue(string $column, int|float|string|bool $value): self
    {
        $this->encloseCurrentFilters();
        return $this->filter($column, $value);
    }

    /** @internal A fixed read alias disambiguates self-referential EXISTS queries. */
    public function forRelationAlias(string $alias): self
    {
        if ($this->readAlias) {
            throw new LogicException('A relation subquery already has an alias.');
        }
        $query = clone $this;
        $query->table .= ' AS ' . Identifier::table($alias);
        $query->readAlias = true;
        return $query;
    }

    public function group(string|array $columns): self
    {
        $columns = is_array($columns) ? $columns : [$columns];
        if ($columns === []) {
            throw new InvalidArgumentException('Group requires at least one column.');
        }

        foreach ($columns as $column) {
            $this->groups[] = Identifier::column($column);
        }

        return $this;
    }

    public function having(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if ($this->groups === []) {
            throw new LogicException('Having requires a grouped query.');
        }

        $this->addComparison($this->havings, 'AND', $column, $operatorOrValue, $value, func_num_args(), false);

        return $this;
    }

    public function sort(string $column, string $direction = 'asc'): self
    {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new InvalidArgumentException('Sort direction must be asc or desc.');
        }

        $this->sorts[] = Identifier::column($column) . ' ' . $direction;
        $this->sortTerms[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    public function limit(int $count): self
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Limit must not be negative.');
        }

        $this->rowLimit = $count;

        return $this;
    }

    public function skip(int $count): self
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Skip must not be negative.');
        }

        $this->rowSkip = $count;
        $this->hasManualSkip = true;

        return $this;
    }

    public function toSql(): string
    {
        return $this->compileSelect();
    }

    public function bindings(): array
    {
        $bindings = [];
        foreach ($this->countProjections as $projection) {
            array_push($bindings, ...$projection['bindings']);
        }
        return array_merge($bindings, $this->predicateBindings());
    }

    public function all(): array
    {
        return $this->execute($this->toSql(), $this->bindings())->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Traverse a bounded forward keyset without a COUNT query. The caller
     * identifies the unique key for raw table queries; ModelQuery supplies its
     * Model's primary key. NULL sort values and unsupported query shapes are
     * rejected rather than producing ambiguous cursor boundaries.
     */
    public function cursorPage(int $limit, ?string $after = null, string $key = 'id'): CursorPage
    {
        if ($limit < 1 || $limit === PHP_INT_MAX) {
            throw new InvalidArgumentException('Cursor page size is outside the supported range.');
        }
        $key = Identifier::simple($key);
        if ($this->joins !== [] || $this->groups !== [] || $this->havings !== [] || $this->isDistinct
            || $this->rowLimit !== null || $this->hasManualSkip || $this->lockMode !== null) {
            throw new LogicException('cursorPage() requires a simple unbounded table query.');
        }

        $query = clone $this;
        $seen = [];
        foreach ($query->sortTerms as $term) {
            // The result column must be unambiguous so the next boundary can be read.
            if (str_contains($term['column'], '.') || isset($seen[$term['column']])) {
                throw new LogicException('Cursor ordering requires unique unqualified columns.');
            }
            $seen[$term['column']] = true;
        }
        if (!isset($seen[$key])) {
            $query->sort($key);
        }
        if ($query->columns !== ['*']) {
            foreach ($query->sortTerms as $term) {
                if (!in_array(Identifier::column($term['column']), $query->columns, true)) {
                    throw new LogicException('Cursor queries must select every ordered column without an alias.');
                }
            }
        }

        // Only a digest of the source query is persisted in the cursor. This
        // catches stale cross-query reuse without putting filter values in it.
        try {
            $fingerprintSource = json_encode([
                $this->connection->name(), $this->connection->driver(), $query->compileSelect(null, null, true, false),
                $query->bindings(), $query->sortTerms, $limit,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Cursor query cannot be encoded.', 0, $exception);
        }
        $fingerprint = hash('sha256', $fingerprintSource);
        if ($after !== null) {
            $position = CursorCodec::decode($after, $fingerprint, count($query->sortTerms));
            $terms = [];
            $bindings = [];
            foreach ($query->sortTerms as $index => $term) {
                $parts = [];
                for ($previous = 0; $previous < $index; $previous++) {
                    $parts[] = Identifier::column($query->sortTerms[$previous]['column']) . ' = ?';
                    $bindings[] = $position[$previous];
                }
                $parts[] = Identifier::column($term['column'])
                    . ($term['direction'] === 'ASC' ? ' > ?' : ' < ?');
                $bindings[] = $position[$index];
                $terms[] = '(' . implode(' AND ', $parts) . ')';
            }
            $query->requirePredicate('(' . implode(' OR ', $terms) . ')', $bindings);
        }
        $rows = $query->limit($limit + 1)->all();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $next = null;
        if ($hasMore) {
            $last = $rows[array_key_last($rows)];
            $position = [];
            foreach ($query->sortTerms as $term) {
                $column = $term['column'];
                if (!array_key_exists($column, $last) || $last[$column] === null) {
                    throw new LogicException('Cursor ordering requires non-NULL selected values.');
                }
                $position[] = $last[$column];
            }
            $next = CursorCodec::encode($fingerprint, $position);
        }
        return new CursorPage($rows, $limit, $next);
    }

    /** Locks selected rows until the caller-owned MySQL transaction ends. */
    public function lockForUpdate(): self
    {
        $this->lockMode = 'FOR UPDATE';
        return $this;
    }

    /** Acquires a shared MySQL row lock within a caller-owned transaction. */
    public function lockShared(): self
    {
        $this->lockMode = 'FOR SHARE';
        return $this;
    }

    /** Count the unbounded filtered table, then read only the requested rows. */
    public function page(int $page, int $perPage): Page
    {
        $this->assertNoLockForDerivedRead();
        $offset = Page::offset($page, $perPage);
        if ($this->rowLimit !== null || $this->hasManualSkip) {
            throw new LogicException('page() cannot be combined with limit() or skip().');
        }
        if ($this->joins !== [] || $this->groups !== [] || $this->havings !== [] || $this->isDistinct) {
            throw new LogicException('page() supports filtered table queries without joins, grouping, or distinct.');
        }
        $total = (clone $this)->count();
        $items = (clone $this)->limit($perPage)->skip($offset)->all();
        return new Page($items, $page, $perPage, $total);
    }

    public function first(): ?array
    {
        if ($this->rowLimit === 0) {
            return null;
        }

        $row = $this->execute($this->compileSelect(1), $this->bindings())->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function exists(): bool
    {
        if ($this->rowLimit === 0) {
            return false;
        }

        return $this->execute($this->compileSelect(1, '1'), $this->predicateBindings())->fetchColumn() !== false;
    }

    public function count(): int
    {
        $this->assertNoLockForDerivedRead();
        return (int) $this->execute($this->countSql(), $this->predicateBindings())->fetchColumn();
    }

    /** The count projection omits sorting and manual page bounds. */
    public function countSql(): string
    {
        $this->assertNoLockForDerivedRead();
        if ($this->groups !== [] || $this->havings !== [] || $this->isDistinct) {
            $inner = $this->compileSelect(null, null, false, false, false);
            return 'SELECT COUNT(*) FROM (' . $inner . ') AS squehub_count';
        }
        return 'SELECT COUNT(*)' . $this->compileFrom() . $this->compileConditions($this->filters, 'WHERE');
    }

    public function sum(string $column): int|float|string|null
    {
        return $this->aggregate('SUM', $column);
    }

    public function avg(string $column): int|float|string|null
    {
        return $this->aggregate('AVG', $column);
    }

    public function min(string $column): int|float|string|null
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): int|float|string|null
    {
        return $this->aggregate('MAX', $column);
    }

    public function insert(array $values): int
    {
        $this->assertNoLockForWrite();
        [$columns, $bindings] = $this->writeValues($values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO ' . $this->table . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';

        return $this->execute($sql, $bindings)->rowCount();
    }

    /**
     * Insert rows in bounded statements. All rows are validated before the
     * first write; multiple statements share a transaction or nested savepoint.
     * This avoids a partial batch when a later row violates a constraint.
     *
     * @param list<array<string, int|float|string|bool|null>> $rows
     */
    public function insertMany(array $rows): int
    {
        $this->assertNoLockForWrite();
        if ($rows === []) {
            throw new InvalidArgumentException('Bulk insert requires at least one row.');
        }
        [$columns] = $this->writeValues($rows[0]);
        $names = array_keys($rows[0]);
        $bindingsByRow = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== count($names)
                || array_diff($names, array_keys($row)) !== []) {
                throw new InvalidArgumentException('Bulk insert rows must contain the same named fields.');
            }
            $bindingsByRow[] = array_map(static fn (string $name): int|float|string|bool|null => self::binding($row[$name]), $names);
        }
        $parameterLimit = $this->connection->driver() === 'sqlite' ? 900 : 60000;
        if (count($columns) > $parameterLimit) {
            throw new InvalidArgumentException('Bulk insert row exceeds the backend parameter limit.');
        }
        $batchSize = max(1, intdiv($parameterLimit, count($columns)));
        $write = function () use ($columns, $bindingsByRow, $batchSize): int {
            $written = 0;
            foreach (array_chunk($bindingsByRow, $batchSize) as $batch) {
                $rowPlaceholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $sql = 'INSERT INTO ' . $this->table . ' (' . implode(', ', $columns) . ') VALUES '
                    . implode(', ', array_fill(0, count($batch), $rowPlaceholders));
                $bindings = [];
                foreach ($batch as $values) {
                    array_push($bindings, ...$values);
                }
                $written += $this->execute($sql, $bindings)->rowCount();
            }
            return $written;
        };
        return count($rows) > $batchSize ? $this->connection->transaction($write) : $write();
    }

    /**
     * Use backend-native atomic conflict handling. SQLite targets the supplied
     * unique columns; MySQL's ON DUPLICATE KEY applies to any unique conflict.
     * Row-count semantics are the backend's native affected-row semantics.
     *
     * @param array<string, int|float|string|bool|null> $values
     * @param list<string> $conflictColumns
     * @param list<string> $updateColumns
     */
    public function upsert(array $values, array $conflictColumns, array $updateColumns): int
    {
        $this->assertNoLockForWrite();
        [$columns, $bindings] = $this->writeValues($values);
        if ($conflictColumns === [] || $updateColumns === []) {
            throw new InvalidArgumentException('Upsert requires explicit conflict and update columns.');
        }
        foreach ([$conflictColumns, $updateColumns] as $names) {
            if (count($names) !== count(array_unique($names))) {
                throw new InvalidArgumentException('Upsert column lists must not contain duplicates.');
            }
            foreach ($names as $name) {
                Identifier::simple($name);
                if (!array_key_exists($name, $values)) {
                    throw new InvalidArgumentException('Upsert columns must exist in the inserted values.');
                }
            }
        }
        $sql = 'INSERT INTO ' . $this->table . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $assignments = [];
        foreach ($updateColumns as $name) {
            $assignments[] = Identifier::column($name) . ' = ?';
            $bindings[] = self::binding($values[$name]);
        }
        if ($this->connection->driver() === 'sqlite') {
            $target = array_map(static fn (string $name): string => Identifier::column($name), $conflictColumns);
            $sql .= ' ON CONFLICT (' . implode(', ', $target) . ') DO UPDATE SET ' . implode(', ', $assignments);
        } elseif ($this->connection->driver() === 'mysql') {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
        } else {
            throw new LogicException('Upsert is unsupported by this database driver.');
        }
        return $this->execute($sql, $bindings)->rowCount();
    }

    /**
     * Visit bounded batches in primary-key order. The callback may return false
     * to stop. Keyset traversal avoids offset skips during append-only growth;
     * callers should not mutate the ordering key while walking the data.
     */
    public function chunk(int $size, callable $callback, string $key = 'id'): int
    {
        if ($this->sortTerms !== []) {
            throw new LogicException('chunk() requires an unsorted query so the key controls traversal.');
        }
        $after = null;
        $batches = 0;
        do {
            $page = $this->cursorPage($size, $after, $key);
            if ($page->items() === []) {
                break;
            }
            ++$batches;
            if ($callback($page->items(), $batches) === false) {
                break;
            }
            $after = $page->nextCursor();
        } while ($after !== null);
        return $batches;
    }

    public function insertId(array $values): string
    {
        $this->insert($values);
        $id = $this->connection->pdo()->lastInsertId();
        if ($id === false) {
            throw new QueryException('The database did not return an inserted ID.');
        }

        return $id;
    }

    public function allowAll(): self
    {
        $this->allRowsAllowed = true;

        return $this;
    }

    public function update(array $values): int
    {
        $this->assertSafeWrite();
        [$columns, $bindings] = $this->writeValues($values);
        $assignments = array_map(static fn (string $column): string => $column . ' = ?', $columns);
        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $assignments)
            . $this->compileConditions($this->filters, 'WHERE');

        return $this->execute($sql, array_merge($bindings, $this->conditionBindings($this->filters)))->rowCount();
    }

    public function delete(): int
    {
        $this->assertSafeWrite();
        $sql = 'DELETE FROM ' . $this->table . $this->compileConditions($this->filters, 'WHERE');

        return $this->execute($sql, $this->conditionBindings($this->filters))->rowCount();
    }

    private function addJoin(string $type, string $table, string $left, string $operator, string $right): self
    {
        $table = Identifier::table($table, true);
        $left = Identifier::column($left);
        $right = Identifier::column($right);
        $operator = self::operator($operator);
        if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
            throw new InvalidArgumentException('Join operator is not supported.');
        }

        $this->joins[] = ' ' . $type . ' JOIN ' . $table . ' ON ' . $left . ' ' . $operator . ' ' . $right;

        return $this;
    }

    private function addFilterGroup(string $boolean, callable $callback): self
    {
        $group = new self($this->connection, 'squehub_group');
        $callback($group);
        if ($group->filters === [] || $group->joins !== [] || $group->groups !== []
            || $group->havings !== [] || $group->sorts !== [] || $group->columns !== ['*']
            || $group->isDistinct || $group->rowLimit !== null || $group->hasManualSkip
            || $group->lockMode !== null || $group->countProjections !== []) {
            throw new LogicException('A filter group may contain only predicates.');
        }
        $this->filters[] = [$boolean, '(' . substr($group->compileConditions($group->filters, 'WHERE'), 7) . ')',
            $group->conditionBindings($group->filters)];
        $this->hasRestrictiveFilter = $this->hasRestrictiveFilter || $group->hasRestrictiveFilter;
        return $this;
    }

    private function addSubquery(string $operator, self $subquery, bool $oneColumn = false): self
    {
        if ($subquery->connection !== $this->connection) {
            throw new LogicException('Subqueries must use the same database connection.');
        }
        if ($oneColumn && (count($subquery->columns) !== 1 || $subquery->columns === ['*'])) {
            throw new LogicException('IN subqueries must select exactly one column.');
        }
        $this->filters[] = ['AND', $operator . ' (' . $subquery->toSql() . ')', $subquery->bindings()];
        // An EXISTS subquery can be true for every row, so it does not by
        // itself authorize a write without allowAll().
        return $this;
    }

    /** Keep mandatory predicates outside an existing OR chain. */
    private function encloseCurrentFilters(): void
    {
        if ($this->filters === []) {
            return;
        }
        $parts = [];
        foreach ($this->filters as [$boolean, $sql]) {
            $parts[] = ($parts === [] ? '' : $boolean . ' ') . $sql;
        }
        $this->filters = [['AND', '(' . implode(' ', $parts) . ')', $this->conditionBindings($this->filters)]];
    }

    private function requirePredicate(string $sql, array $bindings): void
    {
        $this->encloseCurrentFilters();
        $this->filters[] = ['AND', $sql, $bindings];
        $this->hasRestrictiveFilter = true;
    }

    private function addComparison(array &$conditions, string $boolean, string $column, mixed $operatorOrValue,
        mixed $value, int $argumentCount, bool $restrictive): void
    {
        if ($argumentCount !== 2 && $argumentCount !== 3) {
            throw new InvalidArgumentException('Filters require a value or an operator and value.');
        }

        $column = Identifier::column($column);
        $operator = $argumentCount === 2 ? '=' : self::operator($operatorOrValue);
        $binding = self::binding($argumentCount === 2 ? $operatorOrValue : $value);
        if ($binding === null) {
            throw new InvalidArgumentException('Use filterNull or filterNotNull for NULL values.');
        }

        $conditions[] = [$boolean, $column . ' ' . $operator . ' ?', [$binding]];
        if ($restrictive) {
            $this->hasRestrictiveFilter = true;
        }
    }

    private function addIn(string $column, array $values, bool $negative): void
    {
        $column = Identifier::column($column);
        $values = array_map(static fn (mixed $value): mixed => self::binding($value), array_values($values));
        if ($values === []) {
            $this->filters[] = ['AND', $negative ? '1 = 1' : '0 = 1', []];
            if (!$negative) {
                $this->hasRestrictiveFilter = true;
            }

            return;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->filters[] = ['AND', $column . ($negative ? ' NOT IN (' : ' IN (') . $placeholders . ')', $values];
        $this->hasRestrictiveFilter = true;
    }

    private static function operator(mixed $operator): string
    {
        if (!is_string($operator)) {
            throw new InvalidArgumentException('Invalid database comparison operator.');
        }

        $operator = strtoupper(trim($operator));
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException('Invalid database comparison operator.');
        }

        return $operator;
    }

    private static function binding(mixed $value): int|float|string|bool|null
    {
        if ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException('Database bindings must be scalar values or NULL.');
        }

        return $value;
    }

    private function writeValues(array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Database writes require at least one field.');
        }

        $columns = [];
        $bindings = [];
        foreach ($values as $column => $value) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Database write fields must be named.');
            }

            $columns[] = Identifier::column($column);
            $bindings[] = self::binding($value);
        }

        return [$columns, $bindings];
    }

    private function assertSafeWrite(): void
    {
        if (!$this->hasRestrictiveFilter && !$this->allRowsAllowed) {
            throw new LogicException('Unfiltered update or delete requires allowAll().');
        }

        if ($this->joins !== [] || $this->groups !== [] || $this->havings !== []
            || $this->sorts !== [] || $this->rowLimit !== null || $this->rowSkip !== 0
            || $this->lockMode !== null || $this->readAlias) {
            throw new LogicException('This query shape is not supported for update or delete.');
        }
    }

    private function compileSelect(?int $limitOverride = null, ?string $projection = null,
        bool $includeOrder = true, bool $includeWindow = true, bool $includeCountProjections = true): string
    {
        $selection = $projection ?? implode(', ', $this->columns);
        if ($projection === null && $includeCountProjections) {
            foreach ($this->countProjections as $count) {
                $selection .= ', ' . $count['sql'];
            }
        }
        $sql = 'SELECT ' . ($this->isDistinct && $projection === null ? 'DISTINCT ' : '')
            . $selection
            . $this->compileFrom()
            . $this->compileConditions($this->filters, 'WHERE');

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }
        $sql .= $this->compileConditions($this->havings, 'HAVING');

        if ($includeOrder && $this->sorts !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->sorts);
        }

        if ($includeWindow) {
            $limit = $limitOverride === null ? $this->rowLimit : min($this->rowLimit ?? $limitOverride, $limitOverride);
            if ($limit !== null) {
                $sql .= ' LIMIT ' . $limit;
            } elseif ($this->rowSkip > 0) {
                // A bounded maximum works for both MySQL and SQLite when only skip() is set.
                $sql .= ' LIMIT 9223372036854775807';
            }
            if ($this->rowSkip > 0) {
                $sql .= ' OFFSET ' . $this->rowSkip;
            }
        }

        if ($this->lockMode !== null) {
            if ($this->connection->driver() !== 'mysql') {
                throw new LogicException('Row locks are supported only by the MySQL driver.');
            }
            if (!$this->connection->inTransaction() || !$this->connection->pdo()->inTransaction()) {
                throw new LogicException('Row locks require an active managed transaction.');
            }
            $sql .= ' ' . $this->lockMode;
        }

        return $sql;
    }

    private function compileFrom(): string
    {
        return ' FROM ' . $this->table . implode('', $this->joins);
    }

    private function compileConditions(array $conditions, string $clause): string
    {
        if ($conditions === []) {
            return '';
        }

        $parts = [];
        foreach ($conditions as [$boolean, $sql]) {
            $parts[] = ($parts === [] ? '' : $boolean . ' ') . $sql;
        }

        return ' ' . $clause . ' ' . implode(' ', $parts);
    }

    private function conditionBindings(array $conditions): array
    {
        $bindings = [];
        foreach ($conditions as [, , $values]) {
            array_push($bindings, ...$values);
        }

        return $bindings;
    }

    /** Aggregate and existence reads omit SELECT projections but keep all predicates. */
    private function predicateBindings(): array
    {
        return array_merge($this->conditionBindings($this->filters), $this->conditionBindings($this->havings));
    }

    private function aggregate(string $function, string $column): int|float|string|null
    {
        $this->assertNoLockForDerivedRead();
        if ($this->groups !== [] || $this->havings !== []) {
            throw new LogicException('Scalar aggregates do not support grouped queries.');
        }

        $column = Identifier::column($column);
        $sql = 'SELECT ' . $function . '(' . $column . ')'
            . $this->compileFrom()
            . $this->compileConditions($this->filters, 'WHERE');
        /** @var int|float|string|false $value PDO drivers differ for numeric aggregate results. */
        $value = $this->execute($sql, $this->conditionBindings($this->filters))->fetchColumn();

        return $value === false ? null : $value;
    }

    private function assertNoLockForDerivedRead(): void
    {
        if ($this->lockMode !== null) {
            throw new LogicException('Row locks require a direct select, not a count or aggregate.');
        }
    }

    private function assertNoLockForWrite(): void
    {
        if ($this->lockMode !== null) {
            throw new LogicException('Row locks cannot be combined with INSERT or UPSERT.');
        }
        if ($this->readAlias) {
            throw new LogicException('A relation read alias cannot be used for writes.');
        }
    }

    private function execute(string $sql, array $bindings): PDOStatement
    {
        $started = null;
        $succeeded = false;
        try {
            $pdo = $this->connection->pdo();
            $started = hrtime(true);
            $statement = $pdo->prepare($sql);
            if ($statement === false) {
                throw new QueryException('Unable to prepare database query.');
            }
            foreach ($bindings as $index => $value) {
                $type = $value === null ? PDO::PARAM_NULL
                    : (is_bool($value) ? PDO::PARAM_BOOL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
                $statement->bindValue($index + 1, $value, $type);
            }
            $statement->execute();
            $succeeded = true;

            return $statement;
        } catch (PDOException $exception) {
            throw new QueryException('Database query failed.', 0, $exception);
        } finally {
            if ($started !== null) {
                $this->connection->recordQuery((hrtime(true) - $started) / 1_000_000,
                    Connection::sqlOperation($sql), !$succeeded);
            }
        }
    }
}
