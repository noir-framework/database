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

namespace Noirapi\Database\Schema\Compiler;

use Noirapi\Database\Schema\AlterCommand;
use Noirapi\Database\Schema\AlterTable;
use Noirapi\Database\Schema\BaseColumn;
use Noirapi\Database\Schema\Compiler;
use Noirapi\Database\Schema\CreateTable;
use Override;

use function strpos;
use function substr;

class SQLite extends Compiler
{
    /** @var list<'unsigned'|'nullable'|'default'|'autoincrement'> */
    protected array $modifiers = ['nullable', 'default', 'autoincrement'];

    protected string $autoincrement = 'AUTOINCREMENT';

    /** Set once an AUTOINCREMENT column already declared the primary key inline. */
    private bool $nopk = false;

    /**
     * The database of an SQLite DSN is its file path.
     */
    #[Override]
    public function currentDatabase(string $dsn): string
    {
        $colon = strpos($dsn, ':');

        return substr($dsn, $colon === false ? 0 : $colon + 1);
    }

    #[Override]
    public function getTables(string $database): array
    {
        $sql = 'SELECT ' . $this->wrap('name') . ' FROM ' . $this->wrap('sqlite_master')
            . ' WHERE type = ? ORDER BY ' . $this->wrap('name') . ' ASC';

        return ['sql' => $sql, 'params' => ['table']];
    }

    #[Override]
    public function getColumns(string $database, string $table): array
    {
        return ['sql' => 'PRAGMA table_info(' . $this->wrap($table) . ')', 'params' => []];
    }

    #[Override]
    public function renameTable(string $current, string $new): array
    {
        return ['sql' => 'ALTER TABLE ' . $this->wrap($current) . ' RENAME TO ' . $this->wrap($new), 'params' => []];
    }

    #[Override]
    public function handleModifierAutoincrement(BaseColumn $column): string
    {
        $modifier = parent::handleModifierAutoincrement($column);
        if ($modifier !== '') {
            $this->nopk = true;
            $modifier = 'PRIMARY KEY ' . $modifier;
        }

        return $modifier;
    }

    #[Override]
    public function handlePrimaryKey(CreateTable $schema): string
    {
        if ($this->nopk) {
            return '';
        }

        return parent::handlePrimaryKey($schema);
    }

    #[Override]
    protected function handleTypeInteger(BaseColumn $column): string
    {
        return 'INTEGER';
    }

    #[Override]
    protected function handleTypeTime(BaseColumn $column): string
    {
        return 'DATETIME';
    }

    #[Override]
    protected function handleTypeTimestamp(BaseColumn $column): string
    {
        return 'DATETIME';
    }

    #[Override]
    protected function handleEngine(CreateTable $schema): string
    {
        return '';
    }

    #[Override]
    protected function handleAddUnique(AlterTable $table, AlterCommand $command): string
    {
        return 'CREATE UNIQUE INDEX ' . $this->wrap($command->name) . ' ON '
            . $this->wrap($table->getTableName()) . '(' . $this->wrapArray($command->columns) . ')';
    }

    #[Override]
    protected function handleAddIndex(AlterTable $table, AlterCommand $command): string
    {
        return 'CREATE INDEX ' . $this->wrap($command->name) . ' ON '
            . $this->wrap($table->getTableName()) . '(' . $this->wrapArray($command->columns) . ')';
    }
}
