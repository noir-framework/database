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
 * Picks the aggregate applied to a HAVING column: `->count()`, `->sum()`, ...
 */
class HavingExpression
{
    protected Having $having;

    protected string|Expression $column = '';

    protected string $separator = 'AND';

    public function __construct(protected SQLStatement $sql)
    {
        $this->having = new Having($sql);
    }

    public function __clone()
    {
        if ($this->column instanceof Expression) {
            $this->column = clone $this->column;
        }

        $this->sql = clone $this->sql;
        $this->having = new Having($this->sql);
    }

    public function init(string|Expression|Closure $column, string $separator): static
    {
        $this->separator = $separator;

        if ($column instanceof Closure) {
            /** @var Closure(Expression): mixed $column */
            $this->column = Expression::fromClosure($column);

            return $this;
        }

        $this->column = $column;

        return $this;
    }

    public function count(bool $distinct = false): Having
    {
        return $this->having->init((new Expression())->count($this->column, $distinct), $this->separator);
    }

    public function avg(bool $distinct = false): Having
    {
        return $this->having->init((new Expression())->avg($this->column, $distinct), $this->separator);
    }

    public function sum(bool $distinct = false): Having
    {
        return $this->having->init((new Expression())->sum($this->column, $distinct), $this->separator);
    }

    public function min(bool $distinct = false): Having
    {
        return $this->having->init((new Expression())->min($this->column, $distinct), $this->separator);
    }

    public function max(bool $distinct = false): Having
    {
        return $this->having->init((new Expression())->max($this->column, $distinct), $this->separator);
    }
}
