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
 * Collects the selected columns of a SELECT statement.
 *
 * @psalm-import-type ColumnArg from Expression
 */
class ColumnExpression
{
    public function __construct(protected SQLStatement $sql)
    {
    }

    public function __clone()
    {
        $this->sql = clone $this->sql;
    }

    /**
     * @param ColumnArg $name
     */
    public function column(string|Expression|Closure $name, ?string $alias = null): static
    {
        $this->sql->addColumn($name, $alias);

        return $this;
    }

    /**
     * Accepts `['col', 'col' => 'alias', 'alias' => fn (Expression $e) => ...]`.
     *
     * @param array<int|string, ColumnArg> $columns
     */
    public function columns(array $columns): static
    {
        foreach ($columns as $name => $alias) {
            if (!is_string($name)) {
                $this->column($alias);
                continue;
            }

            // ['column' => 'alias'] or ['alias' => expression]
            is_string($alias) ? $this->column($name, $alias) : $this->column($alias, $name);
        }

        return $this;
    }

    /**
     * @param ColumnArg|list<ColumnArg> $column
     */
    public function count(
        string|Expression|Closure|array $column = '*',
        ?string $alias = null,
        bool $distinct = false,
    ): static {
        return $this->column((new Expression())->count($column, $distinct), $alias);
    }

    /**
     * @param ColumnArg $column
     */
    public function avg(string|Expression|Closure $column, ?string $alias = null, bool $distinct = false): static
    {
        return $this->column((new Expression())->avg($column, $distinct), $alias);
    }

    /**
     * @param ColumnArg $column
     */
    public function sum(string|Expression|Closure $column, ?string $alias = null, bool $distinct = false): static
    {
        return $this->column((new Expression())->sum($column, $distinct), $alias);
    }

    /**
     * @param ColumnArg $column
     */
    public function min(string|Expression|Closure $column, ?string $alias = null, bool $distinct = false): static
    {
        return $this->column((new Expression())->min($column, $distinct), $alias);
    }

    /**
     * @param ColumnArg $column
     */
    public function max(string|Expression|Closure $column, ?string $alias = null, bool $distinct = false): static
    {
        return $this->column((new Expression())->max($column, $distinct), $alias);
    }

    /**
     * @param ColumnArg $column
     */
    public function ucase(string|Expression|Closure $column, ?string $alias = null): static
    {
        return $this->column((new Expression())->ucase($column), $alias);
    }

    /**
     * @param ColumnArg $column
     */
    public function lcase(string|Expression|Closure $column, ?string $alias = null): static
    {
        return $this->column((new Expression())->lcase($column), $alias);
    }

    /**
     * @param ColumnArg $column
     */
    public function len(string|Expression|Closure $column, ?string $alias = null): static
    {
        return $this->column((new Expression())->len($column), $alias);
    }

    /**
     * @param ColumnArg $column
     */
    public function mid(
        string|Expression|Closure $column,
        int $start = 1,
        ?string $alias = null,
        int $length = 0,
    ): static {
        return $this->column((new Expression())->mid($column, $start, $length), $alias);
    }

    /**
     * Kept from opis/database for output compatibility: emitted as FORMAT(column, decimals).
     *
     * @param ColumnArg $column
     */
    public function round(string|Expression|Closure $column, int $decimals = 0, ?string $alias = null): static
    {
        return $this->column((new Expression())->format($column, $decimals), $alias);
    }

    /**
     * @param ColumnArg $column
     */
    public function format(string|Expression|Closure $column, int $format, ?string $alias = null): static
    {
        return $this->column((new Expression())->format($column, $format), $alias);
    }

    public function now(?string $alias = null): static
    {
        return $this->column((new Expression())->now(), $alias);
    }
}
