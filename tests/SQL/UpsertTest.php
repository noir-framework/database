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

use LogicException;
use Noirapi\Database\Database;
use Noirapi\Database\SQL\Expression;
use Noirapi\Database\Test\Connection;
use PHPUnit\Framework\TestCase;

class UpsertTest extends TestCase
{
    public function testGenericUpdatesAllButKeys(): void
    {
        $this->assertEquals(
            'INSERT INTO "users" ("id", "name", "age") VALUES (1, \'foo\', 18), (2, \'bar\', 20)'
            . ' ON CONFLICT ("id") DO UPDATE SET "name" = excluded."name", "age" = excluded."age"',
            $this->compile('', fn (Database $db) => $db->insertMany([
                ['id' => 1, 'name' => 'foo', 'age' => 18],
                ['id' => 2, 'name' => 'bar', 'age' => 20],
            ])->upsert('id')->into('users')),
        );
    }

    public function testGenericColumnsAndExplicitValues(): void
    {
        $this->assertEquals(
            'INSERT INTO "stats" ("day", "page", "hits") VALUES (\'2026-09-28\', \'/\', 1)'
            . ' ON CONFLICT ("day", "page") DO UPDATE SET "hits" = "hits" + 1, "seen" = \'yes\', "page" = excluded."page"',
            $this->compile('', fn (Database $db) => $db->insert(['day' => '2026-09-28', 'page' => '/', 'hits' => 1])
                ->upsert(['day', 'page'], [
                    'hits' => fn (Expression $e) => $e->column('hits')->op('+')->value(1),
                    'seen' => 'yes',
                    'page',
                ])
                ->into('stats')),
        );
    }

    public function testGenericDoNothing(): void
    {
        $this->assertEquals(
            'INSERT INTO "users" ("id") VALUES (1) ON CONFLICT DO NOTHING',
            $this->compile('', fn (Database $db) => $db->insert(['id' => 1])->upsert([], [])->into('users')),
        );
        $this->assertEquals(
            'INSERT INTO "users" ("id") VALUES (1) ON CONFLICT ("id") DO NOTHING',
            $this->compile('', fn (Database $db) => $db->insert(['id' => 1])->upsert('id')->into('users')),
        );
    }

    public function testGenericUpdateNeedsKeys(): void
    {
        $this->expectException(LogicException::class);
        $this->compile('', fn (Database $db) => $db->insert(['id' => 1, 'a' => 2])->upsert([])->into('users'));
    }

    public function testMySQL(): void
    {
        $this->assertEquals(
            'INSERT INTO `users` (`id`, `name`, `hits`) VALUES (1, \'foo\', 1), (2, \'bar\', 1)'
            . ' ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `hits` = `hits` + 1',
            $this->compile('mysql', fn (Database $db) => $db->insertMany([
                ['id' => 1, 'name' => 'foo', 'hits' => 1],
                ['id' => 2, 'name' => 'bar', 'hits' => 1],
            ])->upsert('id', ['name', 'hits' => fn (Expression $e) => $e->column('hits')->op('+')->value(1)])
                ->into('users')),
        );
    }

    public function testMySQLDoNothing(): void
    {
        $this->assertEquals(
            'INSERT INTO `users` (`id`, `name`) VALUES (1, \'foo\') ON DUPLICATE KEY UPDATE `id` = `id`',
            $this->compile('mysql', fn (Database $db) => $db->insert(['id' => 1, 'name' => 'foo'])
                ->upsert('id', [])->into('users')),
        );
        $this->assertEquals(
            'INSERT INTO `users` (`name`) VALUES (\'foo\') ON DUPLICATE KEY UPDATE `name` = `name`',
            $this->compile('mysql', fn (Database $db) => $db->insert(['name' => 'foo'])->upsert([], [])->into('users')),
        );
    }

    public function testSQLServerMerge(): void
    {
        $this->assertEquals(
            'MERGE INTO [users] WITH (HOLDLOCK) USING (VALUES (1, \'foo\', 18), (2, \'bar\', 20)) AS [excluded] ([id], [name], [age])'
            . ' ON [users].[id] = [excluded].[id]'
            . ' WHEN MATCHED THEN UPDATE SET [name] = [excluded].[name], [age] = 99'
            . ' WHEN NOT MATCHED THEN INSERT ([id], [name], [age]) VALUES ([excluded].[id], [excluded].[name], [excluded].[age]);',
            $this->compile('sqlsrv', fn (Database $db) => $db->insertMany([
                ['id' => 1, 'name' => 'foo', 'age' => 18],
                ['id' => 2, 'name' => 'bar', 'age' => 20],
            ])->upsert(['id'], ['name', 'age' => 99])->into('users')),
        );
    }

    public function testSQLServerDoNothing(): void
    {
        $this->assertEquals(
            'MERGE INTO [users] WITH (HOLDLOCK) USING (VALUES (1)) AS [excluded] ([id]) ON [users].[id] = [excluded].[id]'
            . ' WHEN NOT MATCHED THEN INSERT ([id]) VALUES ([excluded].[id]);',
            $this->compile('sqlsrv', fn (Database $db) => $db->insert(['id' => 1])->upsert('id')->into('users')),
        );
    }

    public function testSQLServerNeedsKeys(): void
    {
        $this->expectException(LogicException::class);
        $this->compile('sqlsrv', fn (Database $db) => $db->insert(['id' => 1])->upsert([], [])->into('users'));
    }

    public function testSQLServerPlainInsertUnchanged(): void
    {
        $this->assertEquals(
            'INSERT INTO [users] ([id]) VALUES (1)',
            $this->compile('sqlsrv', fn (Database $db) => $db->insert(['id' => 1])->into('users')),
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
