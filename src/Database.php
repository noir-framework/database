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

use Noirapi\Database\SQL\Expression;
use Noirapi\Database\SQL\Insert;
use Noirapi\Database\SQL\Query;
use Noirapi\Database\SQL\Update;
use PDOException;

/**
 * Entry point for queries: `$db->from('users')->where('id')->is(1)->select()`.
 */
class Database
{
    protected ?Schema $schema = null;

    public function __construct(protected Connection $connection)
    {
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @return list<array{query: string, time?: float}>
     */
    public function getLog(): array
    {
        return $this->connection->getLog();
    }

    /**
     * @param string|array<int|string, string|Expression> $tables a table, a list of tables, or table => alias
     */
    public function from(string|array $tables): Query
    {
        return new Query($this->connection, $tables);
    }

    /**
     * @param array<string, mixed> $values column => value
     */
    public function insert(array $values): Insert
    {
        return (new Insert($this->connection))->insert($values);
    }

    /**
     * @param string|array<int|string, string|Expression> $table
     */
    public function update(string|array $table): Update
    {
        return new Update($this->connection, $table);
    }

    public function schema(): Schema
    {
        return $this->schema ??= $this->connection->getSchema();
    }

    /**
     * Runs the callback (receiving this Database) inside a transaction.
     *
     * @template TResult
     * @template TDefault
     *
     * @param callable(Database): TResult $query
     * @param TDefault $default
     *
     * @return TResult|TDefault
     *
     * @throws PDOException
     */
    public function transaction(callable $query, mixed $default = null): mixed
    {
        /** @var callable(mixed): TResult $query */
        return $this->connection->transaction($query, $this, $default);
    }
}
