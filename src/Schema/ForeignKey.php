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

declare(strict_types=1);namespace Noirapi\Database\Schema;

use function array_values;
use function in_array;
use function strtoupper;

/**
 * FOREIGN KEY (columns) REFERENCES table (columns) [ON DELETE ...] [ON UPDATE ...]
 */
class ForeignKey
{
    protected string $refTable = '';

    /** @var list<string> */
    protected array $refColumns = [];

    /** @var array<'ON DELETE'|'ON UPDATE', string> */
    protected array $actions = [];

    /**
     * @param list<string> $columns
     */
    public function __construct(protected array $columns)
    {
    }

    public function getReferencedTable(): string
    {
        return $this->refTable;
    }

    /**
     * @return list<string>
     */
    public function getReferencedColumns(): array
    {
        return $this->refColumns;
    }

    /**
     * @return list<string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<'ON DELETE'|'ON UPDATE', string>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    public function references(string $table, string ...$columns): static
    {
        $this->refTable = $table;
        $this->refColumns = array_values($columns);

        return $this;
    }

    /**
     * @param string $action RESTRICT, CASCADE, NO ACTION or SET NULL; anything else is ignored
     */
    public function onDelete(string $action): static
    {
        return $this->addAction('ON DELETE', $action);
    }

    /**
     * @param string $action RESTRICT, CASCADE, NO ACTION or SET NULL; anything else is ignored
     */
    public function onUpdate(string $action): static
    {
        return $this->addAction('ON UPDATE', $action);
    }

    /**
     * @param 'ON DELETE'|'ON UPDATE' $on
     */
    protected function addAction(string $on, string $action): static
    {
        $action = strtoupper($action);
        if (!in_array($action, ['RESTRICT', 'CASCADE', 'NO ACTION', 'SET NULL'], true)) {
            return $this;
        }

        $this->actions[$on] = $action;

        return $this;
    }
}
