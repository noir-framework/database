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

use function implode;
use function is_array;

/**
 * ALTER TABLE operations passed to Schema::alter() callbacks.
 */
class AlterTable
{
    /** @var list<AlterCommand> */
    protected array $commands = [];

    public function __construct(protected string $table)
    {
    }

    public function getTableName(): string
    {
        return $this->table;
    }

    /**
     * @return list<AlterCommand>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    public function dropIndex(string $name): static
    {
        return $this->addCommand(new AlterCommand(AlterAction::DropIndex, $name));
    }

    public function dropUnique(string $name): static
    {
        return $this->addCommand(new AlterCommand(AlterAction::DropUniqueKey, $name));
    }

    public function dropPrimary(string $name): static
    {
        return $this->addCommand(new AlterCommand(AlterAction::DropPrimaryKey, $name));
    }

    public function dropForeign(string $name): static
    {
        return $this->addCommand(new AlterCommand(AlterAction::DropForeignKey, $name));
    }

    public function dropColumn(string $name): static
    {
        return $this->addCommand(new AlterCommand(AlterAction::DropColumn, $name));
    }

    public function dropDefaultValue(string $column): static
    {
        return $this->addCommand(new AlterCommand(AlterAction::DropDefaultValue, $column));
    }

    public function renameColumn(string $from, string $to): static
    {
        return $this->addCommand(
            new AlterCommand(AlterAction::RenameColumn, $from, column: new AlterColumn($this, $to)),
        );
    }

    /**
     * @param string|list<string> $columns
     */
    public function primary(string|array $columns, ?string $name = null): static
    {
        return $this->addKey(AlterAction::AddPrimary, 'pk', $columns, $name);
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): static
    {
        return $this->addKey(AlterAction::AddUnique, 'uk', $columns, $name);
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): static
    {
        return $this->addKey(AlterAction::AddIndex, 'ik', $columns, $name);
    }

    /**
     * @param string|list<string> $columns
     */
    public function foreign(string|array $columns, ?string $name = null): ForeignKey
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $foreign = new ForeignKey($columns);
        $this->addCommand(new AlterCommand(
            AlterAction::AddForeign,
            $name ?? $this->table . '_fk_' . implode('_', $columns),
            foreign: $foreign,
        ));

        return $foreign;
    }

    public function setDefaultValue(string $column, mixed $value): static
    {
        return $this->addCommand(new AlterCommand(AlterAction::SetDefaultValue, $column, value: $value));
    }

    public function integer(string $name): AlterColumn
    {
        return $this->addColumn($name, 'integer');
    }

    public function float(string $name): AlterColumn
    {
        return $this->addColumn($name, 'float');
    }

    public function double(string $name): AlterColumn
    {
        return $this->addColumn($name, 'double');
    }

    public function boolean(string $name): AlterColumn
    {
        return $this->addColumn($name, 'boolean');
    }

    public function binary(string $name): AlterColumn
    {
        return $this->addColumn($name, 'binary');
    }

    public function text(string $name): AlterColumn
    {
        return $this->addColumn($name, 'text');
    }

    public function time(string $name): AlterColumn
    {
        return $this->addColumn($name, 'time');
    }

    public function timestamp(string $name): AlterColumn
    {
        return $this->addColumn($name, 'timestamp');
    }

    public function date(string $name): AlterColumn
    {
        return $this->addColumn($name, 'date');
    }

    public function dateTime(string $name): AlterColumn
    {
        return $this->addColumn($name, 'dateTime');
    }

    public function decimal(string $name, ?int $length = null, ?int $precision = null): AlterColumn
    {
        return $this->addColumn($name, 'decimal')->set('length', $length)->set('precision', $precision);
    }

    public function string(string $name, int $length = 255): AlterColumn
    {
        return $this->addColumn($name, 'string')->set('length', $length);
    }

    public function fixed(string $name, int $length = 255): AlterColumn
    {
        return $this->addColumn($name, 'fixed')->set('length', $length);
    }

    public function toInteger(string $name): AlterColumn
    {
        return $this->modifyColumn($name, 'integer');
    }

    public function toFloat(string $name): AlterColumn
    {
        return $this->modifyColumn($name, 'float');
    }

    public function toDouble(string $name): AlterColumn
    {
        return $this->modifyColumn($name, 'double');
    }

    public function toBoolean(string $name): AlterColumn
    {
        return $this->modifyColumn($name, 'boolean');
    }

    public function toBinary(string $name): AlterColumn
    {
        return $this->modifyColumn($name, 'binary');
    }

    public function toText(string $name): AlterColumn
    {
        return $this->modifyColumn($name, 'text');
    }

    public function toTime(string $name): AlterColumn
    {
        return $this->modifyColumn($name, 'time');
    }

    public function toTimestamp(string $name): AlterColumn
    {
        return $this->modifyColumn($name, 'timestamp');
    }

    public function toDate(string $name): AlterColumn
    {
        return $this->modifyColumn($name, 'date');
    }

    public function toDateTime(string $name): AlterColumn
    {
        return $this->modifyColumn($name, 'dateTime');
    }

    public function toDecimal(string $name, ?int $length = null, ?int $precision = null): AlterColumn
    {
        return $this->modifyColumn($name, 'decimal')->set('length', $length)->set('precision', $precision);
    }

    public function toString(string $name, int $length = 255): AlterColumn
    {
        return $this->modifyColumn($name, 'string')->set('length', $length);
    }

    public function toFixed(string $name, int $length = 255): AlterColumn
    {
        return $this->modifyColumn($name, 'fixed')->set('length', $length);
    }

    protected function addCommand(AlterCommand $command): static
    {
        $this->commands[] = $command;

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    protected function addKey(AlterAction $action, string $suffix, string|array $columns, ?string $name): static
    {
        $columns = is_array($columns) ? $columns : [$columns];

        return $this->addCommand(
            new AlterCommand($action, $name ?? $this->table . '_' . $suffix . '_' . implode('_', $columns), $columns),
        );
    }

    protected function addColumn(string $name, string $type): AlterColumn
    {
        $column = new AlterColumn($this, $name, $type);
        $this->addCommand(new AlterCommand(AlterAction::AddColumn, $name, column: $column));

        return $column;
    }

    protected function modifyColumn(string $name, string $type): AlterColumn
    {
        $column = new AlterColumn($this, $name, $type);
        $column->set('handleDefault', false);
        $this->addCommand(new AlterCommand(AlterAction::ModifyColumn, $name, column: $column));

        return $column;
    }
}
