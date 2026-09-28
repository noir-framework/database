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
use Noirapi\Database\Connection;
use Override;

use function is_array;
use function is_int;
use function is_string;

/**
 * UPDATE bound to a connection; `set()`, `increment()` and `decrement()` run it.
 */
class Update extends UpdateStatement
{
    /**
     * @param string|array<int|string, string|Expression> $table
     */
    public function __construct(protected Connection $connection, string|array $table, ?SQLStatement $statement = null)
    {
        parent::__construct($table, $statement);
    }

    /**
     * @param string|array<int|string, mixed> $column a column, a list of columns, or column => amount
     */
    public function increment(string|array $column, int|float $value = 1): int
    {
        return $this->incrementOrDecrement('+', $column, $value);
    }

    /**
     * @param string|array<int|string, mixed> $column a column, a list of columns, or column => amount
     */
    public function decrement(string|array $column, int|float $value = 1): int
    {
        return $this->incrementOrDecrement('-', $column, $value);
    }

    /**
     * `SET col = col | mask` and runs the update. See Expression::bits() for `$signed`.
     */
    public function setBits(string $column, int $mask, bool $signed = false): int
    {
        return $this->set([$column => (new Expression())->bits($column, set: $mask, signed: $signed)]);
    }

    /**
     * `SET col = col & ~mask` and runs the update. See Expression::bits() for `$signed`.
     */
    public function clearBits(string $column, int $mask, bool $signed = false): int
    {
        return $this->set([$column => (new Expression())->bits($column, clear: $mask, signed: $signed)]);
    }

    /**
     * Runs the update and returns the affected row count.
     *
     * @param array<string, mixed> $columns column => value (closures build expressions)
     */
    #[Override]
    public function set(array $columns): int
    {
        parent::set($columns);
        $compiler = $this->connection->getCompiler();

        return $this->connection->count($compiler->update($this->sql), $compiler->getParams());
    }

    /**
     * @param string|array<int|string, mixed> $columns
     */
    protected function incrementOrDecrement(string $sign, string|array $columns, int|float $value): int
    {
        if (!is_array($columns)) {
            $columns = [$columns];
        }

        $values = [];
        foreach ($columns as $key => $amount) {
            if (is_int($key)) {
                if (!is_string($amount)) {
                    throw new InvalidArgumentException('Column names to increment/decrement must be strings');
                }
                [$key, $amount] = [$amount, $value];
            }
            $values[$key] = (new Expression())->column($key)->op($sign)->value($amount);
        }

        return $this->set($values);
    }
}
