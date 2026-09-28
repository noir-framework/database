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
use Noirapi\Database\SQL\Clause\Condition;
use Noirapi\Database\SQL\Clause\JoinColumn;
use Noirapi\Database\SQL\Clause\JoinExpression;
use Noirapi\Database\SQL\Clause\JoinNested;

use function is_string;

/**
 * Collects the ON conditions of a JOIN.
 */
class Join
{
    /** @var list<Condition> */
    protected array $conditions = [];

    /**
     * @return list<Condition>
     */
    public function getJoinConditions(): array
    {
        return $this->conditions;
    }

    /**
     * `on('a.id', 'b.id')` compares two columns; `on(fn (Join $j) => ...)` nests conditions;
     * `on($expression, true)` adds a raw expression.
     *
     * @param string|Expression|Closure $column1
     * @param string|Expression|(Closure(Expression): mixed)|true|null $column2
     */
    public function on(
        string|Expression|Closure $column1,
        string|Expression|Closure|bool|null $column2 = null,
        string $operator = '=',
    ): static {
        return $this->addJoinCondition($column1, $column2, $operator, 'AND');
    }

    /**
     * @param string|Expression|Closure $column1
     * @param string|Expression|(Closure(Expression): mixed)|true|null $column2
     */
    public function andOn(
        string|Expression|Closure $column1,
        string|Expression|Closure|bool|null $column2 = null,
        string $operator = '=',
    ): static {
        return $this->addJoinCondition($column1, $column2, $operator, 'AND');
    }

    /**
     * @param string|Expression|Closure $column1
     * @param string|Expression|(Closure(Expression): mixed)|true|null $column2
     */
    public function orOn(
        string|Expression|Closure $column1,
        string|Expression|Closure|bool|null $column2 = null,
        string $operator = '=',
    ): static {
        return $this->addJoinCondition($column1, $column2, $operator, 'OR');
    }

    /**
     * @param Expression|(Closure(Expression): mixed) $expression
     */
    protected function addJoinExpression(Expression|Closure $expression, string $separator = 'AND'): static
    {
        if ($expression instanceof Closure) {
            $expression = Expression::fromClosure($expression);
        }

        $this->conditions[] = new JoinExpression($expression, $separator);

        return $this;
    }

    /**
     * @param string|Expression|Closure $column1
     * @param string|Expression|(Closure(Expression): mixed)|bool|null $column2
     */
    protected function addJoinCondition(
        string|Expression|Closure $column1,
        string|Expression|Closure|bool|null $column2,
        string $operator,
        string $separator = 'AND',
    ): static {
        if ($column1 instanceof Closure) {
            if ($column2 === null) {
                /** @var Closure(Join): mixed $column1 */
                $join = new self();
                $column1($join);
                $this->conditions[] = new JoinNested($join, $separator);

                return $this;
            }

            /** @var Closure(Expression): mixed $column1 */
            if ($column2 === true) {
                return $this->addJoinExpression($column1, $separator);
            }

            $column1 = Expression::fromClosure($column1);
        } elseif ($column1 instanceof Expression && $column2 === true) {
            return $this->addJoinExpression($column1, $separator);
        }

        if ($column2 instanceof Closure) {
            $column2 = Expression::fromClosure($column2);
        }

        if (!is_string($column2) && !$column2 instanceof Expression) {
            throw new InvalidArgumentException('Join::on() needs a second column unless the first is a closure');
        }

        $this->conditions[] = new JoinColumn($column1, $column2, $operator, $separator);

        return $this;
    }
}
