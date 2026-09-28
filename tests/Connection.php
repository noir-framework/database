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

namespace Opis\Database\Test;

class Connection extends \Opis\Database\Connection
{
    private string $lastSql = '';

    public function __construct(string $driver)
    {
        parent::__construct('');
        $this->driver = $driver;
    }

    public function query(string $sql, array $params = [])
    {
        return $this->record($sql, $params);
    }

    public function column(string $sql, array $params = [])
    {
        return $this->record($sql, $params);
    }

    public function count(string $sql, array $params = [])
    {
        return $this->record($sql, $params);
    }

    public function command(string $sql, array $params = [])
    {
        return $this->record($sql, $params);
    }

    public function lastSql(): string
    {
        return $this->lastSql;
    }

    private function record(string $sql, array $params): string
    {
        return $this->lastSql = $this->replaceParams($sql, $params);
    }
}
