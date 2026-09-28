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

use Noirapi\Database\SQL\ColumnExpression;
use Noirapi\Database\SQL\Expression;

class SelectTest extends BaseClass
{
    public function testSelect(): void
    {
        $expected = 'SELECT * FROM "users"';
        $actual = $this->sql(fn () => $this->db->from('users')->select());
        $this->assertEquals($expected, $actual);
    }

    public function testSelectDistinct(): void
    {
        $expected = 'SELECT DISTINCT * FROM "users"';
        $actual = $this->sql(fn () => $this->db->from('users')->distinct()->select());
        $this->assertEquals($expected, $actual);
    }

    public function testSelectSingleColumn(): void
    {
        $expected = 'SELECT "name" FROM "users"';
        $actual = $this->sql(fn () => $this->db->from('users')->select('name'));
        $this->assertEquals($expected, $actual);
    }

    public function testSelectSingleColumnArray(): void
    {
        $expected = 'SELECT "name" FROM "users"';
        $actual = $this->sql(fn () => $this->db->from('users')->select(['name']));
        $this->assertEquals($expected, $actual);
    }

    public function testSelectMultipleColumns(): void
    {
        $expected = 'SELECT "name", "age" FROM "users"';
        $actual = $this->sql(fn () => $this->db->from('users')->select(['name', 'age']));
        $this->assertEquals($expected, $actual);
    }

    public function testSelectColumnsAliases(): void
    {
        $expected = 'SELECT "name" AS "n", "age" AS "a" FROM "users"';
        $actual = $this->sql(fn () => $this->db->from('users')->select(['name' => 'n', 'age' => 'a']));
        $this->assertEquals($expected, $actual);
    }

    public function testSelectColumnsFirstAliased(): void
    {
        $expected = 'SELECT "name" AS "n", "age" FROM "users"';
        $actual = $this->sql(fn () => $this->db->from('users')->select(['name' => 'n', 'age']));
        $this->assertEquals($expected, $actual);
    }

    public function testSelectColumnsLastAliased(): void
    {
        $expected = 'SELECT "name", "age" AS "a" FROM "users"';
        $actual = $this->sql(fn () => $this->db->from('users')->select(['name', 'age' => 'a']));
        $this->assertEquals($expected, $actual);
    }

    public function testSelectFromMultipleTables(): void
    {
        $expected = 'SELECT * FROM "users", "sites"';
        $actual = $this->sql(fn () => $this->db->from(['users', 'sites'])->select());
        $this->assertEquals($expected, $actual);
    }

    public function testSelectFromMultipleTablesAliased(): void
    {
        $expected = 'SELECT * FROM "users" AS "u", "sites" AS "s"';
        $actual = $this->sql(fn () => $this->db->from(['users' => 'u', 'sites' => 's'])->select());
        $this->assertEquals($expected, $actual);
    }

    public function testSelectColumnsFromMultipleTablesAliased(): void
    {
        $expected = 'SELECT "u"."name", "s"."address" FROM "users" AS "u", "sites" AS "s"';
        $actual = $this->sql(fn () => $this->db->from(['users' => 'u', 'sites' => 's'])->select(['u.name', 's.address']));
        $this->assertEquals($expected, $actual);
    }

    public function testSelectAliasedColumnsFromMultipleTablesAliased(): void
    {
        $expected = 'SELECT "u"."name" AS "n", "s"."address" AS "s" FROM "users" AS "u", "sites" AS "s"';
        $actual = $this->sql(fn () => $this->db->from(['users' => 'u', 'sites' => 's'])->select(['u.name' => 'n', 's.address' => 's']));
        $this->assertEquals($expected, $actual);
    }

    public function testSelectAliasedSingleExpression(): void
    {
        $expected = 'SELECT LCASE("name") AS "lower_name" FROM "users"';
        $actual = $this->sql(fn () => $this->db->from('users')
            ->select(function (ColumnExpression $expr): void {
                $expr->lcase('name', 'lower_name');
            }));
        $this->assertEquals($expected, $actual);
    }

    public function testSelectAliasedExpressionMultiple(): void
    {
        $expected = 'SELECT "name", LEN("name") AS "name_length", "age" AS "alias_age" FROM "users"';
        $actual = $this->sql(fn () => $this->db->from('users')
            ->select([
                'name',
                'name_length' => function (Expression $expr): void {
                    $expr->len('name');
                },
                'age' => 'alias_age',
            ]));
        $this->assertEquals($expected, $actual);
    }
}
