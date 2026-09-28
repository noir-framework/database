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

use function is_string;

/**
 * The comparison half of a HAVING condition on an aggregate.
 */
class Having
{
    protected string|Expression $aggregate = '';

    protected string $separator = 'AND';

    public function __construct(protected SQLStatement $sql)
    {
    }

    public function __clone()
    {
        if ($this->aggregate instanceof Expression) {
            $this->aggregate = clone $this->aggregate;
        }

        $this->sql = clone $this->sql;
    }

    public function init(string|Expression|Closure $aggregate, string $separator): static
    {
        if ($aggregate instanceof Closure) {
            /** @var Closure(Expression): mixed $aggregate */
            $this->aggregate = Expression::fromClosure($aggregate);
        } else {
            $this->aggregate = $aggregate;
        }
        $this->separator = $separator;

        return $this;
    }

    public function eq(mixed $value, bool $is_column = false): void
    {
        $this->addCondition($value, '=', $is_column);
    }

    public function ne(mixed $value, bool $is_column = false): void
    {
        $this->addCondition($value, '!=', $is_column);
    }

    public function lt(mixed $value, bool $is_column = false): void
    {
        $this->addCondition($value, '<', $is_column);
    }

    public function gt(mixed $value, bool $is_column = false): void
    {
        $this->addCondition($value, '>', $is_column);
    }

    public function lte(mixed $value, bool $is_column = false): void
    {
        $this->addCondition($value, '<=', $is_column);
    }

    public function gte(mixed $value, bool $is_column = false): void
    {
        $this->addCondition($value, '>=', $is_column);
    }

    /**
     * @param array<mixed>|(Closure(Subquery): mixed) $value
     */
    public function in(array|Closure $value): void
    {
        $this->sql->addHavingInCondition($this->aggregate, $value, $this->separator, false);
    }

    /**
     * @param array<mixed>|(Closure(Subquery): mixed) $value
     */
    public function notIn(array|Closure $value): void
    {
        $this->sql->addHavingInCondition($this->aggregate, $value, $this->separator, true);
    }

    public function between(mixed $value1, mixed $value2): void
    {
        $this->sql->addHavingBetweenCondition($this->aggregate, $value1, $value2, $this->separator, false);
    }

    public function notBetween(mixed $value1, mixed $value2): void
    {
        $this->sql->addHavingBetweenCondition($this->aggregate, $value1, $value2, $this->separator, true);
    }

    protected function addCondition(mixed $value, string $operator, bool $is_column): void
    {
        if ($is_column && is_string($value)) {
            $value = (new Expression())->column($value);
        }

        $this->sql->addHavingCondition($this->aggregate, $value, $operator, $this->separator);
    }
}
