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
use Noirapi\Database\SQL\Interval;
use Noirapi\Database\Test\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function stringFunctions(): iterable
    {
        yield 'generic' => ['', 'SELECT UCASE("a"), LCASE("b"), MID("c", 2, 3), LEN("d") FROM "t"'];
        yield 'mysql' => ['mysql', 'SELECT UCASE(`a`), LCASE(`b`), MID(`c`, 2, 3), LENGTH(`d`) FROM `t`'];
        yield 'pgsql' => ['pgsql', 'SELECT UPPER("a"), LOWER("b"), SUBSTR("c", 2, 3), LENGTH("d") FROM "t"'];
        yield 'sqlite' => ['sqlite', 'SELECT UPPER("a"), LOWER("b"), SUBSTR("c", 2, 3), LENGTH("d") FROM "t"'];
        yield 'sqlsrv' => ['sqlsrv', 'SELECT UPPER([a]), LOWER([b]), SUBSTRING([c], 2, 3), LEN([d]) FROM [t]'];
    }

    #[DataProvider('stringFunctions')]
    public function testStringFunctions(string $driver, string $expected): void
    {
        $this->assertSame($expected, $this->compile($driver, fn (Database $db) => $db->from('t')->select([
            fn (Expression $e) => $e->ucase('a'),
            fn (Expression $e) => $e->lcase('b'),
            fn (Expression $e) => $e->mid('c', 2, 3),
            fn (Expression $e) => $e->len('d'),
        ])));
    }

    public function testSqlServerMidWithoutLength(): void
    {
        $this->assertSame(
            'SELECT SUBSTRING([c], 2, LEN([c])) FROM [t]',
            $this->compile('sqlsrv', fn (Database $db) => $db->from('t')->select(fn (\Noirapi\Database\SQL\ColumnExpression $c) => $c->column(fn (Expression $e) => $e->mid('c', 2)))),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dates(): iterable
    {
        yield 'generic' => ['', 'SELECT * FROM "orders" WHERE "added_on" <= DATE_SUB(NOW(), INTERVAL 4 DAY)'
            . ' AND "due" > DATE_ADD("added_on", INTERVAL ("days") WEEK) AND "day" = CURRENT_DATE'];
        yield 'mysql' => ['mysql', 'SELECT * FROM `orders` WHERE `added_on` <= DATE_SUB(NOW(), INTERVAL 4 DAY)'
            . ' AND `due` > DATE_ADD(`added_on`, INTERVAL (`days`) WEEK) AND `day` = CURRENT_DATE'];
        yield 'pgsql' => ['pgsql', 'SELECT * FROM "orders" WHERE "added_on" <= (NOW() - make_interval(days => 4))'
            . ' AND "due" > ("added_on" + make_interval(weeks => ("days"))) AND "day" = CURRENT_DATE'];
        yield 'sqlite' => ['sqlite', 'SELECT * FROM "orders" WHERE "added_on" <= datetime(datetime(\'now\'), \'-4 days\')'
            . ' AND "due" > datetime("added_on", ((("days") * 7) || \' days\')) AND "day" = CURRENT_DATE'];
        yield 'sqlsrv' => ['sqlsrv', 'SELECT * FROM [orders] WHERE [added_on] <= DATEADD(day, -4, GETDATE())'
            . ' AND [due] > DATEADD(week, ([days]), [added_on]) AND [day] = CAST(GETDATE() AS DATE)'];
    }

    #[DataProvider('dates')]
    public function testDateArithmetic(string $driver, string $expected): void
    {
        $this->assertSame($expected, $this->compile($driver, fn (Database $db) => $db->from('orders')
            ->where('added_on')->atMost(fn (Expression $e) => $e->ago(4, Interval::Day))
            ->andWhere('due')->gt(fn (Expression $e) => $e->dateAdd('added_on', Expression::fromColumn('days'), Interval::Week))
            ->andWhere('day')->is(fn (Expression $e) => $e->currentDate())
            ->select()));
    }

    public function testDateUnits(): void
    {
        $units = [];
        foreach (Interval::cases() as $unit) {
            $units[] = $this->compile('mysql', fn (Database $db) => $db->from('t')->select(fn (\Noirapi\Database\SQL\ColumnExpression $c) => $c->column(fn (Expression $e) => $e->fromNow(1, $unit))));
        }
        $this->assertSame([
            'SELECT DATE_ADD(NOW(), INTERVAL 1 SECOND) FROM `t`',
            'SELECT DATE_ADD(NOW(), INTERVAL 1 MINUTE) FROM `t`',
            'SELECT DATE_ADD(NOW(), INTERVAL 1 HOUR) FROM `t`',
            'SELECT DATE_ADD(NOW(), INTERVAL 1 DAY) FROM `t`',
            'SELECT DATE_ADD(NOW(), INTERVAL 1 WEEK) FROM `t`',
            'SELECT DATE_ADD(NOW(), INTERVAL 1 MONTH) FROM `t`',
            'SELECT DATE_ADD(NOW(), INTERVAL 1 YEAR) FROM `t`',
        ], $units);
    }

    public function testInet(): void
    {
        $this->assertSame(
            'SELECT INET6_NTOA(`ip`) FROM `hosts` WHERE `ip` = INET6_ATON(\'::1\') OR `ip4` = INET_ATON(`other`)',
            $this->compile('mysql', fn (Database $db) => $db->from('hosts')
                ->where('ip')->is(fn (Expression $e) => $e->inet6Aton('::1'))
                ->orWhere('ip4')->is(fn (Expression $e) => $e->inetAton(Expression::fromColumn('other')))
                ->select(fn (\Noirapi\Database\SQL\ColumnExpression $c) => $c->column(fn (Expression $e) => $e->inet6Ntoa('ip')))),
        );
    }

    public function testInetUnsupportedOnOtherDialects(): void
    {
        foreach (['pgsql', 'sqlite', 'sqlsrv'] as $driver) {
            try {
                $this->compile($driver, fn (Database $db) => $db->from('h')->where('ip')->is(fn (Expression $e) => $e->inet6Aton('::1'))->select());
                $this->fail('INET6_ATON must be rejected on ' . $driver);
            } catch (LogicException $e) {
                $this->assertStringContainsString('INET6_ATON() is not supported', $e->getMessage());
            }
        }
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
