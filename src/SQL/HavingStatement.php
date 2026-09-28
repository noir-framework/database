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
 * Fluent HAVING clause builder.
 */
class HavingStatement
{
    protected SQLStatement $sql;

    protected HavingExpression $expression;

    public function __construct(?SQLStatement $statement = null)
    {
        $this->sql = $statement ?? new SQLStatement();
        $this->expression = new HavingExpression($this->sql);
    }

    public function __clone()
    {
        $this->sql = clone $this->sql;
        $this->expression = new HavingExpression($this->sql);
    }

    public function getSQLStatement(): SQLStatement
    {
        return $this->sql;
    }

    /**
     * `having('col', fn (HavingExpression $e) => $e->count()->gt(5))` adds a condition;
     * `having(fn (HavingStatement $h) => ...)` adds a nested group.
     *
     * @param string|Expression|Closure $column
     * @param (Closure(HavingExpression): mixed)|null $value
     */
    public function having(string|Expression|Closure $column, ?Closure $value = null): static
    {
        return $this->addCondition($column, $value, 'AND');
    }

    /**
     * @param string|Expression|Closure $column
     * @param (Closure(HavingExpression): mixed)|null $value
     */
    public function andHaving(string|Expression|Closure $column, ?Closure $value = null): static
    {
        return $this->addCondition($column, $value, 'AND');
    }

    /**
     * @param string|Expression|Closure $column
     * @param (Closure(HavingExpression): mixed)|null $value
     */
    public function orHaving(string|Expression|Closure $column, ?Closure $value = null): static
    {
        return $this->addCondition($column, $value, 'OR');
    }

    /**
     * @param string|Expression|Closure $column
     * @param (Closure(HavingExpression): mixed)|null $value
     */
    protected function addCondition(string|Expression|Closure $column, ?Closure $value, string $separator): static
    {
        if ($column instanceof Closure && $value === null) {
            /** @var Closure(HavingStatement): mixed $column */
            $this->sql->addHavingGroupCondition($column, $separator);

            return $this;
        }

        $expr = $this->expression->init($column, $separator);
        if ($value !== null) {
            $value($expr);
        }

        return $this;
    }
}
