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

/**
 * Fluent WHERE clause builder.
 *
 * `where('col')` returns a {@see Where} whose comparison methods return this statement;
 * `where(fn (WhereStatement $w) => ...)` adds a nested group and returns this statement.
 */
class WhereStatement
{
    protected SQLStatement $sql;

    public function __construct(?SQLStatement $statement = null)
    {
        $this->sql = $statement ?? new SQLStatement();
    }

    public function __clone()
    {
        $this->sql = clone $this->sql;
    }

    public function getSQLStatement(): SQLStatement
    {
        return $this->sql;
    }

    /**
     * @param string|Expression|Closure $column
     *
     * @return ($isExpr is true ? Where<$this> : ($column is Closure ? $this : Where<$this>))
     */
    public function where(string|Expression|Closure $column, bool $isExpr = false): Where|static
    {
        return $this->addWhereCondition($column, 'AND', $isExpr);
    }

    /**
     * @param string|Expression|Closure $column
     *
     * @return ($isExpr is true ? Where<$this> : ($column is Closure ? $this : Where<$this>))
     */
    public function andWhere(string|Expression|Closure $column, bool $isExpr = false): Where|static
    {
        return $this->addWhereCondition($column, 'AND', $isExpr);
    }

    /**
     * @param string|Expression|Closure $column
     *
     * @return ($isExpr is true ? Where<$this> : ($column is Closure ? $this : Where<$this>))
     */
    public function orWhere(string|Expression|Closure $column, bool $isExpr = false): Where|static
    {
        return $this->addWhereCondition($column, 'OR', $isExpr);
    }

    /**
     * @param Closure(Subquery): mixed $select
     */
    public function whereExists(Closure $select): static
    {
        return $this->addWhereExistCondition($select);
    }

    /**
     * @param Closure(Subquery): mixed $select
     */
    public function andWhereExists(Closure $select): static
    {
        return $this->addWhereExistCondition($select);
    }

    /**
     * @param Closure(Subquery): mixed $select
     */
    public function orWhereExists(Closure $select): static
    {
        return $this->addWhereExistCondition($select, 'OR');
    }

    /**
     * @param Closure(Subquery): mixed $select
     */
    public function whereNotExists(Closure $select): static
    {
        return $this->addWhereExistCondition($select, 'AND', true);
    }

    /**
     * @param Closure(Subquery): mixed $select
     */
    public function andWhereNotExists(Closure $select): static
    {
        return $this->addWhereExistCondition($select, 'AND', true);
    }

    /**
     * @param Closure(Subquery): mixed $select
     */
    public function orWhereNotExists(Closure $select): static
    {
        return $this->addWhereExistCondition($select, 'OR', true);
    }

    /**
     * @param string|Expression|Closure $column
     *
     * @return Where<$this>|$this
     */
    protected function addWhereCondition(
        string|Expression|Closure $column,
        string $separator = 'AND',
        bool $isExpr = false,
    ): Where|static {
        if ($column instanceof Closure && !$isExpr) {
            /** @var Closure(WhereStatement): mixed $column */
            $this->sql->addWhereConditionGroup($column, $separator);

            return $this;
        }

        /** @var Where<$this> $where */
        $where = new Where($this, $this->sql);
        $where->init($column, $separator);

        return $where;
    }

    /**
     * @param Closure(Subquery): mixed $select
     */
    protected function addWhereExistCondition(Closure $select, string $separator = 'AND', bool $not = false): static
    {
        $this->sql->addWhereExistsCondition($select, $separator, $not);

        return $this;
    }
}
