<?php

/* ===========================================================================
 * Copyright 2018 Zindex Software
 * Copyright 2026 noir-framework
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * ============================================================================ */

declare(strict_types=1);

namespace Noirapi\Database\SQL;

use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use Noirapi\Database\SQL\Clause\AggregateFunction;
use Noirapi\Database\SQL\Clause\BitsPart;
use Noirapi\Database\SQL\Clause\BitTest;
use Noirapi\Database\SQL\Clause\CallPart;
use Noirapi\Database\SQL\Clause\ColumnPart;
use Noirapi\Database\SQL\Clause\Condition;
use Noirapi\Database\SQL\Clause\DateArithmetic;
use Noirapi\Database\SQL\Clause\ExpressionPart;
use Noirapi\Database\SQL\Clause\FunctionName;
use Noirapi\Database\SQL\Clause\GroupPart;
use Noirapi\Database\SQL\Clause\HavingBetween;
use Noirapi\Database\SQL\Clause\HavingCondition;
use Noirapi\Database\SQL\Clause\HavingIn;
use Noirapi\Database\SQL\Clause\HavingInSelect;
use Noirapi\Database\SQL\Clause\HavingNested;
use Noirapi\Database\SQL\Clause\JoinClause;
use Noirapi\Database\SQL\Clause\JoinColumn;
use Noirapi\Database\SQL\Clause\JoinExpression;
use Noirapi\Database\SQL\Clause\JoinNested;
use Noirapi\Database\SQL\Clause\JsonPart;
use Noirapi\Database\SQL\Clause\OperatorPart;
use Noirapi\Database\SQL\Clause\OrderClause;
use Noirapi\Database\SQL\Clause\SelectColumn;
use Noirapi\Database\SQL\Clause\SqlFunction;
use Noirapi\Database\SQL\Clause\SubqueryPart;
use Noirapi\Database\SQL\Clause\UpdateColumn;
use Noirapi\Database\SQL\Clause\UpsertClause;
use Noirapi\Database\SQL\Clause\ValuePart;
use Noirapi\Database\SQL\Clause\WhereBetween;
use Noirapi\Database\SQL\Clause\WhereBits;
use Noirapi\Database\SQL\Clause\WhereColumn;
use Noirapi\Database\SQL\Clause\WhereExists;
use Noirapi\Database\SQL\Clause\WhereIn;
use Noirapi\Database\SQL\Clause\WhereInSelect;
use Noirapi\Database\SQL\Clause\WhereJsonContains;
use Noirapi\Database\SQL\Clause\WhereJsonExists;
use Noirapi\Database\SQL\Clause\WhereLike;
use Noirapi\Database\SQL\Clause\WhereNested;
use Noirapi\Database\SQL\Clause\WhereNop;
use Noirapi\Database\SQL\Clause\WhereNull;

use function array_diff;
use function array_map;
use function array_values;
use function explode;
use function get_debug_type;
use function implode;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function sprintf;
use function str_replace;
use function strtoupper;

/**
 * Generic ANSI-ish SQL compiler; dialects override the parts that differ.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") One small method per construct, overridable per dialect.
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Knows every clause value object it compiles.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") Hooks: dialects use parameters the base ignores.
 */
class Compiler
{
    /** Date format used for DateTimeInterface parameters. */
    protected string $dateFormat = 'Y-m-d H:i:s';

    /** sprintf() pattern used to quote table and column names. */
    protected string $wrapper = '"%s"';

    /** @var list<mixed> */
    protected array $params = [];

    /** @var (Closure(string): string)|null Set while compiling with inlined literals */
    protected ?Closure $quoter = null;

    public function select(SQLStatement $select): string
    {
        $sql = $select->getDistinct() ? 'SELECT DISTINCT ' : 'SELECT ';
        $sql .= $this->handleColumns($select->getColumns());
        $sql .= $this->handleInto($select->getIntoTable(), $select->getIntoDatabase());
        $sql .= ' FROM ';
        $sql .= $this->handleTables($select->getTables());
        $sql .= $this->handleJoins($select->getJoins());
        $sql .= $this->handleWheres($select->getWheres());
        $sql .= $this->handleGroupings($select->getGroupBy());
        $sql .= $this->handleOrderings($select->getOrder());
        $sql .= $this->handleHavings($select->getHaving());
        $sql .= $this->handleLimit($select->getLimit());
        $sql .= $this->handleOffset($select->getOffset());

        return $sql;
    }

    public function insert(SQLStatement $insert): string
    {
        $columns = $this->handleColumns($insert->getColumns());

        $sql = 'INSERT INTO ';
        $sql .= $this->handleTables($insert->getTables());
        $sql .= $columns === '*' ? '' : ' (' . $columns . ')';
        $sql .= $this->handleInsertRows($insert->getInsertRows());
        $sql .= $this->handleUpsert($insert->getUpsert(), $this->insertColumnNames($insert));

        return $sql;
    }

    /**
     * Compiles a SELECT with every value inlined as a literal instead of a parameter, for
     * statements that cannot take parameters (CREATE VIEW).
     *
     * @param Closure(string): string $quote Quotes a string literal, e.g. PDO::quote(...)
     *
     * @throws InvalidArgumentException When a value has no literal form
     */
    public function selectInline(SQLStatement $select, Closure $quote): string
    {
        $this->quoter = $quote;

        try {
            return $this->select($select);
        } finally {
            $this->quoter = null;
            $this->params = [];
        }
    }

    public function update(SQLStatement $update): string
    {
        $sql = 'UPDATE ';
        $sql .= $this->handleTables($update->getTables());
        $sql .= $this->handleJoins($update->getJoins());
        $sql .= $this->handleSetColumns($update->getUpdateColumns());
        $sql .= $this->handleWheres($update->getWheres());

        return $sql;
    }

    public function delete(SQLStatement $delete): string
    {
        $sql = 'DELETE ' . $this->handleTables($delete->getTables());
        $sql .= $sql === 'DELETE ' ? 'FROM ' : ' FROM ';
        $sql .= $this->handleTables($delete->getFrom());
        $sql .= $this->handleJoins($delete->getJoins());
        $sql .= $this->handleWheres($delete->getWheres());

        return $sql;
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    /**
     * @param array<string, string> $options Supported keys: dateFormat, wrapper
     *
     * @throws InvalidArgumentException On an unknown option
     */
    public function setOptions(array $options): void
    {
        foreach ($options as $name => $value) {
            match ($name) {
                'dateFormat' => $this->dateFormat = $value,
                'wrapper' => $this->wrapper = $value,
                default => throw new InvalidArgumentException('Unknown compiler option: ' . $name),
            };
        }
    }

    /**
     * Binds every value as a parameter and returns the comma separated placeholders.
     *
     * @param array<mixed> $params
     */
    public function params(array $params): string
    {
        return implode(', ', array_map($this->param(...), $params));
    }

    /**
     * @param array<string|Expression> $columns
     */
    public function columns(array $columns): string
    {
        return implode(', ', array_map($this->wrap(...), $columns));
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Returns the collected parameters and resets them for the next statement.
     *
     * @return list<mixed>
     */
    public function getParams(): array
    {
        $params = $this->params;
        $this->params = [];

        return $params;
    }

    protected function wrap(string|Expression $value): string
    {
        if ($value instanceof Expression) {
            return $this->handleExpressions($value->getExpressions());
        }

        $json = JsonPath::fromArrow($value);
        if ($json !== null) {
            return $this->jsonExtract($this->wrap($json[0]), $json[1]);
        }

        $wrapped = [];
        foreach (explode('.', $value) as $segment) {
            $wrapped[] = $segment === '*' ? $segment : sprintf($this->wrapper, $segment);
        }

        return implode('.', $wrapped);
    }

    /**
     * Stores a query parameter and returns its placeholder (expressions are inlined).
     */
    protected function param(mixed $value): string
    {
        if ($value instanceof Expression) {
            return $this->handleExpressions($value->getExpressions());
        }

        if ($value instanceof DateTimeInterface) {
            $value = $value->format($this->dateFormat);
        }

        if ($this->quoter !== null) {
            return $this->literal($value, $this->quoter);
        }

        $this->params[] = $value;

        return '?';
    }

    /**
     * @param Closure(string): string $quote
     *
     * @throws InvalidArgumentException When the value has no literal form
     */
    protected function literal(mixed $value, Closure $quote): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => $quote($value),
            default => throw new InvalidArgumentException('Cannot inline a ' . get_debug_type($value) . ' value'),
        };
    }

    /**
     * @param list<ExpressionPart> $expressions
     */
    protected function handleExpressions(array $expressions): string
    {
        $sql = [];
        foreach ($expressions as $expr) {
            $sql[] = match (true) {
                $expr instanceof ColumnPart => $this->wrap($expr->column),
                $expr instanceof OperatorPart => $expr->operator,
                $expr instanceof ValuePart => $this->param($expr->value),
                $expr instanceof GroupPart => '(' . $this->handleExpressions($expr->expression->getExpressions()) . ')',
                $expr instanceof SubqueryPart => '(' . $this->select($expr->subquery->getSQLStatement()) . ')',
                $expr instanceof AggregateFunction => $this->handleAggregateFunction($expr),
                $expr instanceof SqlFunction => $this->handleSqlFunction($expr),
                $expr instanceof CallPart => $this->handleCall($expr),
                $expr instanceof DateArithmetic => $this->handleDateArithmetic($expr),
                $expr instanceof BitsPart => $this->handleBits($expr),
                $expr instanceof JsonPart => $this->jsonExtract($this->wrap($expr->column), $expr->path),
                default => throw new LogicException('Unsupported expression part: ' . get_debug_type($expr)),
            };
        }

        return implode(' ', $sql);
    }

    protected function handleAggregateFunction(AggregateFunction $func): string
    {
        $columns = $func->columns;
        $column = $func->name->value === 'COUNT' ? $this->columns($columns) : $this->wrap($columns[0]);

        return $func->name->value . '(' . ($func->distinct ? 'DISTINCT ' : '') . $column . ')';
    }

    protected function handleCall(CallPart $call): string
    {
        return $call->name . '(' . $this->params($call->args) . ')';
    }

    protected function handleSqlFunction(SqlFunction $func): string
    {
        return match ($func->name) {
            FunctionName::Ucase => $this->sqlFunctionUCASE($func),
            FunctionName::Lcase => $this->sqlFunctionLCASE($func),
            FunctionName::Mid => $this->sqlFunctionMID($func),
            FunctionName::Len => $this->sqlFunctionLEN($func),
            FunctionName::Round => $this->sqlFunctionROUND($func),
            FunctionName::Now => $this->sqlFunctionNOW($func),
            FunctionName::Format => $this->sqlFunctionFORMAT($func),
            FunctionName::CurrentDate => $this->sqlFunctionCURRENTDATE($func),
            FunctionName::Inet6Aton, FunctionName::Inet6Ntoa, FunctionName::InetAton, FunctionName::InetNtoa
                => $this->sqlFunctionINET($func),
        };
    }

    /**
     * `(col & ~clear) | set`, leaving out the parts that are 0.
     */
    protected function handleBits(BitsPart $bits): string
    {
        $sql = $this->wrap($bits->column);
        if ($bits->clear !== 0) {
            $sql = '(' . $sql . ' & ~' . $this->param($bits->clear) . ')';
        }

        return $bits->set === 0 ? $sql : $sql . ' | ' . $this->param($bits->set);
    }

    /**
     * DATE_ADD(date, INTERVAL n UNIT) / DATE_SUB(...), the MySQL form.
     */
    protected function handleDateArithmetic(DateArithmetic $date): string
    {
        return ($date->subtract ? 'DATE_SUB(' : 'DATE_ADD(') . $this->wrap($date->date)
            . ', INTERVAL ' . $this->dateAmount($date->amount) . ' ' . $this->intervalUnit($date->unit) . ')';
    }

    /**
     * A bound number, or a parenthesized expression.
     */
    protected function dateAmount(int|Expression $amount): string
    {
        return is_int($amount) ? $this->param($amount) : '(' . $this->wrap($amount) . ')';
    }

    protected function intervalUnit(Interval $unit): string
    {
        return strtoupper($unit->name);
    }

    /**
     * @param array<int|string, string|Expression> $tables name => alias, or a list of names
     */
    protected function handleTables(array $tables): string
    {
        $sql = [];
        foreach ($tables as $name => $alias) {
            $sql[] = is_string($name) ? $this->wrap($name) . ' AS ' . $this->wrap($alias) : $this->wrap($alias);
        }

        return implode(', ', $sql);
    }

    /**
     * @param list<SelectColumn> $columns
     */
    protected function handleColumns(array $columns): string
    {
        if ($columns === []) {
            return '*';
        }

        $sql = [];
        foreach ($columns as $column) {
            $sql[] = $column->alias !== null
                ? $this->wrap($column->name) . ' AS ' . $this->wrap($column->alias)
                : $this->wrap($column->name);
        }

        return implode(', ', $sql);
    }

    protected function handleInto(?string $table, ?string $database): string
    {
        if ($table === null) {
            return '';
        }

        return ' INTO ' . $this->wrap($table) . ($database === null ? '' : ' IN ' . $this->wrap($database));
    }

    /**
     * @param list<Condition> $wheres
     */
    protected function handleWheres(array $wheres, bool $prefix = true): string
    {
        if ($wheres === []) {
            return '';
        }

        return ($prefix ? ' WHERE ' : '') . $this->handleConditions($wheres);
    }

    /**
     * @param list<string|Expression> $grouping
     */
    protected function handleGroupings(array $grouping): string
    {
        return $grouping === [] ? '' : ' GROUP BY ' . $this->columns($grouping);
    }

    /**
     * @param list<JoinClause> $joins
     */
    protected function handleJoins(array $joins): string
    {
        if ($joins === []) {
            return '';
        }

        $sql = [];
        foreach ($joins as $join) {
            $conditions = $join->join === null ? '' : $this->handleJoinConditions($join->join->getJoinConditions());
            $sql[] = $join->type . ' JOIN ' . $this->handleTables($join->tables)
                . ($conditions === '' ? '' : ' ON ' . $conditions);
        }

        return ' ' . implode(' ', $sql);
    }

    /**
     * @param list<Condition> $conditions
     */
    protected function handleJoinConditions(array $conditions): string
    {
        return $this->handleConditions($conditions);
    }

    /**
     * @param list<Condition> $havings
     */
    protected function handleHavings(array $havings, bool $prefix = true): string
    {
        if ($havings === []) {
            return '';
        }

        return ($prefix ? ' HAVING ' : '') . $this->handleConditions($havings);
    }

    /**
     * @param list<OrderClause> $ordering
     */
    protected function handleOrderings(array $ordering): string
    {
        if ($ordering === []) {
            return '';
        }

        $sql = [];
        foreach ($ordering as $order) {
            if ($order->nulls !== null) {
                [$isNull, $notNull] = $order->nulls === 'NULLS FIRST' ? [0, 1] : [1, 0];
                foreach ($order->columns as $column) {
                    $sql[] = '(CASE WHEN ' . $this->wrap($column) . ' IS NULL THEN ' . $isNull . ' ELSE '
                        . $notNull . ' END)';
                }
            }

            $sql[] = $this->columns($order->columns) . ' ' . $order->order;
        }

        return ' ORDER BY ' . implode(', ', $sql);
    }

    /**
     * `col->path` keys update a path inside a JSON column; all paths of one column are set by
     * a single assignment.
     *
     * @param list<UpdateColumn> $columns
     *
     * @throws InvalidArgumentException When a column is set both whole and by JSON path
     */
    protected function handleSetColumns(array $columns): string
    {
        if ($columns === []) {
            return '';
        }

        /** @var array<string, UpdateColumn|list<array{JsonPath, mixed}>> $assignments */
        $assignments = [];
        foreach ($columns as $column) {
            $json = JsonPath::fromArrow($column->column);
            if ($json === null) {
                $assignments[$column->column] = isset($assignments[$column->column])
                    ? throw new InvalidArgumentException('Column "' . $column->column . '" is set twice')
                    : $column;
                continue;
            }

            $current = $assignments[$json[0]] ?? [];
            if ($current instanceof UpdateColumn) {
                throw new InvalidArgumentException('Column "' . $json[0] . '" is set both whole and by JSON path');
            }
            $current[] = [$json[1], $column->value];
            $assignments[$json[0]] = $current;
        }

        $sql = [];
        foreach ($assignments as $name => $assignment) {
            $sql[] = $assignment instanceof UpdateColumn
                ? $this->wrap($assignment->column) . ' = ' . $this->param($assignment->value)
                : $this->wrap($name) . ' = ' . $this->jsonSet($this->wrap($name), $assignment);
        }

        return ' SET ' . implode(', ', $sql);
    }

    /**
     * The scalar at the path as text: JSON_VALUE (MySQL 8.0.21+, MariaDB, SQL Server).
     */
    protected function jsonExtract(string $column, JsonPath $path): string
    {
        return 'JSON_VALUE(' . $column . ', ' . $this->quote($path->dollar()) . ')';
    }

    /**
     * JSON_SET(col, path, value, ...). Strings are bound as they are; other values are sent
     * as JSON text through JSON_EXTRACT(?, '$'), since MariaDB would store a bound number as
     * a string. Parent objects must already exist.
     *
     * @param list<array{JsonPath, mixed}> $paths
     */
    protected function jsonSet(string $column, array $paths): string
    {
        $sql = 'JSON_SET(' . $column;
        /** @var mixed $value */
        foreach ($paths as [$path, $value]) {
            $sql .= ', ' . $this->quote($path->dollar()) . ', ' . $this->jsonValue($value, "JSON_EXTRACT(%s, '$')");
        }

        return $sql . ')';
    }

    /**
     * An expression inline, a string as a parameter, anything else as a JSON-encoded parameter
     * placed into $format.
     */
    protected function jsonValue(mixed $value, string $format): string
    {
        if ($value instanceof Expression || is_string($value)) {
            return $this->param($value);
        }

        return sprintf($format, $this->param($this->jsonEncode($value)));
    }

    /**
     * The wrapped column and the arrow path, if any.
     *
     * @return array{string, JsonPath|null}
     */
    protected function jsonTarget(string|Expression $column): array
    {
        $json = is_string($column) ? JsonPath::fromArrow($column) : null;

        return $json === null ? [$this->wrap($column), null] : [$this->wrap($json[0]), $json[1]];
    }

    /**
     * @throws \JsonException
     */
    protected function jsonEncode(mixed $value): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

        return json_encode($value, JSON_THROW_ON_ERROR | $flags);
    }

    /**
     * @param list<list<mixed>> $rows
     */
    protected function handleInsertRows(array $rows): string
    {
        return ' VALUES ' . implode(', ', array_map(fn (array $row): string => '(' . $this->params($row) . ')', $rows));
    }

    /**
     * @return list<string>
     */
    protected function insertColumnNames(SQLStatement $insert): array
    {
        $names = [];
        foreach ($insert->getColumns() as $column) {
            if (is_string($column->name)) {
                $names[] = $column->name;
            }
        }

        return $names;
    }

    /**
     * Resolves the SET list of an upsert: column names take the inserted value.
     *
     * @param list<string> $columns The inserted columns
     *
     * @return list<string|UpdateColumn>
     */
    protected function upsertAssignments(UpsertClause $upsert, array $columns): array
    {
        return $upsert->update ?? array_values(array_diff($columns, $upsert->keys));
    }

    /**
     * ON CONFLICT (PostgreSQL, SQLite 3.24+).
     *
     * @param list<string> $columns The inserted columns
     *
     * @throws LogicException When updating without conflict keys
     */
    protected function handleUpsert(?UpsertClause $upsert, array $columns): string
    {
        if ($upsert === null) {
            return '';
        }

        $target = $upsert->keys === [] ? '' : ' (' . $this->columns($upsert->keys) . ')';
        $assignments = $this->upsertAssignments($upsert, $columns);
        if ($assignments === []) {
            return ' ON CONFLICT' . $target . ' DO NOTHING';
        }

        if ($target === '') {
            throw new LogicException('upsert() needs the conflict key columns to update on this driver');
        }

        $sql = [];
        foreach ($assignments as $assignment) {
            $sql[] = is_string($assignment)
                ? $this->wrap($assignment) . ' = excluded.' . $this->wrap($assignment)
                : $this->wrap($assignment->column) . ' = ' . $this->param($assignment->value);
        }

        return ' ON CONFLICT' . $target . ' DO UPDATE SET ' . implode(', ', $sql);
    }

    protected function handleLimit(int $limit): string
    {
        return $limit === 0 ? '' : ' LIMIT ' . $this->param($limit);
    }

    protected function handleOffset(int $offset): string
    {
        return $offset === -1 ? '' : ' OFFSET ' . $this->param($offset);
    }

    /**
     * Compiles conditions joined by their separators.
     *
     * @param list<Condition> $conditions
     */
    protected function handleConditions(array $conditions): string
    {
        $sql = [];
        foreach ($conditions as $i => $condition) {
            $sql[] = ($i === 0 ? '' : $condition->separator . ' ') . $this->handleCondition($condition);
        }

        return implode(' ', $sql);
    }

    protected function handleCondition(Condition $condition): string
    {
        return match (true) {
            $condition instanceof WhereColumn => $this->whereColumn($condition),
            $condition instanceof WhereIn => $this->whereIn($condition),
            $condition instanceof WhereInSelect => $this->whereInSelect($condition),
            $condition instanceof WhereNested => $this->whereNested($condition),
            $condition instanceof WhereExists => $this->whereExists($condition),
            $condition instanceof WhereNull => $this->whereNull($condition),
            $condition instanceof WhereBetween => $this->whereBetween($condition),
            $condition instanceof WhereLike => $this->whereLike($condition),
            $condition instanceof WhereNop => $this->whereNop($condition),
            $condition instanceof WhereBits => $this->whereBits($condition),
            $condition instanceof WhereJsonContains => $this->whereJsonContains($condition),
            $condition instanceof WhereJsonExists => $this->whereJsonExists($condition),
            $condition instanceof HavingCondition => $this->havingCondition($condition),
            $condition instanceof HavingNested => $this->havingNested($condition),
            $condition instanceof HavingBetween => $this->havingBetween($condition),
            $condition instanceof HavingInSelect => $this->havingInSelect($condition),
            $condition instanceof HavingIn => $this->havingIn($condition),
            $condition instanceof JoinColumn => $this->joinColumn($condition),
            $condition instanceof JoinNested => $this->joinNested($condition),
            $condition instanceof JoinExpression => $this->joinExpression($condition),
            default => throw new LogicException('Unsupported condition: ' . get_debug_type($condition)),
        };
    }

    protected function joinColumn(JoinColumn $join): string
    {
        return $this->wrap($join->column1) . ' ' . $join->operator . ' ' . $this->wrap($join->column2);
    }

    protected function joinNested(JoinNested $join): string
    {
        return '(' . $this->handleJoinConditions($join->join->getJoinConditions()) . ')';
    }

    protected function joinExpression(JoinExpression $join): string
    {
        return $this->wrap($join->expression);
    }

    protected function whereColumn(WhereColumn $where): string
    {
        return $this->wrap($where->column) . ' ' . $where->operator . ' ' . $this->param($where->value);
    }

    protected function whereIn(WhereIn $where): string
    {
        return $this->wrap($where->column) . ' ' . ($where->not ? 'NOT IN ' : 'IN ')
            . '(' . $this->params($where->values) . ')';
    }

    protected function whereInSelect(WhereInSelect $where): string
    {
        return $this->wrap($where->column) . ' ' . ($where->not ? 'NOT IN ' : 'IN ')
            . '(' . $this->select($where->subquery->getSQLStatement()) . ')';
    }

    protected function whereNested(WhereNested $where): string
    {
        return '(' . $this->handleWheres($where->conditions, false) . ')';
    }

    protected function whereExists(WhereExists $where): string
    {
        return ($where->not ? 'NOT EXISTS ' : 'EXISTS ')
            . '(' . $this->select($where->subquery->getSQLStatement()) . ')';
    }

    protected function whereNull(WhereNull $where): string
    {
        return $this->wrap($where->column) . ' ' . ($where->not ? 'IS NOT NULL' : 'IS NULL');
    }

    protected function whereBetween(WhereBetween $where): string
    {
        return $this->wrap($where->column) . ' ' . ($where->not ? 'NOT BETWEEN' : 'BETWEEN') . ' '
            . $this->param($where->value1) . ' AND ' . $this->param($where->value2);
    }

    protected function whereLike(WhereLike $where): string
    {
        return $this->wrap($where->column) . ' ' . ($where->not ? 'NOT LIKE' : 'LIKE') . ' '
            . $this->param($where->pattern);
    }

    protected function whereBits(WhereBits $where): string
    {
        $column = $this->wrap($where->column);

        return match ($where->test) {
            BitTest::All => '(~' . $column . ' & ' . $this->param($where->mask) . ') = 0',
            BitTest::Any => '(' . $column . ' & ' . $this->param($where->mask) . ') != 0',
            BitTest::None => '(' . $column . ' & ' . $this->param($where->mask) . ') = 0',
        };
    }

    /**
     * JSON_CONTAINS(doc, json[, path]) (MySQL/MariaDB).
     */
    protected function whereJsonContains(WhereJsonContains $where): string
    {
        [$column, $path] = $this->jsonTarget($where->column);

        $value = $this->param($this->jsonEncode($where->value));

        return ($where->not ? 'NOT ' : '') . 'JSON_CONTAINS(' . $column . ', ' . $value
            . ($path === null ? '' : ', ' . $this->quote($path->dollar())) . ')';
    }

    /**
     * JSON_CONTAINS_PATH(doc, 'one', path) (MySQL/MariaDB).
     */
    protected function whereJsonExists(WhereJsonExists $where): string
    {
        [$column, $path] = $this->jsonTarget($where->column);

        return ($where->not ? 'NOT ' : '') . 'JSON_CONTAINS_PATH(' . $column . ", 'one', "
            . $this->quote($path?->dollar() ?? '$') . ')';
    }

    protected function whereNop(WhereNop $where): string
    {
        return $this->wrap($where->column);
    }

    protected function havingCondition(HavingCondition $having): string
    {
        return $this->wrap($having->aggregate) . ' ' . $having->operator . ' ' . $this->param($having->value);
    }

    protected function havingNested(HavingNested $having): string
    {
        return '(' . $this->handleHavings($having->conditions, false) . ')';
    }

    protected function havingBetween(HavingBetween $having): string
    {
        return $this->wrap($having->aggregate) . ($having->not ? ' NOT BETWEEN ' : ' BETWEEN ')
            . $this->param($having->value1) . ' AND ' . $this->param($having->value2);
    }

    protected function havingInSelect(HavingInSelect $having): string
    {
        return $this->wrap($having->aggregate) . ($having->not ? ' NOT IN ' : ' IN ')
            . '(' . $this->select($having->subquery->getSQLStatement()) . ')';
    }

    protected function havingIn(HavingIn $having): string
    {
        return $this->wrap($having->aggregate) . ($having->not ? ' NOT IN ' : ' IN ')
            . '(' . $this->params($having->values) . ')';
    }

    protected function sqlFunctionUCASE(SqlFunction $func): string
    {
        return 'UCASE(' . $this->wrap($func->column) . ')';
    }

    protected function sqlFunctionLCASE(SqlFunction $func): string
    {
        return 'LCASE(' . $this->wrap($func->column) . ')';
    }

    protected function sqlFunctionMID(SqlFunction $func): string
    {
        return 'MID(' . $this->wrap($func->column) . ', ' . $this->param($func->start)
            . ($func->length > 0 ? ', ' . $this->param($func->length) : '') . ')';
    }

    protected function sqlFunctionLEN(SqlFunction $func): string
    {
        return 'LEN(' . $this->wrap($func->column) . ')';
    }

    protected function sqlFunctionROUND(SqlFunction $func): string
    {
        return 'ROUND(' . $this->wrap($func->column) . ', ' . $this->param($func->decimals) . ')';
    }

    protected function sqlFunctionNOW(SqlFunction $func): string
    {
        return 'NOW()';
    }

    protected function sqlFunctionFORMAT(SqlFunction $func): string
    {
        return 'FORMAT(' . $this->wrap($func->column) . ', ' . $this->param($func->format) . ')';
    }

    protected function sqlFunctionCURRENTDATE(SqlFunction $func): string
    {
        return 'CURRENT_DATE';
    }

    /**
     * INET6_ATON / INET6_NTOA / INET_ATON / INET_NTOA, which only MySQL and MariaDB have.
     */
    protected function sqlFunctionINET(SqlFunction $func): string
    {
        return $func->name->value . '(' . $this->wrap($func->column) . ')';
    }

    /**
     * For dialects without a function: fails at compile time instead of sending invalid SQL.
     *
     * @throws LogicException Always
     */
    protected function unsupportedFunction(SqlFunction $func): never
    {
        throw new LogicException($func->name->value . '() is not supported by ' . static::class);
    }
}
