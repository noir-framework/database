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

use InvalidArgumentException;
use LogicException;
use Noirapi\Database\SQL\Expression;

class InsertTest extends BaseClass
{
    public function testInsertSingleValue(): void
    {
        $expected = 'INSERT INTO "users" ("age") VALUES (18)';
        $actual = $this->sql(fn () => $this->db->insert(['age' => 18])->into('users'));
        $this->assertEquals($expected, $actual);
    }

    public function testInsertMultipleValues(): void
    {
        $expected = 'INSERT INTO "users" ("name", "age") VALUES (\'foo\', 18)';
        $actual = $this->sql(fn () => $this->db->insert(['name' => 'foo', 'age' => 18])->into('users'));
        $this->assertEquals($expected, $actual);
    }

    public function testInsertBooleanValues(): void
    {
        $expected = 'INSERT INTO "test" ("foo", "bar") VALUES (TRUE, FALSE)';
        $actual = $this->sql(fn () => $this->db->insert(['foo' => true, 'bar' => false])->into('test'));
        $this->assertEquals($expected, $actual);
    }

    public function testInsertExpressions(): void
    {
        $expected = 'INSERT INTO "users" ("name") VALUES (LCASE( \'foo\' ))';
        $actual = $this->sql(fn () => $this->db->insert([
            'name' => function (Expression $expr): void {
                $expr->{'LCASE('}->value('foo')->{')'};
            },
        ])->into('users'));
        $this->assertEquals($expected, $actual);
    }

    public function testInsertMany(): void
    {
        $expected = 'INSERT INTO "users" ("name", "age") VALUES (\'foo\', 18), (\'bar\', 20), (\'baz\', NULL)';
        $actual = $this->sql(fn () => $this->db->insertMany([
            ['name' => 'foo', 'age' => 18],
            ['age' => 20, 'name' => 'bar'],
        ])->insertMany([['name' => 'baz', 'age' => null]])->into('users'));
        $this->assertEquals($expected, $actual);
    }

    public function testInsertManyFollowsInsertColumns(): void
    {
        $expected = 'INSERT INTO "users" ("name", "age") VALUES (\'foo\', 18), (\'bar\', 20 + 1)';
        $actual = $this->sql(fn () => $this->db->insert(['name' => 'foo', 'age' => 18])
            ->insertMany([['age' => fn (Expression $e) => $e->value(20)->op('+')->value(1), 'name' => 'bar']])
            ->into('users'));
        $this->assertEquals($expected, $actual);
    }

    public function testInsertManyRejectsMismatchedRows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('row 1 must have exactly the columns: name, age');
        $this->db->insertMany([['name' => 'foo', 'age' => 18], ['name' => 'bar']]);
    }

    public function testInsertManyRejectsExtraColumns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->insertMany([['name' => 'foo'], ['name' => 'bar', 'email' => 'x']]);
    }

    public function testInsertManyRejectsListRows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->insertMany([['foo', 18]]); // @phpstan-ignore argument.type
    }

    public function testInsertAfterInsertManyThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->db->insertMany([['name' => 'foo']])->insert(['age' => 1]);
    }

    public function testInsertResourceIsLogged(): void
    {
        $stream = fopen('php://memory', 'rb');
        $this->assertIsResource($stream);
        $actual = $this->sql(fn () => $this->db->insert(['data' => $stream])->into('files'));
        $this->assertEquals('INSERT INTO "files" ("data") VALUES (\'RESOURCE#' . get_resource_id($stream) . '\')', $actual);
        fclose($stream);
    }
}
