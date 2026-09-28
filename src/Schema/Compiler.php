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

use InvalidArgumentException;
use Noirapi\Database\Connection;

use function array_map;
use function implode;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function sprintf;
use function str_replace;
use function strtoupper;
use function trim;

/**
 * Generic DDL compiler; dialects override type names, modifiers and ALTER forms.
 *
 * @psalm-type Command = array{sql: string, params: list<mixed>}
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") One small method per construct, overridable per dialect.
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Knows every clause value object it compiles.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") Hooks: dialects use parameters the base ignores.
 */
class Compiler
{
    protected string $separator = ';';

    /** sprintf() pattern used to quote table and column names. */
    protected string $wrapper = '"%s"';

    /** @var list<mixed> */
    protected array $params = [];

    /** @var list<'unsigned'|'nullable'|'default'|'autoincrement'> Modifiers emitted, in order */
    protected array $modifiers = ['unsigned', 'nullable', 'default', 'autoincrement'];

    /** @var list<string> Integer sizes that may auto-increment */
    protected array $serials = ['tiny', 'small', 'normal', 'medium', 'big'];

    protected string $autoincrement = 'AUTO_INCREMENT';

    /**
     * The connection is only needed by dialects that inspect the live schema (MySQL column renames).
     */
    public function __construct(protected ?Connection $connection = null)
    {
    }

    /**
     * @param array<string, string> $options Supported keys: wrapper
     *
     * @throws InvalidArgumentException On an unknown option
     */
    public function setOptions(array $options): static
    {
        foreach ($options as $name => $value) {
            match ($name) {
                'wrapper' => $this->wrapper = $value,
                default => throw new InvalidArgumentException('Unknown schema compiler option: ' . $name),
            };
        }

        return $this;
    }

    /**
     * @return list<mixed>
     */
    public function getParams(): array
    {
        $params = $this->params;
        $this->params = [];

        return $params;
    }

    /**
     * Returns the current database name directly, or the query that selects it.
     *
     * @return string|Command
     */
    public function currentDatabase(string $dsn): string|array
    {
        return ['sql' => 'SELECT database()', 'params' => []];
    }

    /**
     * @return Command
     */
    public function renameTable(string $current, string $new): array
    {
        return ['sql' => 'RENAME TABLE ' . $this->wrap($current) . ' TO ' . $this->wrap($new), 'params' => []];
    }

    /**
     * @return Command
     */
    public function getTables(string $database): array
    {
        $sql = 'SELECT ' . $this->wrap('table_name') . ' FROM ' . $this->wrap('information_schema')
            . '.' . $this->wrap('tables') . ' WHERE table_type = ? AND table_schema = ? ORDER BY '
            . $this->wrap('table_name') . ' ASC';

        return ['sql' => $sql, 'params' => ['BASE TABLE', $database]];
    }

    /**
     * @return Command
     */
    public function getViews(string $database): array
    {
        $sql = 'SELECT ' . $this->wrap('table_name') . ' FROM ' . $this->wrap('information_schema')
            . '.' . $this->wrap('tables') . ' WHERE table_type = ? AND table_schema = ? ORDER BY '
            . $this->wrap('table_name') . ' ASC';

        return ['sql' => $sql, 'params' => ['VIEW', $database]];
    }

    /**
     * @param string $select The view's SELECT, with its values inlined
     *
     * @return Command
     */
    public function createView(string $view, string $select): array
    {
        return ['sql' => 'CREATE VIEW ' . $this->wrap($view) . ' AS ' . $select, 'params' => []];
    }

    /**
     * @return Command
     */
    public function dropView(string $view): array
    {
        return ['sql' => 'DROP VIEW ' . $this->wrap($view), 'params' => []];
    }

    /**
     * @return Command
     */
    public function getColumns(string $database, string $table): array
    {
        $sql = 'SELECT ' . $this->wrap('column_name') . ' AS ' . $this->wrap('name')
            . ', ' . $this->wrap('column_type') . ' AS ' . $this->wrap('type')
            . ' FROM ' . $this->wrap('information_schema') . '.' . $this->wrap('columns')
            . ' WHERE ' . $this->wrap('table_schema') . ' = ? AND ' . $this->wrap('table_name') . ' = ? '
            . ' ORDER BY ' . $this->wrap('ordinal_position') . ' ASC';

        return ['sql' => $sql, 'params' => [$database, $table]];
    }

    /**
     * @return list<Command>
     */
    public function create(CreateTable $schema): array
    {
        $sql = 'CREATE TABLE ' . $this->wrap($schema->getTableName());
        $sql .= "(\n";
        $sql .= $this->handleColumns($schema->getColumns());
        $sql .= $this->handlePrimaryKey($schema);
        $sql .= $this->handleUniqueKeys($schema);
        $sql .= $this->handleForeignKeys($schema);
        $sql .= "\n)" . $this->handleEngine($schema);

        $commands = [['sql' => $sql, 'params' => $this->getParams()]];
        foreach ($this->handleIndexKeys($schema) as $index) {
            $commands[] = ['sql' => $index, 'params' => []];
        }

        return $commands;
    }

    /**
     * @return list<Command>
     */
    public function alter(AlterTable $schema): array
    {
        $commands = [];
        foreach ($schema->getCommands() as $command) {
            $sql = $this->handleAlterCommand($schema, $command);
            if ($sql === '') {
                continue;
            }

            $commands[] = ['sql' => $sql, 'params' => $this->getParams()];
        }

        return $commands;
    }

    /**
     * @return Command
     */
    public function drop(string $table): array
    {
        return ['sql' => 'DROP TABLE ' . $this->wrap($table), 'params' => []];
    }

    /**
     * @return Command
     */
    public function truncate(string $table): array
    {
        return ['sql' => 'TRUNCATE TABLE ' . $this->wrap($table), 'params' => []];
    }

    protected function wrap(string $name): string
    {
        return sprintf($this->wrapper, $name);
    }

    /**
     * @param list<string> $value
     */
    protected function wrapArray(array $value, string $separator = ', '): string
    {
        return implode($separator, array_map($this->wrap(...), $value));
    }

    /**
     * Renders a literal for DDL: numbers verbatim, booleans as 1/0, strings quoted, anything else NULL.
     */
    protected function value(mixed $value): string
    {
        return match (true) {
            is_int($value), is_float($value) => (string) $value,
            is_string($value) && is_numeric($value) => $value,
            is_bool($value) => $value ? '1' : '0',
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            default => 'NULL',
        };
    }

    /**
     * @param array<BaseColumn> $columns
     */
    protected function handleColumns(array $columns): string
    {
        $sql = [];
        foreach ($columns as $column) {
            $sql[] = $this->wrap($column->getName()) . $this->handleColumnType($column)
                . $this->handleColumnModifiers($column);
        }

        return implode(",\n", $sql);
    }

    protected function handleColumnType(BaseColumn $column): string
    {
        $result = trim(match ($column->getType()) {
            'integer' => $this->handleTypeInteger($column),
            'float' => $this->handleTypeFloat($column),
            'double' => $this->handleTypeDouble($column),
            'decimal' => $this->handleTypeDecimal($column),
            'boolean' => $this->handleTypeBoolean($column),
            'binary' => $this->handleTypeBinary($column),
            'text' => $this->handleTypeText($column),
            'string' => $this->handleTypeString($column),
            'fixed' => $this->handleTypeFixed($column),
            'time' => $this->handleTypeTime($column),
            'timestamp' => $this->handleTypeTimestamp($column),
            'date' => $this->handleTypeDate($column),
            'dateTime' => $this->handleTypeDateTime($column),
            'json' => $this->handleTypeJson($column),
            '' => '',
            default => throw new InvalidArgumentException('Unknown column type: ' . $column->getType()),
        });

        return $result === '' ? '' : ' ' . $result;
    }

    protected function handleColumnModifiers(BaseColumn $column): string
    {
        $line = '';
        foreach ($this->modifiers as $modifier) {
            $result = trim(match ($modifier) {
                'unsigned' => $this->handleModifierUnsigned($column),
                'nullable' => $this->handleModifierNullable($column),
                'default' => $this->handleModifierDefault($column),
                'autoincrement' => $this->handleModifierAutoincrement($column),
            });
            $line .= $result === '' ? '' : ' ' . $result;
        }

        return $line;
    }

    protected function handleTypeInteger(BaseColumn $column): string
    {
        return 'INT';
    }

    protected function handleTypeFloat(BaseColumn $column): string
    {
        return 'FLOAT';
    }

    protected function handleTypeDouble(BaseColumn $column): string
    {
        return 'DOUBLE';
    }

    protected function handleTypeDecimal(BaseColumn $column): string
    {
        return 'DECIMAL';
    }

    protected function handleTypeBoolean(BaseColumn $column): string
    {
        return 'BOOLEAN';
    }

    protected function handleTypeBinary(BaseColumn $column): string
    {
        return 'BLOB';
    }

    protected function handleTypeText(BaseColumn $column): string
    {
        return 'TEXT';
    }

    protected function handleTypeString(BaseColumn $column): string
    {
        return 'VARCHAR(' . $this->value($column->getLength() ?? 255) . ')';
    }

    protected function handleTypeFixed(BaseColumn $column): string
    {
        return 'CHAR(' . $this->value($column->getLength() ?? 255) . ')';
    }

    protected function handleTypeTime(BaseColumn $column): string
    {
        return 'TIME';
    }

    protected function handleTypeTimestamp(BaseColumn $column): string
    {
        return 'TIMESTAMP';
    }

    protected function handleTypeDate(BaseColumn $column): string
    {
        return 'DATE';
    }

    protected function handleTypeDateTime(BaseColumn $column): string
    {
        return 'DATETIME';
    }

    protected function handleTypeJson(BaseColumn $column): string
    {
        return 'JSON';
    }

    /**
     * DECIMAL[(length[, precision])] with the given separator before the parenthesis.
     */
    protected function decimal(BaseColumn $column, string $space = ''): string
    {
        $length = $column->getLength();
        if ($length === null) {
            return 'DECIMAL';
        }

        $precision = $column->getPrecision();

        return 'DECIMAL' . $space . '(' . $this->value($length)
            . ($precision === null ? '' : ', ' . $this->value($precision)) . ')';
    }

    protected function handleModifierUnsigned(BaseColumn $column): string
    {
        return $column->isUnsigned() ? 'UNSIGNED' : '';
    }

    protected function handleModifierNullable(BaseColumn $column): string
    {
        return $column->isNullable() ? '' : 'NOT NULL';
    }

    protected function handleModifierDefault(BaseColumn $column): string
    {
        return $column->getDefault() === null ? '' : 'DEFAULT ' . $this->value($column->getDefault());
    }

    protected function handleModifierAutoincrement(BaseColumn $column): string
    {
        if ($column->getType() !== 'integer' || !in_array($column->getSize(), $this->serials, true)) {
            return '';
        }

        return $column->isAutoincrement() ? $this->autoincrement : '';
    }

    protected function handlePrimaryKey(CreateTable $schema): string
    {
        $primaryKey = $schema->getPrimaryKey();
        if ($primaryKey === null) {
            return '';
        }

        return ",\n" . 'CONSTRAINT ' . $this->wrap($primaryKey['name'])
            . ' PRIMARY KEY (' . $this->wrapArray($primaryKey['columns']) . ')';
    }

    protected function handleUniqueKeys(CreateTable $schema): string
    {
        $keys = $schema->getUniqueKeys();
        if ($keys === []) {
            return '';
        }

        $sql = [];
        foreach ($keys as $name => $columns) {
            $sql[] = 'CONSTRAINT ' . $this->wrap($name) . ' UNIQUE (' . $this->wrapArray($columns) . ')';
        }

        return ",\n" . implode(",\n", $sql);
    }

    /**
     * @return list<string>
     */
    protected function handleIndexKeys(CreateTable $schema): array
    {
        $sql = [];
        $table = $this->wrap($schema->getTableName());
        foreach ($schema->getIndexes() as $name => $columns) {
            $sql[] = 'CREATE INDEX ' . $this->wrap($name) . ' ON ' . $table . '(' . $this->wrapArray($columns) . ')';
        }

        return $sql;
    }

    protected function handleForeignKeys(CreateTable $schema): string
    {
        $keys = $schema->getForeignKeys();
        if ($keys === []) {
            return '';
        }

        $sql = [];
        foreach ($keys as $name => $key) {
            $cmd = 'CONSTRAINT ' . $this->wrap($name) . ' FOREIGN KEY (' . $this->wrapArray($key->getColumns()) . ') ';
            $cmd .= 'REFERENCES ' . $this->wrap($key->getReferencedTable())
                . ' (' . $this->wrapArray($key->getReferencedColumns()) . ')';
            foreach ($key->getActions() as $actionName => $action) {
                $cmd .= ' ' . $actionName . ' ' . $action;
            }
            $sql[] = $cmd;
        }

        return ",\n" . implode(",\n", $sql);
    }

    protected function handleEngine(CreateTable $schema): string
    {
        $engine = $schema->getEngine();

        return $engine === null ? '' : ' ENGINE = ' . strtoupper($engine);
    }

    protected function handleAlterCommand(AlterTable $table, AlterCommand $command): string
    {
        return match ($command->action) {
            AlterAction::AddColumn => $this->handleAddColumn($table, $command),
            AlterAction::ModifyColumn => $this->handleModifyColumn($table, $command),
            AlterAction::RenameColumn => $this->handleRenameColumn($table, $command),
            AlterAction::DropColumn => $this->handleDropColumn($table, $command),
            AlterAction::AddPrimary => $this->handleAddPrimary($table, $command),
            AlterAction::AddUnique => $this->handleAddUnique($table, $command),
            AlterAction::AddIndex => $this->handleAddIndex($table, $command),
            AlterAction::AddForeign => $this->handleAddForeign($table, $command),
            AlterAction::DropPrimaryKey => $this->handleDropPrimaryKey($table, $command),
            AlterAction::DropUniqueKey => $this->handleDropUniqueKey($table, $command),
            AlterAction::DropIndex => $this->handleDropIndex($table, $command),
            AlterAction::DropForeignKey => $this->handleDropForeignKey($table, $command),
            AlterAction::SetDefaultValue => $this->handleSetDefaultValue($table, $command),
            AlterAction::DropDefaultValue => $this->handleDropDefaultValue($table, $command),
        };
    }

    protected function alterTable(AlterTable $table): string
    {
        return 'ALTER TABLE ' . $this->wrap($table->getTableName());
    }

    protected function handleDropPrimaryKey(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' DROP CONSTRAINT ' . $this->wrap($command->name);
    }

    protected function handleDropUniqueKey(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' DROP CONSTRAINT ' . $this->wrap($command->name);
    }

    protected function handleDropIndex(AlterTable $table, AlterCommand $command): string
    {
        return 'DROP INDEX ' . $this->wrap($table->getTableName()) . '.' . $this->wrap($command->name);
    }

    protected function handleDropForeignKey(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' DROP CONSTRAINT ' . $this->wrap($command->name);
    }

    protected function handleDropColumn(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' DROP COLUMN ' . $this->wrap($command->name);
    }

    /**
     * Supported by PostgreSQL, SQLite 3.25+, MySQL 8 and MariaDB 10.5.2+.
     */
    protected function handleRenameColumn(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' RENAME COLUMN ' . $this->wrap($command->name)
            . ' TO ' . $this->wrap($command->column()->getName());
    }

    protected function handleModifyColumn(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' MODIFY COLUMN ' . $this->handleColumns([$command->column()]);
    }

    protected function handleAddColumn(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' ADD COLUMN ' . $this->handleColumns([$command->column()]);
    }

    protected function handleAddPrimary(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' ADD CONSTRAINT ' . $this->wrap($command->name)
            . ' PRIMARY KEY (' . $this->wrapArray($command->columns) . ')';
    }

    protected function handleAddUnique(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' ADD CONSTRAINT ' . $this->wrap($command->name)
            . ' UNIQUE (' . $this->wrapArray($command->columns) . ')';
    }

    protected function handleAddIndex(AlterTable $table, AlterCommand $command): string
    {
        return 'CREATE INDEX ' . $this->wrap($command->name) . ' ON ' . $this->wrap($table->getTableName())
            . ' (' . $this->wrapArray($command->columns) . ')';
    }

    protected function handleAddForeign(AlterTable $table, AlterCommand $command): string
    {
        $key = $command->foreign();

        return $this->alterTable($table) . ' ADD CONSTRAINT ' . $this->wrap($command->name)
            . ' FOREIGN KEY (' . $this->wrapArray($key->getColumns()) . ') '
            . 'REFERENCES ' . $this->wrap($key->getReferencedTable())
            . '(' . $this->wrapArray($key->getReferencedColumns()) . ')';
    }

    protected function handleSetDefaultValue(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' ALTER COLUMN ' . $this->wrap($command->name)
            . ' SET DEFAULT ' . $this->value($command->value);
    }

    protected function handleDropDefaultValue(AlterTable $table, AlterCommand $command): string
    {
        return $this->alterTable($table) . ' ALTER COLUMN ' . $this->wrap($command->name) . ' DROP DEFAULT';
    }
}
