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

namespace Noirapi\Database\Schema;

use Override;

/**
 * A column added, modified or renamed by ALTER TABLE.
 */
class AlterColumn extends BaseColumn
{
    public function __construct(protected AlterTable $table, string $name, ?string $type = null)
    {
        parent::__construct($name, $type);
    }

    public function getTable(): AlterTable
    {
        return $this->table;
    }

    /**
     * Ignored for modified columns: use AlterTable::setDefaultValue() there.
     */
    #[Override]
    public function defaultValue(mixed $value): static
    {
        if ($this->get('handleDefault', true) === true) {
            parent::defaultValue($value);
        }

        return $this;
    }

    public function autoincrement(): static
    {
        return $this->set('autoincrement', true);
    }
}
