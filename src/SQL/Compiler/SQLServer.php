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
use Noirapi\Database\SQL\Expression;
use Noirapi\Database\SQL\Interval;
use Noirapi\Database\SQL\SQLStatement;
use Override;

use function array_map;
use function array_values;
use function implode;
use function is_bool;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function ltrim;
use function strtolower;
use function trim;

class SQLServer extends Compiler
{
    protected string $dateFormat = 'Y-m-d H:i:s.0000000';

    protected string $wrapper = '[%s]';

    /** SQL Server allows 2,100 parameters per request, including some the driver adds itself. */
    protected int $maxParams = 2000;

    /**
     * Emulates LIMIT with TOP, and LIMIT + OFFSET with ROW_NUMBER().
     */
    #[Override]
    public function select(SQLStatement $select): string
    {
        $limit = $select->getLimit();

        if ($limit <= 0) {
            return parent::select($select);
        }

        $offset = $select->getOffset();

        if ($offset < 0) {
            $sql = $select->getDistinct() ? 'SELECT DISTINCT ' : 'SELECT ';
            $sql .= 'TOP ' . $limit . ' ';
            $sql .= $this->handleColumns($select->getColumns());
            $sql .= $this->handleInto($select->getIntoTable(), $select->getIntoDatabase());
            $sql .= ' FROM ';
            $sql .= $this->handleTables($select->getTables());
            $sql .= $this->handleJoins($select->getJoins());
            $sql .= $this->handleWheres($select->getWheres());
            $sql .= $this->handleGroupings($select->getGroupBy());
            $sql .= $this->handleOrderings($select->getOrder());
            $sql .= $this->handleHavings($select->getHaving());

            return $sql;
        }

        $order = trim($this->handleOrderings($select->getOrder()));

        if ($order === '') {
            $order = 'ORDER BY (SELECT 0)';
        }

        $sql = $select->getDistinct() ? 'SELECT DISTINCT ' : 'SELECT ';
        $sql .= $this->handleColumns($select->getColumns());
        $sql .= ', ROW_NUMBER() OVER (' . $order . ') AS opis_rownum';
        $sql .= ' FROM ';
        $sql .= $this->handleTables($select->getTables());
        $sql .= $this->handleJoins($select->getJoins());
        $sql .= $this->handleWheres($select->getWheres());
        $sql .= $this->handleGroupings($select->getGroupBy());
        $sql .= $this->handleHavings($select->getHaving());

        $limit += $offset;
        $offset++;

        return 'SELECT * FROM (' . $sql . ') AS m1 WHERE opis_rownum BETWEEN ' . $offset . ' AND ' . $limit;
    }

    /**
     * SQL Server has no INSERT ... ON CONFLICT, so upserts become a MERGE with the rows as its
     * source, aliased [excluded]. HOLDLOCK keeps concurrent upserts from both inserting.
     *
     * @throws LogicException When upserting without conflict keys
     */
    #[Override]
    public function insert(SQLStatement $insert): string
    {
        $upsert = $insert->getUpsert();
        if ($upsert === null) {
            return parent::insert($insert);
        }

        if ($upsert->keys === []) {
            throw new LogicException('upsert() needs the conflict key columns on SQL Server');
        }

        $table = $this->wrap(array_values($insert->getTables())[0] ?? '');
        $columns = $this->insertColumnNames($insert);
        $source = static fn (string $column): string => '[excluded].' . $column;
        $wrapped = array_map($this->wrap(...), $columns);

        $sql = 'MERGE INTO ' . $table . ' WITH (HOLDLOCK) USING ('
            . ltrim($this->handleInsertRows($insert->getInsertRows())) . ') AS [excluded] ('
            . implode(', ', $wrapped) . ') ON ';
        $sql .= implode(' AND ', array_map(
            fn (string $key): string => $table . '.' . $this->wrap($key) . ' = ' . $source($this->wrap($key)),
            $upsert->keys,
        ));

        $set = [];
        foreach ($this->upsertAssignments($upsert, $columns) as $assignment) {
            $set[] = is_string($assignment)
                ? $this->wrap($assignment) . ' = ' . $source($this->wrap($assignment))
                : $this->wrap($assignment->column) . ' = ' . $this->param($assignment->value);
        }

        if ($set !== []) {
            $sql .= ' WHEN MATCHED THEN UPDATE SET ' . implode(', ', $set);
        }

        return $sql . ' WHEN NOT MATCHED THEN INSERT (' . implode(', ', $wrapped) . ') VALUES ('
            . implode(', ', array_map($source, $wrapped)) . ');';
    }

    #[Override]
    public function update(SQLStatement $update): string
    {
        $joins = $this->handleJoins($update->getJoins());
        $tables = $update->getTables();

        if ($joins !== '') {
            $joins = ' FROM ' . $this->handleTables($tables) . ' ' . $joins;
            $tables = array_values($tables);
        }

        $sql = 'UPDATE ';
        $sql .= $this->handleTables($tables);
        $sql .= $this->handleSetColumns($update->getUpdateColumns());
        $sql .= $joins;
        $sql .= $this->handleWheres($update->getWheres());

        return $sql;
    }

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

    /**
     * SUBSTRING() needs a length; without one the rest of the string is taken.
     */
    #[Override]
    protected function sqlFunctionMID(SqlFunction $func): string
    {
        $column = $this->wrap($func->column);

        return 'SUBSTRING(' . $column . ', ' . $this->param($func->start) . ', '
            . ($func->length > 0 ? $this->param($func->length) : 'LEN(' . $column . ')') . ')';
    }

    #[Override]
    protected function sqlFunctionNOW(SqlFunction $func): string
    {
        return 'GETDATE()';
    }

    #[Override]
    protected function sqlFunctionCURRENTDATE(SqlFunction $func): string
    {
        return 'CAST(GETDATE() AS DATE)';
    }

    #[Override]
    protected function sqlFunctionINET(SqlFunction $func): string
    {
        $this->unsupportedFunction($func);
    }

    #[Override]
    protected function handleDateArithmetic(DateArithmetic $date): string
    {
        $amount = $this->dateAmount($date->amount);
        if ($date->subtract) {
            $amount = '-' . $amount;
        }

        return 'DATEADD(' . $this->intervalUnit($date->unit) . ', ' . $amount . ', ' . $this->wrap($date->date) . ')';
    }

    /**
     * A scalar among the elements: EXISTS (SELECT 1 FROM OPENJSON(doc[, path]) WHERE [value] = ?).
     *
     * @throws LogicException For arrays and objects, which OPENJSON() values cannot match
     */
    #[Override]
    protected function whereJsonContains(WhereJsonContains $where): string
    {
        if (!is_scalar($where->value)) {
            throw new LogicException('jsonContains() on SQL Server only searches for scalar values');
        }

        [$column, $path] = $this->jsonTarget($where->column);
        $value = is_bool($where->value) ? ($where->value ? 'true' : 'false') : $where->value;

        return ($where->not ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM OPENJSON(' . $column
            . ($path === null ? '' : ', ' . $this->quote($path->dollar())) . ') WHERE [value] = '
            . $this->param($value) . ')';
    }

    /**
     * JSON_PATH_EXISTS() needs SQL Server 2022 or Azure SQL.
     */
    #[Override]
    protected function whereJsonExists(WhereJsonExists $where): string
    {
        [$column, $path] = $this->jsonTarget($where->column);

        return 'JSON_PATH_EXISTS(' . $column . ', ' . $this->quote($path?->dollar() ?? '$') . ')'
            . ($where->not ? ' = 0' : ' = 1');
    }

    /**
     * Nested JSON_MODIFY(): numbers and strings as they are, booleans as BIT, arrays and objects
     * through JSON_QUERY(); null uses a strict path, since lax mode would delete the key.
     */
    #[Override]
    protected function jsonSet(string $column, array $paths): string
    {
        $sql = $column;
        /** @var mixed $value */
        foreach ($paths as [$path, $value]) {
            $target = $this->quote(($value === null ? 'strict ' : '') . $path->dollar());
            $sql = 'JSON_MODIFY(' . $sql . ', ' . $target . ', ' . match (true) {
                $value === null => 'NULL',
                $value instanceof Expression, is_string($value), is_int($value), is_float($value)
                    => $this->param($value),
                is_bool($value) => 'CAST(' . $this->param($value) . ' AS BIT)',
                default => 'JSON_QUERY(' . $this->param($this->jsonEncode($value)) . ')',
            } . ')';
        }

        return $sql;
    }

    #[Override]
    protected function intervalUnit(Interval $unit): string
    {
        return strtolower($unit->name);
    }
}
