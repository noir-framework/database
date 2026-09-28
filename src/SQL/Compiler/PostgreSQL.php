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

use Noirapi\Database\SQL\Clause\DateArithmetic;
use Noirapi\Database\SQL\Clause\SqlFunction;
use Noirapi\Database\SQL\Clause\WhereJsonContains;
use Noirapi\Database\SQL\Clause\WhereJsonExists;
use Noirapi\Database\SQL\Compiler;
use Noirapi\Database\SQL\Expression;
use Noirapi\Database\SQL\Interval;
use Noirapi\Database\SQL\JsonPath;
use Override;

/**
 * PostgreSQL has no UCASE/LCASE/MID/LEN, DATE_ADD or INET6_ATON: those map to its own functions.
 */
class PostgreSQL extends Compiler
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
    protected function sqlFunctionINET(SqlFunction $func): string
    {
        $this->unsupportedFunction($func);
    }

    /**
     * (date + make_interval(days => n)): typed arguments, so parameters need no casts.
     */
    #[Override]
    protected function handleDateArithmetic(DateArithmetic $date): string
    {
        return '(' . $this->wrap($date->date) . ($date->subtract ? ' - ' : ' + ') . 'make_interval('
            . $this->intervalUnit($date->unit) . ' => ' . $this->dateAmount($date->amount) . '))';
    }

    #[Override]
    protected function jsonExtract(string $column, JsonPath $path): string
    {
        return '(' . $column . ' #>> ' . $this->quote($path->pgArray()) . ')';
    }

    /**
     * jsonb containment, which also matches nested objects: CAST(doc AS jsonb) @> CAST(? AS jsonb).
     */
    #[Override]
    protected function whereJsonContains(WhereJsonContains $where): string
    {
        [$column, $path] = $this->jsonTarget($where->column);
        $document = $path === null ? $column : '(' . $column . ' #> ' . $this->quote($path->pgArray()) . ')';

        return ($where->not ? 'NOT ' : '') . '(CAST(' . $document . ' AS jsonb) @> CAST('
            . $this->param($this->jsonEncode($where->value)) . ' AS jsonb))';
    }

    #[Override]
    protected function whereJsonExists(WhereJsonExists $where): string
    {
        [$column, $path] = $this->jsonTarget($where->column);

        return '(' . $column . ' #> ' . $this->quote($path?->pgArray() ?? '{}') . ')'
            . ($where->not ? ' IS NULL' : ' IS NOT NULL');
    }

    /**
     * Nested jsonb_set(CAST(col AS jsonb), '{path}', value): parent objects must exist.
     */
    #[Override]
    protected function jsonSet(string $column, array $paths): string
    {
        $sql = 'CAST(' . $column . ' AS jsonb)';
        /** @var mixed $value */
        foreach ($paths as [$path, $value]) {
            $sql = 'jsonb_set(' . $sql . ', ' . $this->quote($path->pgArray()) . ', '
                . ($value instanceof Expression
                    ? 'to_jsonb(' . $this->param($value) . ')'
                    : 'CAST(' . $this->param($this->jsonEncode($value)) . ' AS jsonb)')
                . ')';
        }

        return $sql;
    }

    #[Override]
    protected function intervalUnit(Interval $unit): string
    {
        return match ($unit) {
            Interval::Second => 'secs',
            Interval::Minute => 'mins',
            Interval::Hour => 'hours',
            Interval::Day => 'days',
            Interval::Week => 'weeks',
            Interval::Month => 'months',
            Interval::Year => 'years',
        };
    }
}
