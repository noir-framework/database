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

/**
 * WHERE plus JOIN support shared by SELECT, UPDATE and DELETE.
 */
class BaseStatement extends WhereStatement
{
    /**
     * @param string|Expression|array<int|string, string|Expression>|(Closure(Expression): mixed) $table
     * @param Closure(Join): mixed $closure
     */
    public function join(string|Expression|array|Closure $table, Closure $closure): static
    {
        $this->sql->addJoinClause('INNER', $table, $closure);

        return $this;
    }

    /**
     * @param string|Expression|array<int|string, string|Expression>|(Closure(Expression): mixed) $table
     * @param Closure(Join): mixed $closure
     */
    public function leftJoin(string|Expression|array|Closure $table, Closure $closure): static
    {
        $this->sql->addJoinClause('LEFT', $table, $closure);

        return $this;
    }

    /**
     * @param string|Expression|array<int|string, string|Expression>|(Closure(Expression): mixed) $table
     * @param Closure(Join): mixed $closure
     */
    public function rightJoin(string|Expression|array|Closure $table, Closure $closure): static
    {
        $this->sql->addJoinClause('RIGHT', $table, $closure);

        return $this;
    }

    /**
     * @param string|Expression|array<int|string, string|Expression>|(Closure(Expression): mixed) $table
     * @param Closure(Join): mixed $closure
     */
    public function fullJoin(string|Expression|array|Closure $table, Closure $closure): static
    {
        $this->sql->addJoinClause('FULL', $table, $closure);

        return $this;
    }

    /**
     * @param string|Expression|array<int|string, string|Expression>|(Closure(Expression): mixed) $table
     */
    public function crossJoin(string|Expression|array|Closure $table): static
    {
        $this->sql->addJoinClause('CROSS', $table);

        return $this;
    }
}
