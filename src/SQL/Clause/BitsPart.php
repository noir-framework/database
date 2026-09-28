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

use Noirapi\Database\SQL\Expression;

/**
 * `(column & ~clear) | set`. $signed makes MySQL cast the (unsigned 64-bit) result back to
 * SIGNED, needed to store bit 63 in a signed BIGINT column.
 */
final readonly class BitsPart implements ExpressionPart
{
    public function __construct(
        public string|Expression $column,
        public int $set = 0,
        public int $clear = 0,
        public bool $signed = false,
    ) {
    }
}
