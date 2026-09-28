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
 * CREATE TABLE definition passed to Schema::create() callbacks.
 */
class CreateTable
{
    /** @var array<string, CreateColumn> */
    protected array $columns = [];

    /** @var array{name: string, columns: list<string>}|null */
    protected ?array $primaryKey = null;

    /** @var array<string, list<string>> */
    protected array $uniqueKeys = [];

    /** @var array<string, list<string>> */
    protected array $indexes = [];

    /** @var array<string, ForeignKey> */
    protected array $foreignKeys = [];

    protected ?string $engine = null;

    protected ?CreateColumn $autoincrement = null;

    public function __construct(protected string $table)
    {
    }

    public function getTableName(): string
    {
        return $this->table;
    }

    /**
     * @return array<string, CreateColumn>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return array{name: string, columns: list<string>}|null
     */
    public function getPrimaryKey(): ?array
    {
        return $this->primaryKey;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getUniqueKeys(): array
    {
        return $this->uniqueKeys;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * @return array<string, ForeignKey>
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function getEngine(): ?string
    {
        return $this->engine;
    }

    public function getAutoincrement(): ?CreateColumn
    {
        return $this->autoincrement;
    }

    public function engine(string $name): static
    {
        $this->engine = $name;

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function primary(string|array $columns, ?string $name = null): static
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->primaryKey = [
            'name' => $name ?? $this->table . '_pk_' . implode('_', $columns),
            'columns' => $columns,
        ];

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): static
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->uniqueKeys[$name ?? $this->table . '_uk_' . implode('_', $columns)] = $columns;

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): static
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $this->indexes[$name ?? $this->table . '_ik_' . implode('_', $columns)] = $columns;

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function foreign(string|array $columns, ?string $name = null): ForeignKey
    {
        $columns = is_array($columns) ? $columns : [$columns];

        return $this->foreignKeys[$name ?? $this->table . '_fk_' . implode('_', $columns)] = new ForeignKey($columns);
    }

    /**
     * Marks an integer column as auto-incrementing primary key; ignored for other types.
     */
    public function autoincrement(CreateColumn $column, ?string $name = null): static
    {
        if ($column->getType() !== 'integer') {
            return $this;
        }

        $this->autoincrement = $column->set('autoincrement', true);

        return $this->primary($column->getName(), $name);
    }

    public function integer(string $name): CreateColumn
    {
        return $this->addColumn($name, 'integer');
    }

    public function float(string $name): CreateColumn
    {
        return $this->addColumn($name, 'float');
    }

    public function double(string $name): CreateColumn
    {
        return $this->addColumn($name, 'double');
    }

    public function boolean(string $name): CreateColumn
    {
        return $this->addColumn($name, 'boolean');
    }

    public function binary(string $name): CreateColumn
    {
        return $this->addColumn($name, 'binary');
    }

    public function text(string $name): CreateColumn
    {
        return $this->addColumn($name, 'text');
    }

    public function time(string $name): CreateColumn
    {
        return $this->addColumn($name, 'time');
    }

    public function timestamp(string $name): CreateColumn
    {
        return $this->addColumn($name, 'timestamp');
    }

    public function date(string $name): CreateColumn
    {
        return $this->addColumn($name, 'date');
    }

    public function dateTime(string $name): CreateColumn
    {
        return $this->addColumn($name, 'dateTime');
    }

    public function decimal(string $name, ?int $length = null, ?int $precision = null): CreateColumn
    {
        return $this->addColumn($name, 'decimal')->length($length)->set('precision', $precision);
    }

    public function string(string $name, int $length = 255): CreateColumn
    {
        return $this->addColumn($name, 'string')->length($length);
    }

    public function fixed(string $name, int $length = 255): CreateColumn
    {
        return $this->addColumn($name, 'fixed')->length($length);
    }

    public function softDelete(string $column = 'deleted_at'): static
    {
        $this->dateTime($column);

        return $this;
    }

    public function timestamps(string $createColumn = 'created_at', string $updateColumn = 'updated_at'): static
    {
        $this->dateTime($createColumn)->notNull();
        $this->dateTime($updateColumn);

        return $this;
    }

    protected function addColumn(string $name, string $type): CreateColumn
    {
        return $this->columns[$name] = new CreateColumn($this, $name, $type);
    }
}
