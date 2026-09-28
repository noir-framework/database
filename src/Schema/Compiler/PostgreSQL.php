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

class PostgreSQL extends Compiler
{
    /** @var list<'unsigned'|'nullable'|'default'|'autoincrement'> */
    protected array $modifiers = ['nullable', 'default'];

    #[Override]
    public function getColumns(string $database, string $table): array
    {
        $sql = 'SELECT ' . $this->wrap('column_name') . ' AS ' . $this->wrap('name')
            . ', ' . $this->wrap('udt_name') . ' AS ' . $this->wrap('type')
            . ' FROM ' . $this->wrap('information_schema') . '.' . $this->wrap('columns')
            . ' WHERE ' . $this->wrap('table_schema') . ' = ? AND ' . $this->wrap('table_name') . ' = ? '
            . ' ORDER BY ' . $this->wrap('ordinal_position') . ' ASC';

        return ['sql' => $sql, 'params' => [$database, $table]];
    }

    #[Override]
    public function currentDatabase(string $dsn): string|array
    {
        return ['sql' => 'SELECT current_schema()', 'params' => []];
    }

    #[Override]
    public function renameTable(string $current, string $new): array
    {
        return ['sql' => 'ALTER TABLE ' . $this->wrap($current) . ' RENAME TO ' . $this->wrap($new), 'params' => []];
    }

    /**
     * Auto-increment integers become SERIAL types.
     */
    #[Override]
    protected function handleTypeInteger(BaseColumn $column): string
    {
        $serial = $column->isAutoincrement();

        return match ($column->getSize()) {
            'tiny', 'small' => $serial ? 'SMALLSERIAL' : 'SMALLINT',
            'medium', 'normal' => $serial ? 'SERIAL' : 'INTEGER',
            'big' => $serial ? 'BIGSERIAL' : 'BIGINT',
        };
    }

    #[Override]
    protected function handleTypeFloat(BaseColumn $column): string
    {
        return 'REAL';
    }

    #[Override]
    protected function handleTypeDouble(BaseColumn $column): string
    {
        return 'DOUBLE PRECISION';
    }

    #[Override]
    protected function handleTypeDecimal(BaseColumn $column): string
    {
        return $this->decimal($column, ' ');
    }

    #[Override]
    protected function handleTypeBinary(BaseColumn $column): string
    {
        return 'BYTEA';
    }

    #[Override]
    protected function handleTypeTime(BaseColumn $column): string
    {
        return 'TIME(0) WITHOUT TIME ZONE';
    }

    #[Override]
    protected function handleTypeTimestamp(BaseColumn $column): string
    {
        return 'TIMESTAMP(0) WITHOUT TIME ZONE';
    }

    #[Override]
    protected function handleTypeDateTime(BaseColumn $column): string
    {
        return 'TIMESTAMP(0) WITHOUT TIME ZONE';
    }

    /**
     * Index names are schema-wide in PostgreSQL, so they are prefixed with the table name.
     */
    #[Override]
    protected function handleIndexKeys(CreateTable $schema): array
    {
        $sql = [];
        $table = $schema->getTableName();
        foreach ($schema->getIndexes() as $name => $columns) {
            $sql[] = 'CREATE INDEX ' . $this->wrap($table . '_' . $name) . ' ON ' . $this->wrap($table)
                . '(' . $this->wrapArray($columns) . ')';
        }

        return $sql;
    }

    #[Override]
    protected function handleRenameColumn(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' RENAME COLUMN ' . $this->wrap($command->name)
            . ' TO ' . $this->wrap($command->column()->getName());
    }

    #[Override]
    protected function handleAddIndex(AlterTable $table, AlterCommand $command): string
    {
        return 'CREATE INDEX ' . $this->wrap($table->getTableName() . '_' . $command->name)
            . ' ON ' . $this->wrap($table->getTableName()) . ' (' . $this->wrapArray($command->columns) . ')';
    }

    #[Override]
    protected function handleDropIndex(AlterTable $table, AlterCommand $command): string
    {
        return 'DROP INDEX ' . $this->wrap($table->getTableName() . '_' . $command->name);
    }

    #[Override]
    protected function handleEngine(CreateTable $schema): string
    {
        return '';
    }
}
