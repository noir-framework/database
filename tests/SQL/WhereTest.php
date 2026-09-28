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

use Noirapi\Database\SQL\Expression;

class WhereTest extends BaseClass
{
    public function testWhereIs(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" = 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->is(21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereIsNot(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" != 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->isNot(21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereLT(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" < 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->lessThan(21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereLTAlt(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" < 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->lt(21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereGT(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" > 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->greaterThan(21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereGTAlt(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" > 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->gt(21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereLTE(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" <= 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->atMost(21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereLTEAlt(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" <= 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->lte(21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereGTE(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" >= 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->atLeast(21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereGTEAlt(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" >= 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->gte(21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testBetween(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" BETWEEN 18 AND 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->between(18, 21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testNotBetween(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" NOT BETWEEN 18 AND 21';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->notBetween(18, 21)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereInArray(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" IN (18, 21, 31)';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->in([18, 21, 31])->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereNotInArray(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" NOT IN (18, 21, 31)';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->notIn([18, 21, 31])->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereInQuery(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" IN (SELECT "name" FROM "customers")';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->in(function ($query): void {
            $query->from('customers')->select('name');
        })->select());
        $this->assertEquals($expected, $actual);
    }


    public function testWhereNotInQuery(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" NOT IN (SELECT "name" FROM "customers")';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->notIn(function ($query): void {
            $query->from('customers')->select('name');
        })->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereLike(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "name" LIKE \'%foo%\'';
        $actual = $this->sql(fn () => $this->db->from('users')->where('name')->like('%foo%')->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereNotLike(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "name" NOT LIKE \'%foo%\'';
        $actual = $this->sql(fn () => $this->db->from('users')->where('name')->notLike('%foo%')->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereIsNull(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "name" IS NULL';
        $actual = $this->sql(fn () => $this->db->from('users')->where('name')->isNull()->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereIsNotNull(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "name" IS NOT NULL';
        $actual = $this->sql(fn () => $this->db->from('users')->where('name')->notNull()->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereAndCondition(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" = 18 AND "city" = \'London\'';
        $actual = $this->sql(fn () => $this->db->from('users')
            ->where('age')->is(18)
            ->andWhere('city')->is('London')
            ->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereOrCondition(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" = 18 OR "city" = \'London\'';
        $actual = $this->sql(fn () => $this->db->from('users')
            ->where('age')->is(18)
            ->orWhere('city')->is('London')
            ->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereGroupCondition(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" = 18 AND ("city" = \'London\' OR "city" = \'Paris\')';
        $actual = $this->sql(fn () => $this->db->from('users')
            ->where('age')->is(18)
            ->andWhere(function ($group): void {
                $group->where('city')->is('London')
                    ->orWhere('city')->is('Paris');
            })
            ->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereIsColumn(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" = "foo"';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->is('foo', true)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereIsNotColumn(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" != "foo"';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->isNot('foo', true)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereLTColumn(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" < "foo"';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->lessThan('foo', true)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereLTAltColumn(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" < "foo"';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->lt('foo', true)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereGTColumn(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" > "foo"';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->greaterThan('foo', true)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereGTAltColumn(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" > "foo"';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->gt('foo', true)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereLTEColumn(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" <= "foo"';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->atMost('foo', true)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereLTEAltColumn(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" <= "foo"';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->lte('foo', true)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereGTEColumn(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" >= "foo"';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->atLeast('foo', true)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereGTEAltColumn(): void
    {
        $expected = 'SELECT * FROM "users" WHERE "age" >= "foo"';
        $actual = $this->sql(fn () => $this->db->from('users')->where('age')->gte('foo', true)->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereExists(): void
    {
        $expected = 'SELECT * FROM "users" WHERE EXISTS (SELECT * FROM "orders" WHERE "orders"."name" = "users"."name")';
        $actual = $this->sql(fn () => $this->db->from('users')
            ->whereExists(function ($query): void {
                $query->from('orders')
                    ->where('orders.name')->eq('users.name', true)
                    ->select();
            })
            ->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereExpression1(): void
    {
        $expected = 'SELECT * FROM "numbers" WHERE "c" = "b" + 10';
        $actual = $this->sql(fn () => $this->db->from('numbers')
            ->where('c')->eq(function ($expr): void {
                $expr->column('b')->op('+')->value(10);
            })
            ->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereExpression2(): void
    {
        $expected = 'SELECT * FROM "numbers" WHERE "c" = "a" + "b"';
        $actual = $this->sql(fn () => $this->db->from('numbers')
            ->where('c')->eq(function ($expr): void {
                $expr->column('a')->{'+'}->column('b');
            })
            ->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereExpression3(): void
    {
        $expected = 'SELECT * FROM "names" WHERE LCASE("name") LIKE \'%test%\'';
        $actual = $this->sql(fn () => $this->db->from('names')
            ->where(function (Expression $expr): void {
                $expr->lcase('name');
            }, true)
            ->like('%test%')
            ->select());
        $this->assertEquals($expected, $actual);
    }

    public function testWhereNop(): void
    {
        $expected = 'SELECT * FROM "users" WHERE match( "username" ) against( \'expression\' )';
        $actual = $this->sql(fn () => $this->db->from('users')
            ->where(function (Expression $expr): void {
                $expr->op('match(')->column('username')->op(') against(')
                    ->value('expression')->op(')');
            }, true)->nop()
            ->select());
        $this->assertEquals($expected, $actual);
    }
}
