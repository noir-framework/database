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

namespace Noirapi\Database\SQL\Clause;

/**
 * What an INSERT does when it hits a duplicate key.
 *
 * Each entry of $update is either a column name (set it to the value that was being inserted)
 * or an UpdateColumn with an explicit value. null means every inserted column except the keys;
 * an empty list means leave the existing row unchanged.
 */
final readonly class UpsertClause
{
    /**
     * @param list<string> $keys Conflict target: the unique or primary key columns
     * @param list<string|UpdateColumn>|null $update
     */
    public function __construct(
        public array $keys,
        public ?array $update = null,
    ) {
    }
}
