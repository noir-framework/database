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

use InvalidArgumentException;
use LogicException;

use function array_diff;
use function array_keys;
use function array_map;
use function count;
use function implode;
use function is_array;
use function is_string;

/**
 * Connection-less INSERT builder.
 */
class InsertStatement
{
    protected SQLStatement $sql;

    /** @var list<string>|null Column order fixed by the first insertMany() row */
    protected ?array $rowColumns = null;

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
     * @param array<array-key, mixed> $values column => value
     */
    public function insert(array $values): static
    {
        if ($this->rowColumns !== null) {
            throw new LogicException('insert() cannot add columns after insertMany(); call it first');
        }

        foreach (array_keys($values) as $column) {
            $this->sql->addColumn((string) $column);
            $this->sql->addValue($values[$column]);
        }

        return $this;
    }

    /**
     * Adds several rows, compiled to a single multi-row `INSERT ... VALUES (...), (...)`.
     *
     * Every row must have the same columns (in any order); pass `null` explicitly for missing
     * values. Can be called repeatedly, and after insert(), whose columns the rows then follow.
     *
     * @param list<array<string, mixed>> $rows each row is column => value
     *
     * @throws InvalidArgumentException When a row's columns differ from the first row's
     */
    public function insertMany(array $rows): static
    {
        foreach ($rows as $index => $row) {
            $columns = $this->rowColumns ??= $this->initRowColumns($row);
            $unknown = array_diff(array_keys($row), $columns);
            if ($unknown !== [] || count($row) !== count($columns)) {
                throw new InvalidArgumentException(
                    'insertMany() row ' . $index . ' must have exactly the columns: ' . implode(', ', $columns),
                );
            }

            $this->sql->addValues(array_map(static fn (string $column): mixed => $row[$column], $columns));
        }

        return $this;
    }

    /**
     * Turns the INSERT into an upsert: when a row hits a duplicate key, update the existing row.
     *
     * - `$update === null`: set every inserted column except the keys to the inserted value
     * - a list of column names: set just those to the inserted value
     * - column => value (or Expression / Closure): set an explicit value. Qualify columns of the
     *   existing row with the table name, e.g. `fn (Expression $e) => $e->column('stats.hits')->op('+')->value(1)`;
     *   PostgreSQL and SQL Server reject the unqualified name as ambiguous
     * - `[]`: keep the existing row unchanged (insert or ignore)
     *
     * Both forms can be mixed. `$keys` are the unique or primary key columns that detect the
     * conflict; PostgreSQL, SQLite and SQL Server require them, MySQL uses any unique key.
     *
     * @param string|list<string> $keys
     * @param array<int|string, mixed>|null $update
     *
     * @throws InvalidArgumentException When a list entry of $update is not a column name
     */
    public function upsert(string|array $keys, ?array $update = null): static
    {
        $this->sql->setUpsert(is_array($keys) ? $keys : [$keys], $update);

        return $this;
    }

    public function into(string $table): mixed
    {
        $this->sql->addTables([$table]);

        return null;
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException When the row has no or non-string column names
     */
    private function initRowColumns(array $row): array
    {
        $columns = [];
        foreach ($this->sql->getColumns() as $column) {
            if (is_string($column->name)) {
                $columns[] = $column->name;
            }
        }

        if ($columns !== []) {
            return $columns;
        }

        foreach (array_keys($row) as $column) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('insertMany() rows must be column => value arrays');
            }
            $columns[] = $column;
            $this->sql->addColumn($column);
        }

        if ($columns === []) {
            throw new InvalidArgumentException('insertMany() rows must not be empty');
        }

        return $columns;
    }
}
