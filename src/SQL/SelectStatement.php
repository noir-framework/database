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

declare(strict_types=1);namespace Noirapi\Database\SQL;

use Closure;

use function is_array;

/**
 * Connection-less SELECT builder, used directly for sub-queries.
 *
 * Its terminal methods (`select()`, `column()`, `count()`, ...) only record the columns;
 * {@see Select} overrides them to run the query.
 *
 * @psalm-import-type ColumnArg from Expression
 */
class SelectStatement extends BaseStatement
{
    protected HavingStatement $have;

    /**
     * @param string|array<int|string, string|Expression> $tables
     */
    public function __construct(string|array $tables, ?SQLStatement $statement = null)
    {
        parent::__construct($statement);
        $this->sql->addTables(is_array($tables) ? $tables : [$tables]);
        $this->have = new HavingStatement($this->sql);
    }

    public function __clone()
    {
        parent::__clone();
        $this->have = new HavingStatement($this->sql);
    }

    public function into(string $table, ?string $database = null): static
    {
        $this->sql->setInto($table, $database);

        return $this;
    }

    public function distinct(bool $value = true): static
    {
        $this->sql->setDistinct($value);

        return $this;
    }

    /**
     * @param ColumnArg|list<ColumnArg> $columns
     */
    public function groupBy(string|Expression|Closure|array $columns): static
    {
        $this->sql->addGroupBy(is_array($columns) ? $columns : [$columns]);

        return $this;
    }

    /**
     * @param string|Expression|Closure $column
     * @param (Closure(HavingExpression): mixed)|null $value
     */
    public function having(string|Expression|Closure $column, ?Closure $value = null): static
    {
        $this->have->having($column, $value);

        return $this;
    }

    /**
     * @param string|Expression|Closure $column
     * @param (Closure(HavingExpression): mixed)|null $value
     */
    public function andHaving(string|Expression|Closure $column, ?Closure $value = null): static
    {
        $this->have->andHaving($column, $value);

        return $this;
    }

    /**
     * @param string|Expression|Closure $column
     * @param (Closure(HavingExpression): mixed)|null $value
     */
    public function orHaving(string|Expression|Closure $column, ?Closure $value = null): static
    {
        $this->have->orHaving($column, $value);

        return $this;
    }

    /**
     * @param ColumnArg|list<ColumnArg> $columns
     */
    public function orderBy(string|Expression|Closure|array $columns, string $order = 'ASC', ?string $nulls = null): static
    {
        $this->sql->addOrder(is_array($columns) ? $columns : [$columns], $order, $nulls);

        return $this;
    }

    public function limit(int $value): static
    {
        $this->sql->setLimit($value);

        return $this;
    }

    public function offset(int $value): static
    {
        $this->sql->setOffset($value);

        return $this;
    }

    /**
     * @param ColumnArg|array<int|string, ColumnArg>|(Closure(ColumnExpression): mixed) $columns
     */
    public function select(string|Expression|Closure|array $columns = []): mixed
    {
        $expr = new ColumnExpression($this->sql);
        if ($columns instanceof Closure) {
            /** @var Closure(ColumnExpression): mixed $columns */
            $columns($expr);
        } else {
            $expr->columns(is_array($columns) ? $columns : [$columns]);
        }

        return null;
    }

    /**
     * @param ColumnArg $name
     */
    public function column(string|Expression|Closure $name): mixed
    {
        (new ColumnExpression($this->sql))->column($name);

        return null;
    }

    /**
     * @param ColumnArg|list<ColumnArg> $column
     */
    public function count(string|Expression|Closure|array $column = '*', bool $distinct = false): mixed
    {
        (new ColumnExpression($this->sql))->count($column, null, $distinct);

        return null;
    }

    /**
     * @param ColumnArg $column
     */
    public function avg(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        (new ColumnExpression($this->sql))->avg($column, null, $distinct);

        return null;
    }

    /**
     * @param ColumnArg $column
     */
    public function sum(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        (new ColumnExpression($this->sql))->sum($column, null, $distinct);

        return null;
    }

    /**
     * @param ColumnArg $column
     */
    public function min(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        (new ColumnExpression($this->sql))->min($column, null, $distinct);

        return null;
    }

    /**
     * @param ColumnArg $column
     */
    public function max(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        (new ColumnExpression($this->sql))->max($column, null, $distinct);

        return null;
    }
}
