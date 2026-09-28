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

use function array_keys;

/**
 * Connection-less INSERT builder.
 */
class InsertStatement
{
    protected SQLStatement $sql;

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
        foreach (array_keys($values) as $column) {
            $this->sql->addColumn((string) $column);
            $this->sql->addValue($values[$column]);
        }

        return $this;
    }

    public function into(string $table): mixed
    {
        $this->sql->addTables([$table]);

        return null;
    }
}
