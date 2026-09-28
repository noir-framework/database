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

namespace Noirapi\Database;

use Noirapi\Database\Schema\AlterTable;
use Noirapi\Database\Schema\CreateTable;
use PDOException;
use RuntimeException;

use function array_keys;
use function is_array;
use function is_scalar;
use function is_string;
use function strtolower;

/**
 * Schema inspection and DDL: `$schema->create('users', fn (CreateTable $t) => $t->integer('id'))`.
 *
 * @psalm-type ColumnInfo = array{name: string, type: string}
 */
class Schema
{
    /** @var array<string, string>|null lower-case name => actual name */
    protected ?array $tableList = null;

    protected ?string $currentDatabase = null;

    /** @var array<string, array<string, ColumnInfo>> */
    protected array $columns = [];

    public function __construct(protected Connection $connection)
    {
    }

    /**
     * @throws PDOException
     * @throws RuntimeException When the driver has no schema compiler
     */
    public function getCurrentDatabase(): string
    {
        if ($this->currentDatabase === null) {
            $result = $this->connection->schemaCompiler()->currentDatabase((string) $this->connection->getDSN());
            $this->currentDatabase = is_string($result)
                ? $result
                : self::toString($this->connection->column($result['sql'], $result['params']));
        }

        return $this->currentDatabase;
    }

    /**
     * @throws PDOException
     */
    public function hasTable(string $table, bool $clear = false): bool
    {
        return isset($this->getTables($clear)[strtolower($table)]);
    }

    /**
     * @return array<string, string> lower-case name => actual name
     *
     * @throws PDOException
     */
    public function getTables(bool $clear = false): array
    {
        if ($clear) {
            $this->tableList = null;
        }

        if ($this->tableList === null) {
            $sql = $this->connection->schemaCompiler()->getTables($this->getCurrentDatabase());
            $rows = $this->connection->query($sql['sql'], $sql['params'])->fetchNum()->all();

            $this->tableList = [];
            foreach ($rows as $row) {
                if (isset($row[0]) && is_string($row[0])) {
                    $this->tableList[strtolower($row[0])] = $row[0];
                }
            }
        }

        return $this->tableList;
    }

    /**
     * @return ($names is true ? list<string> : array<string, ColumnInfo>)|false false when the table does not exist
     *
     * @throws PDOException
     */
    public function getColumns(string $table, bool $clear = false, bool $names = true): array|false
    {
        if ($clear) {
            unset($this->columns[$table]);
        }

        if (!$this->hasTable($table, $clear)) {
            return false;
        }

        if (!isset($this->columns[$table])) {
            $sql = $this->connection->schemaCompiler()->getColumns($this->getCurrentDatabase(), $table);
            $rows = $this->connection->query($sql['sql'], $sql['params'])->fetchAssoc()->all();

            $columns = [];
            foreach ($rows as $row) {
                if (isset($row['name'], $row['type']) && is_string($row['name']) && is_string($row['type'])) {
                    $columns[$row['name']] = ['name' => $row['name'], 'type' => $row['type']];
                }
            }

            $this->columns[$table] = $columns;
        }

        return $names ? array_keys($this->columns[$table]) : $this->columns[$table];
    }

    /**
     * @param callable(CreateTable): mixed $callback
     *
     * @throws PDOException
     */
    public function create(string $table, callable $callback): void
    {
        $schema = new CreateTable($table);
        $callback($schema);

        foreach ($this->connection->schemaCompiler()->create($schema) as $result) {
            $this->connection->command($result['sql'], $result['params']);
        }

        $this->tableList = null;
    }

    /**
     * @param callable(AlterTable): mixed $callback
     *
     * @throws PDOException
     */
    public function alter(string $table, callable $callback): void
    {
        $schema = new AlterTable($table);
        $callback($schema);

        unset($this->columns[strtolower($table)]);

        foreach ($this->connection->schemaCompiler()->alter($schema) as $result) {
            $this->connection->command($result['sql'], $result['params']);
        }
    }

    /**
     * @throws PDOException
     */
    public function renameTable(string $table, string $name): void
    {
        $result = $this->connection->schemaCompiler()->renameTable($table, $name);
        $this->connection->command($result['sql'], $result['params']);

        $this->tableList = null;
        unset($this->columns[strtolower($table)]);
    }

    /**
     * @throws PDOException
     */
    public function drop(string $table): void
    {
        $result = $this->connection->schemaCompiler()->drop($table);
        $this->connection->command($result['sql'], $result['params']);

        $this->tableList = null;
        unset($this->columns[strtolower($table)]);
    }

    /**
     * @throws PDOException
     */
    public function truncate(string $table): void
    {
        $result = $this->connection->schemaCompiler()->truncate($table);
        $this->connection->command($result['sql'], $result['params']);
    }

    private static function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
