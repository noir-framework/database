<?php

declare(strict_types=1);

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

namespace Noirapi\Database\Test\SQL;

use Noirapi\Database\Database;
use Noirapi\Database\SQL\Expression;
use Noirapi\Database\Test\Connection;
use PHPUnit\Framework\TestCase;

class BitsTest extends TestCase
{
    public function testWhereBits(): void
    {
        $this->assertSame(
            'SELECT * FROM "entries" WHERE (~"flags" & 5) = 0 AND ("flags" & 2) != 0 OR ("features" & 8) = 0',
            $this->compile('', fn (Database $db) => $db->from('entries')
                ->where('flags')->hasAllBits(5)
                ->andWhere('flags')->hasAnyBits(2)
                ->orWhere('features')->hasNoBits(8)
                ->select()),
        );
        $this->assertSame(
            'SELECT * FROM [entries] WHERE (~[flags] & -9223372036854775808) = 0',
            $this->compile('sqlsrv', fn (Database $db) => $db->from('entries')->where('flags')->hasAllBits(PHP_INT_MIN)->select()),
        );
    }

    public function testUpdateBits(): void
    {
        $this->assertSame(
            'UPDATE "orders" SET "flags" = "flags" | 4 WHERE "id" = 1',
            $this->compile('', fn (Database $db) => $db->update('orders')->where('id')->is(1)->setBits('flags', 4)),
        );
        $this->assertSame(
            'UPDATE "orders" SET "flags" = ("flags" & ~4) WHERE "id" = 1',
            $this->compile('', fn (Database $db) => $db->update('orders')->where('id')->is(1)->clearBits('flags', 4)),
        );
        $this->assertSame(
            'UPDATE `orders` SET `flags` = CAST((`flags` & ~3) | 8 AS SIGNED)',
            $this->compile('mysql', fn (Database $db) => $db->update('orders')->set([
                'flags' => fn (Expression $e) => $e->bits('flags', set: 8, clear: 3, signed: true),
            ])),
        );
        $this->assertSame(
            'UPDATE "orders" SET "flags" = ("flags" & ~3) | 8',
            $this->compile('pgsql', fn (Database $db) => $db->update('orders')->set([
                'flags' => fn (Expression $e) => $e->bits('flags', set: 8, clear: 3, signed: true),
            ])),
        );
    }

    /**
     * @param \Closure(Database): mixed $query
     */
    private function compile(string $driver, \Closure $query): string
    {
        $connection = new Connection($driver);
        $query(new Database($connection));

        return $connection->lastSql();
    }
}
