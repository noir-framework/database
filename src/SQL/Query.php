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
use Noirapi\Database\Connection;
use Noirapi\Database\ResultSet;

/**
 * Entry point returned by `Database::from()`: add WHERE / JOIN clauses, then select or delete.
 *
 * @psalm-import-type ColumnArg from Expression
 */
class Query extends BaseStatement
{
    /**
     * @param string|array<int|string, string|Expression> $tables
     */
    public function __construct(
        protected Connection $connection,
        protected string|array $tables,
        ?SQLStatement $statement = null,
    ) {
        parent::__construct($statement);
    }

    public function distinct(bool $value = true): Select
    {
        return $this->buildSelect()->distinct($value);
    }

    /**
     * @param ColumnArg|list<ColumnArg> $columns
     */
    public function groupBy(string|Expression|Closure|array $columns): Select
    {
        return $this->buildSelect()->groupBy($columns);
    }

    /**
     * @param (Closure(HavingExpression): mixed)|null $value
     */
    public function having(string|Expression|Closure $column, ?Closure $value = null): Select
    {
        return $this->buildSelect()->having($column, $value);
    }

    /**
     * @param (Closure(HavingExpression): mixed)|null $value
     */
    public function andHaving(string|Expression|Closure $column, ?Closure $value = null): Select
    {
        return $this->buildSelect()->andHaving($column, $value);
    }

    /**
     * @param (Closure(HavingExpression): mixed)|null $value
     */
    public function orHaving(string|Expression|Closure $column, ?Closure $value = null): Select
    {
        return $this->buildSelect()->orHaving($column, $value);
    }

    /**
     * @param ColumnArg|list<ColumnArg> $columns
     */
    public function orderBy(
        string|Expression|Closure|array $columns,
        string $order = 'ASC',
        ?string $nulls = null,
    ): Select {
        return $this->buildSelect()->orderBy($columns, $order, $nulls);
    }

    public function limit(int $value): Select
    {
        return $this->buildSelect()->limit($value);
    }

    public function offset(int $value): Select
    {
        return $this->buildSelect()->offset($value);
    }

    public function into(string $table, ?string $database = null): Select
    {
        return $this->buildSelect()->into($table, $database);
    }

    /**
     * @param ColumnArg|array<int|string, ColumnArg>|(Closure(ColumnExpression): mixed) $columns
     *
     * @return ResultSet<mixed>
     */
    public function select(string|Expression|Closure|array $columns = []): ResultSet
    {
        return $this->buildSelect()->select($columns);
    }

    /**
     * @param ColumnArg $name
     */
    public function column(string|Expression|Closure $name): mixed
    {
        return $this->buildSelect()->column($name);
    }

    /**
     * @param ColumnArg|list<ColumnArg> $column
     */
    public function count(string|Expression|Closure|array $column = '*', bool $distinct = false): mixed
    {
        return $this->buildSelect()->count($column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function avg(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        return $this->buildSelect()->avg($column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function sum(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        return $this->buildSelect()->sum($column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function min(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        return $this->buildSelect()->min($column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function max(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        return $this->buildSelect()->max($column, $distinct);
    }

    /**
     * Deletes the matching rows and returns the affected row count.
     *
     * @param string|array<int|string, string|Expression> $tables Tables to delete from when joining
     */
    public function delete(string|array $tables = []): int
    {
        return $this->buildDelete()->delete($tables);
    }

    protected function buildSelect(): Select
    {
        return new Select($this->connection, $this->tables, $this->sql);
    }

    protected function buildDelete(): Delete
    {
        return new Delete($this->connection, $this->tables, $this->sql);
    }
}
