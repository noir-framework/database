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
use Noirapi\Database\Connection;
use Noirapi\Database\Page;
use Noirapi\Database\ResultSet;
use Override;

use function is_numeric;

/**
 * SELECT bound to a connection; the terminal methods run the query.
 *
 * @psalm-import-type ColumnArg from Expression
 */
class Select extends SelectStatement
{
    /**
     * @param string|array<int|string, string|Expression> $tables
     */
    public function __construct(protected Connection $connection, string|array $tables, ?SQLStatement $statement = null)
    {
        parent::__construct($tables, $statement);
    }

    /**
     * @param ColumnArg|array<int|string, ColumnArg>|(Closure(ColumnExpression): mixed) $columns
     *
     * @return ResultSet<mixed>
     */
    #[Override]
    public function select(string|Expression|Closure|array $columns = []): ResultSet
    {
        parent::select($columns);
        $compiler = $this->connection->getCompiler();

        return $this->connection->query($compiler->select($this->sql), $compiler->getParams());
    }

    /**
     * Like select(), but streams the rows on MySQL instead of buffering them: iterate the result
     * (`foreach` / `lazy()`) and finish it before running another query on this connection.
     *
     * @param ColumnArg|array<int|string, ColumnArg>|(Closure(ColumnExpression): mixed) $columns
     *
     * @return ResultSet<mixed>
     */
    public function stream(string|Expression|Closure|array $columns = []): ResultSet
    {
        parent::select($columns);
        $compiler = $this->connection->getCompiler();

        return $this->connection->stream($compiler->select($this->sql), $compiler->getParams());
    }

    /**
     * Runs the query for one page and counts all matching rows: `paginate(2, 20)` returns rows
     * 21-40 and the total. Add an orderBy() so pages are stable. Grouped and DISTINCT queries
     * are counted as a sub-query.
     *
     * @param int $page Starting at 1
     * @param ColumnArg|array<int|string, ColumnArg>|(Closure(ColumnExpression): mixed) $columns
     *
     * @throws InvalidArgumentException When $page or $perPage is below 1
     */
    public function paginate(int $page, int $perPage, string|Expression|Closure|array $columns = []): Page
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('paginate() needs $page and $perPage of at least 1');
        }

        $total = (clone $this)->countRows($columns);
        $this->limit($perPage)->offset(($page - 1) * $perPage);

        return new Page($this->select($columns), $total, $page, $perPage);
    }

    /**
     * @param ColumnArg|array<int|string, ColumnArg>|(Closure(ColumnExpression): mixed) $columns
     */
    protected function countRows(string|Expression|Closure|array $columns): int
    {
        if ($this->sql->getGroupBy() === [] && !$this->sql->getDistinct()) {
            return $this->count();
        }

        parent::select($columns);
        $compiler = $this->connection->getCompiler();
        $sql = $compiler->select($this->sql->withoutOrder());

        return self::toInt($this->connection->column(
            'SELECT COUNT(*) FROM (' . $sql . ') AS opis_page',
            $compiler->getParams(),
        ));
    }

    /**
     * @param ColumnArg $name
     */
    #[Override]
    public function column(string|Expression|Closure $name): mixed
    {
        parent::column($name);

        return $this->getColumnResult();
    }

    /**
     * @param ColumnArg|list<ColumnArg> $column
     */
    #[Override]
    public function count(string|Expression|Closure|array $column = '*', bool $distinct = false): int
    {
        parent::count($column, $distinct);

        // PDO returns a numeric string with emulated prepares, and false when a GROUP BY matches nothing.
        return self::toInt($this->getAggregateResult());
    }

    /**
     * @param ColumnArg $column
     */
    #[Override]
    public function avg(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        parent::avg($column, $distinct);

        return $this->getAggregateResult();
    }

    /**
     * @param ColumnArg $column
     */
    #[Override]
    public function sum(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        parent::sum($column, $distinct);

        return $this->getAggregateResult();
    }

    /**
     * @param ColumnArg $column
     */
    #[Override]
    public function min(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        parent::min($column, $distinct);

        return $this->getAggregateResult();
    }

    /**
     * @param ColumnArg $column
     */
    #[Override]
    public function max(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        parent::max($column, $distinct);

        return $this->getAggregateResult();
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Without GROUP BY an aggregate returns one row, so ORDER BY is dropped: PostgreSQL and
     * SQL Server reject `SELECT COUNT(*) ... ORDER BY col`. With GROUP BY the order picks the
     * group whose value is returned, so it is kept.
     */
    protected function getAggregateResult(): mixed
    {
        $sql = $this->sql->getGroupBy() === [] ? $this->sql->withoutOrder() : $this->sql;
        $compiler = $this->connection->getCompiler();

        return $this->connection->column($compiler->select($sql), $compiler->getParams());
    }

    protected function getColumnResult(): mixed
    {
        $compiler = $this->connection->getCompiler();

        return $this->connection->column($compiler->select($this->sql), $compiler->getParams());
    }
}
