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

declare(strict_types=1);namespace Noirapi\Database\SQL;

use function is_array;

/**
 * Connection-less DELETE builder.
 */
class DeleteStatement extends BaseStatement
{
    /**
     * @param string|array<int|string, string|Expression> $from
     */
    public function __construct(string|array $from, ?SQLStatement $statement = null)
    {
        parent::__construct($statement);
        $this->sql->setFrom(is_array($from) ? $from : [$from]);
    }

    /**
     * @param string|array<int|string, string|Expression> $tables
     */
    public function delete(string|array $tables = []): mixed
    {
        $this->sql->addTables(is_array($tables) ? $tables : [$tables]);

        return null;
    }
}
