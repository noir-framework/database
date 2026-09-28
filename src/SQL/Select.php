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
use Override;

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
    public function count(string|Expression|Closure|array $column = '*', bool $distinct = false): mixed
    {
        parent::count($column, $distinct);

        return $this->getColumnResult();
    }

    /**
     * @param ColumnArg $column
     */
    #[Override]
    public function avg(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        parent::avg($column, $distinct);

        return $this->getColumnResult();
    }

    /**
     * @param ColumnArg $column
     */
    #[Override]
    public function sum(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        parent::sum($column, $distinct);

        return $this->getColumnResult();
    }

    /**
     * @param ColumnArg $column
     */
    #[Override]
    public function min(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        parent::min($column, $distinct);

        return $this->getColumnResult();
    }

    /**
     * @param ColumnArg $column
     */
    #[Override]
    public function max(string|Expression|Closure $column, bool $distinct = false): mixed
    {
        parent::max($column, $distinct);

        return $this->getColumnResult();
    }

    protected function getColumnResult(): mixed
    {
        $compiler = $this->connection->getCompiler();

        return $this->connection->column($compiler->select($this->sql), $compiler->getParams());
    }
}
