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

use LogicException;

/**
 * One ALTER TABLE operation. Only the fields relevant to the action are set.
 */
final readonly class AlterCommand
{
    /**
     * @param string $name Constraint / index / column name, or the old name for renames
     * @param list<string> $columns Key columns
     */
    public function __construct(
        public AlterAction $action,
        public string $name = '',
        public array $columns = [],
        public ?AlterColumn $column = null,
        public ?ForeignKey $foreign = null,
        public mixed $value = null,
    ) {
    }

    /**
     * @throws LogicException When the action carries no column
     */
    public function column(): AlterColumn
    {
        return $this->column ?? throw new LogicException($this->action->name . ' has no column');
    }

    /**
     * @throws LogicException When the action carries no foreign key
     */
    public function foreign(): ForeignKey
    {
        return $this->foreign ?? throw new LogicException($this->action->name . ' has no foreign key');
    }
}
