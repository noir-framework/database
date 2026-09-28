<?php

declare(strict_types=1);

/* ===========================================================================
 * Copyright 2019 Zindex Software
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
use Noirapi\Database\SQL\HavingExpression;
use Noirapi\Database\SQL\HavingStatement;
use Noirapi\Database\SQL\Join;

class HavingTest extends BaseClass
{
    public function testColumn(): void
    {
        $expected = 'SELECT * FROM "users" GROUP BY "age" HAVING COUNT("friends") > 5';
        $actual = $this->sql(fn () => $this->db->from('users')
            ->groupBy('age')
            ->having('friends', function (HavingExpression $column): void {
                $column->count()->gt(5);
            })
            ->select());
        $this->assertEquals($expected, $actual);
    }

    public function testExpression(): void
    {
        $expected = 'SELECT * FROM "users" GROUP BY "age" HAVING COUNT("friends" * 2) > 5';
        $actual = $this->sql(fn () => $this->db->from('users')
            ->groupBy('age')
            ->having(function (Expression $expr): void {
                $expr->column('friends')->{'*'}->value(2);
            }, function (HavingExpression $column): void {
                $column->count()->gt(5);
            })
            ->select());
        $this->assertEquals($expected, $actual);
    }

    public function testNested(): void
    {
        $expected = 'SELECT COUNT("orders"."id") AS "total_orders", "customers"."name" AS "name" FROM "customers" LEFT JOIN "orders" ON "customers"."id" = "orders"."cid" GROUP BY LCASE("customers"."name") HAVING COUNT("orders"."id") > 10 AND (SUM("orders"."value") >= 1000 OR MIN(ROUND("orders"."value", 2)) >= 500)';
        $actual = $this->sql(fn () => $this->db->from('customers')
            ->leftJoin('orders', function (Join $join): void {
                $join->on('customers.id', 'orders.cid');
            })
            ->groupBy(function (Expression $expr): void {
                $expr->lcase('customers.name');
            })
            ->having('orders.id', function (HavingExpression $column): void {
                $column->count()->gt(10);
            })
            ->andHaving(function (HavingStatement $group): void {
                $group->having('orders.value', function (HavingExpression $column): void {
                    $column->sum()->gte(1000);
                })
                    ->orHaving(function (Expression $expr): void {
                        $expr->round('orders.value', 2);
                    }, function (HavingExpression $column): void {
                        $column->min()->gte(500);
                    });
            })
            ->select(function (ColumnExpression $include): void {
                $include->count('orders.id', 'total_orders')
                    ->column('customers.name', 'name');
            }));
        $this->assertEquals($expected, $actual);
    }
}
