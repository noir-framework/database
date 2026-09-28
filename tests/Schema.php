<?php
/* ===========================================================================
 * Copyright 2018 Zindex Software
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

namespace Noirapi\Database\Test;

use Noirapi\Database\Connection;
use Noirapi\Database\Schema\AlterTable;
use Noirapi\Database\Schema\CreateTable;

/**
 * Compiles schema operations to SQL strings instead of executing them.
 */
class Schema
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(string $table, callable $callback): string
    {
        $schema = new CreateTable($table);
        $callback($schema);

        return $this->join($this->connection->schemaCompiler()->create($schema));
    }

    public function alter(string $table, callable $callback): string
    {
        $schema = new AlterTable($table);
        $callback($schema);

        return $this->join($this->connection->schemaCompiler()->alter($schema));
    }

    public function renameTable(string $table, string $name): string
    {
        return $this->connection->schemaCompiler()->renameTable($table, $name)['sql'];
    }

    public function drop(string $table): string
    {
        return $this->connection->schemaCompiler()->drop($table)['sql'];
    }

    public function truncate(string $table): string
    {
        return $this->connection->schemaCompiler()->truncate($table)['sql'];
    }

    /**
     * @param list<array{sql: string, params: list<mixed>}> $commands
     */
    private function join(array $commands): string
    {
        return implode("\n", array_map(static fn (array $command): string => $command['sql'], $commands));
    }
}
