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

use Noirapi\Database\Connection;
use Override;

/**
 * INSERT bound to a connection: `$db->insert([...])->into('table')` runs it.
 */
class Insert extends InsertStatement
{
    public function __construct(protected Connection $connection, ?SQLStatement $statement = null)
    {
        parent::__construct($statement);
    }

    #[Override]
    public function into(string $table): bool
    {
        parent::into($table);
        $compiler = $this->connection->getCompiler();

        return $this->connection->command($compiler->insert($this->sql), $compiler->getParams());
    }
}
