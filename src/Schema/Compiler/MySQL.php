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
use Override;

class MySQL extends Compiler
{
    protected string $wrapper = '`%s`';

    #[Override]
    protected function handleTypeInteger(BaseColumn $column): string
    {
        return match ($column->getSize()) {
            'tiny' => 'TINYINT',
            'small' => 'SMALLINT',
            'medium' => 'MEDIUMINT',
            'big' => 'BIGINT',
            'normal' => 'INT',
        };
    }

    #[Override]
    protected function handleTypeDecimal(BaseColumn $column): string
    {
        return $this->decimal($column);
    }

    #[Override]
    protected function handleTypeBoolean(BaseColumn $column): string
    {
        return 'TINYINT(1)';
    }

    #[Override]
    protected function handleTypeText(BaseColumn $column): string
    {
        return match ($column->getSize()) {
            'tiny', 'small' => 'TINYTEXT',
            'medium' => 'MEDIUMTEXT',
            'big' => 'LONGTEXT',
            'normal' => 'TEXT',
        };
    }

    #[Override]
    protected function handleTypeBinary(BaseColumn $column): string
    {
        return match ($column->getSize()) {
            'tiny', 'small' => 'TINYBLOB',
            'medium' => 'MEDIUMBLOB',
            'big' => 'LONGBLOB',
            'normal' => 'BLOB',
        };
    }

    #[Override]
    protected function handleDropPrimaryKey(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' DROP PRIMARY KEY';
    }

    #[Override]
    protected function handleDropUniqueKey(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' DROP INDEX ' . $this->wrap($command->name);
    }

    #[Override]
    protected function handleDropIndex(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' DROP INDEX ' . $this->wrap($command->name);
    }

    #[Override]
    protected function handleDropForeignKey(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' DROP FOREIGN KEY ' . $this->wrap($command->name);
    }

    #[Override]
    protected function handleSetDefaultValue(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' ALTER ' . $this->wrap($command->name)
            . ' SET DEFAULT ' . $this->value($command->value);
    }

    #[Override]
    protected function handleDropDefaultValue(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' ALTER ' . $this->wrap($command->name) . ' DROP DEFAULT';
    }
}
