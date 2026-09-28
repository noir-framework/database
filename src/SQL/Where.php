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
use Noirapi\Database\SQL\Clause\BitTest;

use function is_string;

/**
 * The comparison half of a WHERE condition: `where('age')` returns this, `->is(21)` finishes it.
 *
 * @template TStatement of WhereStatement
 *
 * @SuppressWarnings("PHPMD.CamelCaseParameterName") $is_column is public opis/database API (named arguments).
 * @SuppressWarnings("PHPMD.CamelCaseVariableName") $is_column is public opis/database API (named arguments).
 */
class Where
{
    protected string|Expression $column = '';

    protected string $separator = 'AND';

    /**
     * @param TStatement $statement
     */
    public function __construct(
        protected WhereStatement $statement,
        protected SQLStatement $sql,
    ) {
    }

    public function __clone()
    {
        if ($this->column instanceof Expression) {
            $this->column = clone $this->column;
        }

        $this->statement = clone $this->statement;
        $this->sql = $this->statement->getSQLStatement();
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

    /**
     * `is(null)` compiles to `IS NULL` (`= NULL` never matches).
     *
     * @return TStatement
     */
    public function is(mixed $value, bool $is_column = false): WhereStatement
    {
        if ($value === null) {
            return $this->addNullCondition(false);
        }

        return $this->addCondition($value, '=', $is_column);
    }

    /**
     * `isNot(null)` compiles to `IS NOT NULL` (`!= NULL` never matches).
     *
     * @return TStatement
     */
    public function isNot(mixed $value, bool $is_column = false): WhereStatement
    {
        if ($value === null) {
            return $this->addNullCondition(true);
        }

        return $this->addCondition($value, '!=', $is_column);
    }

    /**
     * @return TStatement
     */
    public function lessThan(mixed $value, bool $is_column = false): WhereStatement
    {
        return $this->addCondition($value, '<', $is_column);
    }

    /**
     * @return TStatement
     */
    public function greaterThan(mixed $value, bool $is_column = false): WhereStatement
    {
        return $this->addCondition($value, '>', $is_column);
    }

    /**
     * @return TStatement
     */
    public function atLeast(mixed $value, bool $is_column = false): WhereStatement
    {
        return $this->addCondition($value, '>=', $is_column);
    }

    /**
     * @return TStatement
     */
    public function atMost(mixed $value, bool $is_column = false): WhereStatement
    {
        return $this->addCondition($value, '<=', $is_column);
    }

    /**
     * @return TStatement
     */
    public function between(mixed $value1, mixed $value2): WhereStatement
    {
        return $this->addBetweenCondition($value1, $value2, false);
    }

    /**
     * @return TStatement
     */
    public function notBetween(mixed $value1, mixed $value2): WhereStatement
    {
        return $this->addBetweenCondition($value1, $value2, true);
    }

    /**
     * @return TStatement
     */
    public function like(string $value): WhereStatement
    {
        return $this->addLikeCondition($value, false);
    }

    /**
     * @return TStatement
     */
    public function notLike(string $value): WhereStatement
    {
        return $this->addLikeCondition($value, true);
    }

    /**
     * @param array<mixed>|(Closure(Subquery): mixed) $value
     *
     * @return TStatement
     */
    public function in(array|Closure $value): WhereStatement
    {
        return $this->addInCondition($value, false);
    }

    /**
     * @param array<mixed>|(Closure(Subquery): mixed) $value
     *
     * @return TStatement
     */
    public function notIn(array|Closure $value): WhereStatement
    {
        return $this->addInCondition($value, true);
    }

    /**
     * @return TStatement
     */
    public function isNull(): WhereStatement
    {
        return $this->addNullCondition(false);
    }

    /**
     * @return TStatement
     */
    public function notNull(): WhereStatement
    {
        return $this->addNullCondition(true);
    }

    /**
     * Every bit of the mask is set: `(~col & mask) = 0`. The complement form stays correct
     * for bit 63 on MySQL, whose bit operators return unsigned 64-bit values; pass bit 63 as
     * PHP_INT_MIN (PHP ints are signed).
     *
     * @return TStatement
     */
    public function hasAllBits(int $mask): WhereStatement
    {
        return $this->addBitsCondition($mask, BitTest::All);
    }

    /**
     * At least one bit of the mask is set: `(col & mask) != 0`.
     *
     * @return TStatement
     */
    public function hasAnyBits(int $mask): WhereStatement
    {
        return $this->addBitsCondition($mask, BitTest::Any);
    }

    /**
     * No bit of the mask is set: `(col & mask) = 0`.
     *
     * @return TStatement
     */
    public function hasNoBits(int $mask): WhereStatement
    {
        return $this->addBitsCondition($mask, BitTest::None);
    }

    /**
     * Alias of notNull().
     *
     * @return TStatement
     */
    public function isNotNull(): WhereStatement
    {
        return $this->addNullCondition(true);
    }

    /**
     * Uses the column or expression itself as the condition.
     *
     * @return TStatement
     */
    public function nop(): WhereStatement
    {
        $this->sql->addWhereNop($this->column, $this->separator);

        return $this->statement;
    }

    /**
     * @return TStatement
     */
    public function eq(mixed $value, bool $is_column = false): WhereStatement
    {
        return $this->is($value, $is_column);
    }

    /**
     * @return TStatement
     */
    public function ne(mixed $value, bool $is_column = false): WhereStatement
    {
        return $this->isNot($value, $is_column);
    }

    /**
     * @return TStatement
     */
    public function lt(mixed $value, bool $is_column = false): WhereStatement
    {
        return $this->lessThan($value, $is_column);
    }

    /**
     * @return TStatement
     */
    public function gt(mixed $value, bool $is_column = false): WhereStatement
    {
        return $this->greaterThan($value, $is_column);
    }

    /**
     * @return TStatement
     */
    public function gte(mixed $value, bool $is_column = false): WhereStatement
    {
        return $this->atLeast($value, $is_column);
    }

    /**
     * @return TStatement
     */
    public function lte(mixed $value, bool $is_column = false): WhereStatement
    {
        return $this->atMost($value, $is_column);
    }

    /**
     * @return TStatement
     */
    protected function addCondition(mixed $value, string $operator, bool $isColumn = false): WhereStatement
    {
        if ($isColumn && is_string($value)) {
            $value = (new Expression())->column($value);
        }

        $this->sql->addWhereCondition($this->column, $value, $operator, $this->separator);

        return $this->statement;
    }

    /**
     * @return TStatement
     */
    protected function addBetweenCondition(mixed $value1, mixed $value2, bool $not): WhereStatement
    {
        $this->sql->addWhereBetweenCondition($this->column, $value1, $value2, $this->separator, $not);

        return $this->statement;
    }

    /**
     * @return TStatement
     */
    protected function addLikeCondition(string $pattern, bool $not): WhereStatement
    {
        $this->sql->addWhereLikeCondition($this->column, $pattern, $this->separator, $not);

        return $this->statement;
    }

    /**
     * @param array<mixed>|(Closure(Subquery): mixed) $value
     *
     * @return TStatement
     */
    protected function addInCondition(array|Closure $value, bool $not): WhereStatement
    {
        $this->sql->addWhereInCondition($this->column, $value, $this->separator, $not);

        return $this->statement;
    }

    /**
     * @return TStatement
     */
    protected function addBitsCondition(int $mask, BitTest $test): WhereStatement
    {
        $this->sql->addWhereBitsCondition($this->column, $mask, $test, $this->separator);

        return $this->statement;
    }

    /**
     * @return TStatement
     */
    protected function addNullCondition(bool $not): WhereStatement
    {
        $this->sql->addWhereNullCondition($this->column, $this->separator, $not);

        return $this->statement;
    }
}
