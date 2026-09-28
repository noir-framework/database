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

use function str_replace;

class SQLServer extends Compiler
{
    protected string $wrapper = '[%s]';

    /** @var list<'unsigned'|'nullable'|'default'|'autoincrement'> */
    protected array $modifiers = ['nullable', 'default', 'autoincrement'];

    protected string $autoincrement = 'IDENTITY';

    #[Override]
    public function renameTable(string $current, string $new): array
    {
        return ['sql' => 'sp_rename ' . $this->wrap($current) . ', ' . $this->wrap($new), 'params' => []];
    }

    #[Override]
    public function currentDatabase(string $dsn): string|array
    {
        return ['sql' => 'SELECT SCHEMA_NAME()', 'params' => []];
    }

    #[Override]
    public function getColumns(string $database, string $table): array
    {
        $sql = 'SELECT ' . $this->wrap('column_name') . ' AS ' . $this->wrap('name')
            . ', ' . $this->wrap('data_type') . ' AS ' . $this->wrap('type')
            . ' FROM ' . $this->wrap('information_schema') . '.' . $this->wrap('columns')
            . ' WHERE ' . $this->wrap('table_schema') . ' = ? AND ' . $this->wrap('table_name') . ' = ? '
            . ' ORDER BY ' . $this->wrap('ordinal_position') . ' ASC';

        return ['sql' => $sql, 'params' => [$database, $table]];
    }

    #[Override]
    protected function handleTypeInteger(BaseColumn $column): string
    {
        return match ($column->getSize()) {
            'tiny' => 'TINYINT',
            'small' => 'SMALLINT',
            'medium', 'normal' => 'INTEGER',
            'big' => 'BIGINT',
        };
    }

    /**
     * SQL Server has no DOUBLE type; FLOAT(53) is its double precision.
     */
    #[Override]
    protected function handleTypeDouble(BaseColumn $column): string
    {
        return 'FLOAT(53)';
    }

    #[Override]
    protected function handleTypeDecimal(BaseColumn $column): string
    {
        return $this->decimal($column, ' ');
    }

    #[Override]
    protected function handleTypeBoolean(BaseColumn $column): string
    {
        return 'BIT';
    }

    #[Override]
    protected function handleTypeString(BaseColumn $column): string
    {
        return 'NVARCHAR(' . $this->value($column->getLength() ?? 255) . ')';
    }

    #[Override]
    protected function handleTypeFixed(BaseColumn $column): string
    {
        return 'NCHAR(' . $this->value($column->getLength() ?? 255) . ')';
    }

    #[Override]
    protected function handleTypeText(BaseColumn $column): string
    {
        return 'NVARCHAR(max)';
    }

    #[Override]
    protected function handleTypeBinary(BaseColumn $column): string
    {
        return 'VARBINARY(max)';
    }

    #[Override]
    protected function handleTypeJson(BaseColumn $column): string
    {
        return 'NVARCHAR(max)';
    }

    #[Override]
    protected function handleTypeTimestamp(BaseColumn $column): string
    {
        return 'DATETIME';
    }

    /**
     * sp_rename takes the names as strings: `EXEC sp_rename N'table.old', N'new', 'COLUMN'`.
     */
    #[Override]
    protected function handleRenameColumn(AlterTable $table, AlterCommand $command): string
    {
        return "EXEC sp_rename N'" . $this->literal($table->getTableName() . '.' . $command->name) . "', N'"
            . $this->literal($command->column()->getName()) . "', 'COLUMN'";
    }

    private function literal(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    #[Override]
    protected function handleEngine(CreateTable $schema): string
    {
        return '';
    }
}
