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

use Noirapi\Database\ResultSet;
use PDO;

class Connection extends \Noirapi\Database\Connection
{
    private string $lastSql = '';

    public function __construct(string $driver)
    {
        parent::__construct('', driver: $driver);
    }

    public function query(string $sql, array $params = []): ResultSet
    {
        $this->record($sql, $params);

        return new ResultSet((new PDO('sqlite::memory:'))->query('SELECT 1'));
    }

    public function column(string $sql, array $params = []): mixed
    {
        $this->record($sql, $params);

        return null;
    }

    public function count(string $sql, array $params = []): int
    {
        $this->record($sql, $params);

        return 0;
    }

    public function command(string $sql, array $params = []): bool
    {
        $this->record($sql, $params);

        return true;
    }

    public function lastSql(): string
    {
        return $this->lastSql;
    }

    private function record(string $sql, array $params): void
    {
        $this->lastSql = $this->replaceParams($sql, $params);
    }
}
