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

use Noirapi\Database\SQL\Clause\BitsPart;
use Noirapi\Database\SQL\Clause\SqlFunction;
use Noirapi\Database\SQL\Clause\UpsertClause;
use Noirapi\Database\SQL\Compiler;
use Override;

use function implode;
use function is_string;

class MySQL extends Compiler
{
    protected string $wrapper = '`%s`';

    /**
     * Kept from opis/database for output compatibility: MySQL's ROUND() is emitted as FORMAT().
     */
    #[Override]
    protected function sqlFunctionROUND(SqlFunction $func): string
    {
        return 'FORMAT(' . $this->wrap($func->column) . ', ' . $this->param($func->decimals) . ')';
    }

    #[Override]
    protected function sqlFunctionLEN(SqlFunction $func): string
    {
        return 'LENGTH(' . $this->wrap($func->column) . ')';
    }

    /**
     * Bit operators yield unsigned 64-bit values; a signed BIGINT needs the result cast back.
     */
    #[Override]
    protected function handleBits(BitsPart $bits): string
    {
        $sql = parent::handleBits($bits);

        return $bits->signed ? 'CAST(' . $sql . ' AS SIGNED)' : $sql;
    }

    /**
     * ON DUPLICATE KEY UPDATE, which fires on any unique key, so the conflict keys only matter
     * for "do nothing" (a no-op assignment, since INSERT IGNORE would also swallow other errors).
     * VALUES(col) is used rather than the MySQL 8.0.19 row alias so MariaDB is supported.
     */
    #[Override]
    protected function handleUpsert(?UpsertClause $upsert, array $columns): string
    {
        if ($upsert === null) {
            return '';
        }

        $assignments = $this->upsertAssignments($upsert, $columns);
        if ($assignments === []) {
            $column = $this->wrap($upsert->keys[0] ?? $columns[0] ?? 'id');

            return ' ON DUPLICATE KEY UPDATE ' . $column . ' = ' . $column;
        }

        $sql = [];
        foreach ($assignments as $assignment) {
            $sql[] = is_string($assignment)
                ? $this->wrap($assignment) . ' = VALUES(' . $this->wrap($assignment) . ')'
                : $this->wrap($assignment->column) . ' = ' . $this->param($assignment->value);
        }

        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sql);
    }
}
