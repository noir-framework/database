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

namespace Noirapi\Database\SQL;

use Noirapi\Database\Connection;
use Override;
use PDOException;
use Throwable;

use function array_chunk;
use function count;
use function intdiv;
use function max;

/**
 * INSERT bound to a connection: `$db->insert([...])->into('table')` runs it.
 */
class Insert extends InsertStatement
{
    public function __construct(protected Connection $connection, ?SQLStatement $statement = null)
    {
        parent::__construct($statement);
    }

    /**
     * Runs the insert. A multi-row insert with more bound values than the database accepts in
     * one statement (see Compiler::getMaxParams()) is split into several statements, run in
     * one transaction (or in the caller's open transaction).
     *
     * @throws PDOException
     */
    #[Override]
    public function into(string $table): bool
    {
        parent::into($table);
        $compiler = $this->connection->getCompiler();
        $sql = $compiler->insert($this->sql);
        $params = $compiler->getParams();

        $rows = $this->sql->getInsertRows();
        if (count($params) <= $compiler->getMaxParams() || count($rows) < 2) {
            return $this->connection->command($sql, $params);
        }

        $perRow = intdiv(count($params) + count($rows) - 1, count($rows));
        $chunks = array_chunk($rows, max(1, intdiv($compiler->getMaxParams(), $perRow)));

        return $this->runChunks($chunks);
    }

    /**
     * @param list<non-empty-list<list<mixed>>> $chunks
     *
     * @throws PDOException
     */
    private function runChunks(array $chunks): bool
    {
        $pdo = $this->connection->getPDO();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }

        try {
            $compiler = $this->connection->getCompiler();
            foreach ($chunks as $chunk) {
                $sql = $compiler->insert($this->sql->withInsertRows($chunk));
                $this->connection->command($sql, $compiler->getParams());
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($own) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        return true;
    }
}
