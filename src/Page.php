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

namespace Noirapi\Database;

use function intdiv;
use function max;

/**
 * One page of a paginated query: `$page = $query->paginate(2, 20)`, then
 * `$page->results->fetchClass(User::class)->all()`.
 */
final readonly class Page
{
    /**
     * @param ResultSet<mixed> $results The rows of this page
     * @param int $total Rows matched by the whole query
     */
    public function __construct(
        public ResultSet $results,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    /**
     * The number of the last page; 1 when there are no rows.
     */
    public function lastPage(): int
    {
        return max(1, intdiv($this->total + $this->perPage - 1, $this->perPage));
    }

    public function hasMore(): bool
    {
        return $this->page < $this->lastPage();
    }
}
