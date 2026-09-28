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
use InvalidArgumentException;
use Noirapi\Database\SQL\Clause\BitTest;
use Noirapi\Database\SQL\Clause\Condition;
use Noirapi\Database\SQL\Clause\HavingBetween;
use Noirapi\Database\SQL\Clause\HavingCondition;
use Noirapi\Database\SQL\Clause\HavingIn;
use Noirapi\Database\SQL\Clause\HavingInSelect;
use Noirapi\Database\SQL\Clause\HavingNested;
use Noirapi\Database\SQL\Clause\JoinClause;
use Noirapi\Database\SQL\Clause\OrderClause;
use Noirapi\Database\SQL\Clause\SelectColumn;
use Noirapi\Database\SQL\Clause\UpdateColumn;
use Noirapi\Database\SQL\Clause\UpsertClause;
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

use function array_keys;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function strtoupper;

/**
 * Mutable bag of clauses collected by the fluent statements and read by the compilers.
 *
 * @psalm-import-type ColumnArg from Expression
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") Collects every clause type of the fluent API.
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Creates every clause value object.
 * @SuppressWarnings("PHPMD.ExcessivePublicCount") An adder and a getter per clause kind; read by the compilers.
 * @SuppressWarnings("PHPMD.TooManyFields") One field per clause kind of SELECT/INSERT/UPDATE/DELETE.
 * @SuppressWarnings("PHPMD.BooleanGetMethodName") getDistinct() is public opis/database API.
 */
class SQLStatement
{
    /** @var list<Condition> */
    protected array $wheres = [];

    /** @var list<Condition> */
    protected array $having = [];

    /** @var list<JoinClause> */
    protected array $joins = [];

    /** @var array<int|string, string|Expression> */
    protected array $tables = [];

    /** @var list<SelectColumn> */
    protected array $columns = [];

    /** @var list<UpdateColumn> */
    protected array $updateColumns = [];

    /** @var list<OrderClause> */
    protected array $order = [];

    protected bool $distinct = false;

    /** @var list<string|Expression> */
    protected array $group = [];

    protected int $limit = 0;

    protected int $offset = -1;

    protected ?string $intoTable = null;

    protected ?string $intoDatabase = null;

    /** @var array<int|string, string|Expression> */
    protected array $from = [];

    /** @var list<mixed> */
    protected array $values = [];

    /** @var list<list<mixed>> Rows added after the first one (multi-row INSERT) */
    protected array $rows = [];

    protected ?UpsertClause $upsert = null;

    /**
     * @param Closure(WhereStatement): mixed $callback
     */
    public function addWhereConditionGroup(Closure $callback, string $separator): void
    {
        $where = new WhereStatement();
        $callback($where);
        $this->wheres[] = new WhereNested($where->getSQLStatement()->getWheres(), $separator);
    }

    /**
     * @param ColumnArg $column
     */
    public function addWhereCondition(
        string|Expression|Closure $column,
        mixed $value,
        string $operator,
        string $separator,
    ): void {
        $this->wheres[] = new WhereColumn(
            $this->toExpression($column),
            $this->valueToExpression($value),
            $operator,
            $separator,
        );
    }

    /**
     * @param ColumnArg $column
     */
    public function addWhereLikeCondition(
        string|Expression|Closure $column,
        string $pattern,
        string $separator,
        bool $not,
    ): void {
        $this->wheres[] = new WhereLike($this->toExpression($column), $pattern, $not, $separator);
    }

    /**
     * @param ColumnArg $column
     */
    public function addWhereBetweenCondition(
        string|Expression|Closure $column,
        mixed $value1,
        mixed $value2,
        string $separator,
        bool $not,
    ): void {
        $this->wheres[] = new WhereBetween(
            $this->toExpression($column),
            $this->valueToExpression($value1),
            $this->valueToExpression($value2),
            $not,
            $separator,
        );
    }

    /**
     * @param ColumnArg $column
     * @param array<mixed>|(Closure(Subquery): mixed) $value
     */
    public function addWhereInCondition(
        string|Expression|Closure $column,
        array|Closure $value,
        string $separator,
        bool $not,
    ): void {
        $column = $this->toExpression($column);

        if ($value instanceof Closure) {
            $this->wheres[] = new WhereInSelect($column, $this->subquery($value), $not, $separator);
            return;
        }

        $this->wheres[] = new WhereIn($column, array_values($value), $not, $separator);
    }

    /**
     * @param ColumnArg $column
     */
    public function addWhereNullCondition(string|Expression|Closure $column, string $separator, bool $not): void
    {
        $this->wheres[] = new WhereNull($this->toExpression($column), $not, $separator);
    }

    /**
     * @param ColumnArg $column
     */
    public function addWhereBitsCondition(
        string|Expression|Closure $column,
        int $mask,
        BitTest $test,
        string $separator,
    ): void {
        $this->wheres[] = new WhereBits($this->toExpression($column), $mask, $test, $separator);
    }

    /**
     * @param ColumnArg $column
     */
    public function addWhereJsonContainsCondition(
        string|Expression|Closure $column,
        mixed $value,
        string $separator,
        bool $not,
    ): void {
        $this->wheres[] = new WhereJsonContains($this->toExpression($column), $value, $not, $separator);
    }

    /**
     * @param ColumnArg $column An arrow path such as `meta->a->b`
     *
     * @throws InvalidArgumentException When the column is not an arrow path
     */
    public function addWhereJsonExistsCondition(string|Expression|Closure $column, string $separator, bool $not): void
    {
        if (!is_string($column) || JsonPath::fromArrow($column) === null) {
            throw new InvalidArgumentException('jsonExists() needs an arrow path column such as "meta->key"');
        }

        $this->wheres[] = new WhereJsonExists($column, $not, $separator);
    }

    /**
     * @param ColumnArg $column
     */
    public function addWhereNop(string|Expression|Closure $column, string $separator): void
    {
        $this->wheres[] = new WhereNop($this->toExpression($column), $separator);
    }

    /**
     * @param Closure(Subquery): mixed $closure
     */
    public function addWhereExistsCondition(Closure $closure, string $separator, bool $not): void
    {
        $this->wheres[] = new WhereExists($this->subquery($closure), $not, $separator);
    }

    /**
     * @param string|Expression|array<int|string, string|Expression>|(Closure(Expression): mixed) $table
     * @param (Closure(Join): mixed)|null $closure
     */
    public function addJoinClause(string $type, string|Expression|array|Closure $table, ?Closure $closure = null): void
    {
        $join = null;
        if ($closure !== null) {
            $join = new Join();
            $closure($join);
        }

        if ($table instanceof Closure) {
            $table = Expression::fromClosure($table);
        }

        $this->joins[] = new JoinClause($type, is_array($table) ? $table : [$table], $join);
    }

    /**
     * @param Closure(HavingStatement): mixed $callback
     */
    public function addHavingGroupCondition(Closure $callback, string $separator): void
    {
        $having = new HavingStatement();
        $callback($having);
        $this->having[] = new HavingNested($having->getSQLStatement()->getHaving(), $separator);
    }

    /**
     * @param ColumnArg $aggregate
     */
    public function addHavingCondition(
        string|Expression|Closure $aggregate,
        mixed $value,
        string $operator,
        string $separator,
    ): void {
        $this->having[] = new HavingCondition(
            $this->toExpression($aggregate),
            $this->valueToExpression($value),
            $operator,
            $separator,
        );
    }

    /**
     * @param ColumnArg $aggregate
     * @param array<mixed>|(Closure(Subquery): mixed) $value
     */
    public function addHavingInCondition(
        string|Expression|Closure $aggregate,
        array|Closure $value,
        string $separator,
        bool $not,
    ): void {
        $aggregate = $this->toExpression($aggregate);

        if ($value instanceof Closure) {
            $this->having[] = new HavingInSelect($aggregate, $this->subquery($value), $not, $separator);
            return;
        }

        $this->having[] = new HavingIn($aggregate, array_values($value), $not, $separator);
    }

    /**
     * @param ColumnArg $aggregate
     */
    public function addHavingBetweenCondition(
        string|Expression|Closure $aggregate,
        mixed $value1,
        mixed $value2,
        string $separator,
        bool $not,
    ): void {
        $this->having[] = new HavingBetween(
            $this->toExpression($aggregate),
            $this->valueToExpression($value1),
            $this->valueToExpression($value2),
            $not,
            $separator,
        );
    }

    /**
     * @param array<int|string, string|Expression> $tables
     */
    public function addTables(array $tables): void
    {
        $this->tables = $tables;
    }

    /**
     * @param array<array-key, mixed> $columns column => value
     */
    public function addUpdateColumns(array $columns): void
    {
        foreach (array_keys($columns) as $column) {
            $this->updateColumns[] = new UpdateColumn((string) $column, $this->valueToExpression($columns[$column]));
        }
    }

    /**
     * @param list<ColumnArg> $columns
     */
    public function addOrder(array $columns, string $order, ?string $nulls = null): void
    {
        $order = strtoupper($order);
        if ($order !== 'ASC' && $order !== 'DESC') {
            $order = 'ASC';
        }

        if ($nulls !== null) {
            $nulls = strtoupper($nulls);
            if (!in_array($nulls, ['NULLS FIRST', 'NULLS LAST'], true)) {
                $nulls = null;
            }
        }

        $this->order[] = new OrderClause(array_map($this->toExpression(...), $columns), $order, $nulls);
    }

    /**
     * @param list<ColumnArg> $columns
     */
    public function addGroupBy(array $columns): void
    {
        $this->group = array_map($this->toExpression(...), $columns);
    }

    /**
     * @param ColumnArg $column
     */
    public function addColumn(string|Expression|Closure $column, ?string $alias = null): void
    {
        $this->columns[] = new SelectColumn($this->toExpression($column), $alias);
    }

    public function setDistinct(bool $value): void
    {
        $this->distinct = $value;
    }

    public function setLimit(int $value): void
    {
        $this->limit = $value;
    }

    public function setOffset(int $value): void
    {
        $this->offset = $value;
    }

    public function setInto(string $table, ?string $database = null): void
    {
        $this->intoTable = $table;
        $this->intoDatabase = $database;
    }

    /**
     * @param array<int|string, string|Expression> $from
     */
    public function setFrom(array $from): void
    {
        $this->from = $from;
    }

    public function addValue(mixed $value): void
    {
        $this->values[] = $this->valueToExpression($value);
    }

    /**
     * @return list<Condition>
     */
    public function getWheres(): array
    {
        return $this->wheres;
    }

    /**
     * @return list<Condition>
     */
    public function getHaving(): array
    {
        return $this->having;
    }

    /**
     * @return list<JoinClause>
     */
    public function getJoins(): array
    {
        return $this->joins;
    }

    public function getDistinct(): bool
    {
        return $this->distinct;
    }

    /**
     * @return array<int|string, string|Expression>
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * @return list<SelectColumn>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<UpdateColumn>
     */
    public function getUpdateColumns(): array
    {
        return $this->updateColumns;
    }

    /**
     * @return list<OrderClause>
     */
    public function getOrder(): array
    {
        return $this->order;
    }

    /**
     * @return list<string|Expression>
     */
    public function getGroupBy(): array
    {
        return $this->group;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getIntoTable(): ?string
    {
        return $this->intoTable;
    }

    public function getIntoDatabase(): ?string
    {
        return $this->intoDatabase;
    }

    /**
     * @return array<int|string, string|Expression>
     */
    public function getFrom(): array
    {
        return $this->from;
    }

    /**
     * @return list<mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * Adds a whole INSERT row, its values in column order.
     *
     * @param array<mixed> $values
     */
    public function addValues(array $values): void
    {
        $values = array_values(array_map($this->valueToExpression(...), $values));
        if ($this->values === [] && $this->rows === []) {
            $this->values = $values;

            return;
        }

        $this->rows[] = $values;
    }

    /**
     * All INSERT rows: the one built by addValue() / the first addValues(), then the others.
     *
     * @return list<list<mixed>>
     */
    public function getInsertRows(): array
    {
        return [$this->values, ...$this->rows];
    }

    /**
     * @param list<string> $keys
     * @param array<int|string, mixed>|null $update column names, and/or column => value
     *
     * @throws InvalidArgumentException When a list entry is not a column name
     */
    public function setUpsert(array $keys, ?array $update): void
    {
        if ($update !== null) {
            $columns = [];
            /** @var mixed $value */
            foreach ($update as $column => $value) {
                if (is_string($column)) {
                    $columns[] = new UpdateColumn($column, $this->valueToExpression($value));
                    continue;
                }

                $columns[] = is_string($value)
                    ? $value
                    : throw new InvalidArgumentException('upsert() column names must be strings');
            }
            $update = $columns;
        }

        $this->upsert = new UpsertClause($keys, $update);
    }

    public function getUpsert(): ?UpsertClause
    {
        return $this->upsert;
    }

    /**
     * @param ColumnArg $value
     */
    protected function toExpression(string|Expression|Closure $value): string|Expression
    {
        return $value instanceof Closure ? Expression::fromClosure($value) : $value;
    }

    /**
     * Values may be closures building an expression; anything else is a bound parameter.
     */
    protected function valueToExpression(mixed $value): mixed
    {
        if ($value instanceof Closure) {
            /** @var Closure(Expression): mixed $value */
            return Expression::fromClosure($value);
        }

        return $value;
    }

    /**
     * @param Closure(Subquery): mixed $closure
     */
    private function subquery(Closure $closure): Subquery
    {
        $select = new Subquery();
        $closure($select);

        return $select;
    }
}
