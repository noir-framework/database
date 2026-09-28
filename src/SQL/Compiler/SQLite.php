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

namespace Noirapi\Database\SQL\Compiler;

use LogicException;
use Noirapi\Database\SQL\Clause\DateArithmetic;
use Noirapi\Database\SQL\Clause\SqlFunction;
use Noirapi\Database\SQL\Clause\WhereJsonContains;
use Noirapi\Database\SQL\Clause\WhereJsonExists;
use Noirapi\Database\SQL\Compiler;
use Noirapi\Database\SQL\Interval;
use Noirapi\Database\SQL\JsonPath;
use Override;

use function is_int;
use function is_scalar;

/**
 * SQLite has no UCASE/LCASE/MID/LEN, NOW() or DATE_ADD: those map to its own functions.
 * Date arithmetic uses datetime(date, 'N unit') and returns 'YYYY-MM-DD HH:MM:SS' text;
 * datetime('now') is UTC.
 */
class SQLite extends Compiler
{
    #[Override]
    protected function sqlFunctionUCASE(SqlFunction $func): string
    {
        return 'UPPER(' . $this->wrap($func->column) . ')';
    }

    #[Override]
    protected function sqlFunctionLCASE(SqlFunction $func): string
    {
        return 'LOWER(' . $this->wrap($func->column) . ')';
    }

    #[Override]
    protected function sqlFunctionMID(SqlFunction $func): string
    {
        return 'SUBSTR(' . $this->wrap($func->column) . ', ' . $this->param($func->start)
            . ($func->length > 0 ? ', ' . $this->param($func->length) : '') . ')';
    }

    #[Override]
    protected function sqlFunctionLEN(SqlFunction $func): string
    {
        return 'LENGTH(' . $this->wrap($func->column) . ')';
    }

    #[Override]
    protected function sqlFunctionNOW(SqlFunction $func): string
    {
        return "datetime('now')";
    }

    #[Override]
    protected function sqlFunctionINET(SqlFunction $func): string
    {
        $this->unsupportedFunction($func);
    }

    #[Override]
    protected function handleDateArithmetic(DateArithmetic $date): string
    {
        $sign = $date->subtract ? -1 : 1;
        $weeks = $date->unit === Interval::Week ? 7 : 1;
        $unit = ' ' . $this->intervalUnit($date->unit);

        $modifier = is_int($date->amount)
            ? $this->param(($date->amount * $sign * $weeks) . $unit)
            : '(((' . $this->wrap($date->amount) . ') * ' . ($sign * $weeks) . ") || '" . $unit . "')";

        return 'datetime(' . $this->wrap($date->date) . ', ' . $modifier . ')';
    }

    #[Override]
    protected function jsonExtract(string $column, JsonPath $path): string
    {
        return 'json_extract(' . $column . ', ' . $this->quote($path->dollar()) . ')';
    }

    /**
     * A scalar among the elements: EXISTS (SELECT 1 FROM json_each(doc[, path]) WHERE value = ?).
     *
     * @throws LogicException For arrays and objects, which json_each() cannot match
     */
    #[Override]
    protected function whereJsonContains(WhereJsonContains $where): string
    {
        if (!is_scalar($where->value)) {
            throw new LogicException('jsonContains() on SQLite only searches for scalar values');
        }

        [$column, $path] = $this->jsonTarget($where->column);

        return ($where->not ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM json_each(' . $column
            . ($path === null ? '' : ', ' . $this->quote($path->dollar())) . ') WHERE value = '
            . $this->param($where->value) . ')';
    }

    #[Override]
    protected function whereJsonExists(WhereJsonExists $where): string
    {
        [$column, $path] = $this->jsonTarget($where->column);

        return 'json_type(' . $column . ', ' . $this->quote($path?->dollar() ?? '$') . ')'
            . ($where->not ? ' IS NULL' : ' IS NOT NULL');
    }

    /**
     * json_set(col, path, value, ...); it creates missing parent objects.
     */
    #[Override]
    protected function jsonSet(string $column, array $paths): string
    {
        $sql = 'json_set(' . $column;
        /** @var mixed $value */
        foreach ($paths as [$path, $value]) {
            $sql .= ', ' . $this->quote($path->dollar()) . ', ' . $this->jsonValue($value, 'json(%s)');
        }

        return $sql . ')';
    }

    #[Override]
    protected function intervalUnit(Interval $unit): string
    {
        return match ($unit) {
            Interval::Second => 'seconds',
            Interval::Minute => 'minutes',
            Interval::Hour => 'hours',
            Interval::Day, Interval::Week => 'days',
            Interval::Month => 'months',
            Interval::Year => 'years',
        };
    }
}
