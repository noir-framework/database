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
use Noirapi\Database\SQL\Expression;
use Noirapi\Database\SQL\SelectStatement;
use Noirapi\Database\SQL\Subquery;
use PDOException;
use RuntimeException;

use function array_keys;
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

    /** @var array<string, string>|null lower-case name => actual name */
    protected ?array $viewList = null;

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
            $this->tableList = $this->fetchNames($sql['sql'], $sql['params']);
        }

        return $this->tableList;
    }

    /**
     * @throws PDOException
     */
    public function hasView(string $view, bool $clear = false): bool
    {
        return isset($this->getViews($clear)[strtolower($view)]);
    }

    /**
     * @return array<string, string> lower-case name => actual name
     *
     * @throws PDOException
     */
    public function getViews(bool $clear = false): array
    {
        if ($clear) {
            $this->viewList = null;
        }

        if ($this->viewList === null) {
            $sql = $this->connection->schemaCompiler()->getViews($this->getCurrentDatabase());
            $this->viewList = $this->fetchNames($sql['sql'], $sql['params']);
        }

        return $this->viewList;
    }

    /**
     * Creates a view from a query built in the callback:
     * `$schema->createView('adults', 'users', fn (SelectStatement $q) => $q->where('age')->gte(18)->select())`.
     *
     * Values are inlined as literals, since views cannot take bound parameters.
     *
     * @param string|array<int|string, string|Expression> $table
     * @param callable(SelectStatement): mixed $callback
     *
     * @throws PDOException
     * @throws RuntimeException When the driver cannot quote literals
     */
    public function createView(string $view, string|array $table, callable $callback): void
    {
        $select = (new Subquery())->from($table);
        $callback($select);

        $pdo = $this->connection->getPDO();
        $quote = static function (string $value) use ($pdo): string {
            $quoted = $pdo->quote($value);

            return $quoted !== false ? $quoted : throw new RuntimeException('The PDO driver cannot quote values');
        };
        $sql = $this->connection->getCompiler()->selectInline($select->getSQLStatement(), $quote);

        $result = $this->connection->schemaCompiler()->createView($view, $sql);
        $this->connection->command($result['sql'], $result['params']);

        $this->viewList = null;
    }

    /**
     * @throws PDOException
     */
    public function dropView(string $view): void
    {
        $result = $this->connection->schemaCompiler()->dropView($view);
        $this->connection->command($result['sql'], $result['params']);

        $this->viewList = null;
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

    /**
     * @param list<mixed> $params
     *
     * @return array<string, string> lower-case name => actual name, from the first column
     *
     * @throws PDOException
     */
    private function fetchNames(string $sql, array $params): array
    {
        $names = [];
        foreach ($this->connection->query($sql, $params)->fetchNum()->all() as $row) {
            if (isset($row[0]) && is_string($row[0])) {
                $names[strtolower($row[0])] = $row[0];
            }
        }

        return $names;
    }

    private static function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
