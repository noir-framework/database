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
use Noirapi\Database\Database;
use Noirapi\Database\SQL\Expression;
use Noirapi\Database\SQL\JsonPath;
use Noirapi\Database\Test\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JsonTest extends TestCase
{
    public function testPathParsing(): void
    {
        $this->assertSame(['meta', ['address', 'city', 0, 2]], self::arrow('meta->address->city[0][2]'));
        $this->assertSame(['u.meta', ['a b-c']], self::arrow('u.meta->a b-c'));
        $this->assertNull(JsonPath::fromArrow('meta'));
        $this->assertSame(['a', 'b', 0], JsonPath::fromPath('$.a.b[0]')->segments);
        $this->assertSame(['a', 'b', 0], JsonPath::fromPath('a.b[0]')->segments);
        $this->assertSame(['a.b', 1], JsonPath::fromPath(['a.b', 1])->segments);
        $this->assertSame('$."a"."b"[0]', JsonPath::fromPath('a.b[0]')->dollar());
        $this->assertSame('{"a","b","0"}', JsonPath::fromPath('a.b[0]')->pgArray());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'quote' => ["meta->a'); DROP TABLE x; --"];
        yield 'double quote' => ['meta->a"b'];
        yield 'backslash' => ['meta->a\\b'];
        yield 'empty key' => ['meta->->a'];
        yield 'no column' => ['->a'];
        yield 'bad index' => ['meta->a[x]'];
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathsAreRejected(string $column): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compile('mysql', fn (Database $db) => $db->from('t')->where($column)->is(1)->select());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reads(): iterable
    {
        yield 'mysql' => ['mysql', 'SELECT JSON_VALUE(`meta`, \'$."city"\') AS `city` FROM `users`'
            . ' WHERE JSON_VALUE(`meta`, \'$."address"."zip"\') = \'1000\' AND JSON_VALUE(`meta`, \'$."tags"[0]\') IS NULL'
            . ' ORDER BY JSON_VALUE(`u`.`meta`, \'$."age"\') ASC'];
        yield 'pgsql' => ['pgsql', 'SELECT ("meta" #>> \'{"city"}\') AS "city" FROM "users"'
            . ' WHERE ("meta" #>> \'{"address","zip"}\') = \'1000\' AND ("meta" #>> \'{"tags","0"}\') IS NULL'
            . ' ORDER BY ("u"."meta" #>> \'{"age"}\') ASC'];
        yield 'sqlite' => ['sqlite', 'SELECT json_extract("meta", \'$."city"\') AS "city" FROM "users"'
            . ' WHERE json_extract("meta", \'$."address"."zip"\') = \'1000\' AND json_extract("meta", \'$."tags"[0]\') IS NULL'
            . ' ORDER BY json_extract("u"."meta", \'$."age"\') ASC'];
        yield 'sqlsrv' => ['sqlsrv', 'SELECT JSON_VALUE([meta], \'$."city"\') AS [city] FROM [users]'
            . ' WHERE JSON_VALUE([meta], \'$."address"."zip"\') = \'1000\' AND JSON_VALUE([meta], \'$."tags"[0]\') IS NULL'
            . ' ORDER BY JSON_VALUE([u].[meta], \'$."age"\') ASC'];
    }

    #[DataProvider('reads')]
    public function testReadPaths(string $driver, string $expected): void
    {
        $this->assertSame($expected, $this->compile($driver, fn (Database $db) => $db->from('users')
            ->where('meta->address->zip')->is('1000')
            ->andWhereExpression(fn (Expression $e) => $e->json('meta', 'tags[0]'))->isNull()
            ->orderBy('u.meta->age')
            ->select(['meta->city' => 'city'])));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conditions(): iterable
    {
        yield 'mysql' => ['mysql', 'SELECT * FROM `t` WHERE JSON_CONTAINS(`tags`, \'"x"\') AND NOT JSON_CONTAINS(`meta`, \'{"a":1}\', \'$."roles"\')'
            . ' AND JSON_CONTAINS_PATH(`meta`, \'one\', \'$."a"."b"\') OR NOT JSON_CONTAINS_PATH(`meta`, \'one\', \'$."c"\')'];
        yield 'pgsql' => ['pgsql', 'SELECT * FROM "t" WHERE (CAST("tags" AS jsonb) @> CAST(\'"x"\' AS jsonb))'
            . ' AND NOT (CAST(("meta" #> \'{"roles"}\') AS jsonb) @> CAST(\'{"a":1}\' AS jsonb))'
            . ' AND ("meta" #> \'{"a","b"}\') IS NOT NULL OR ("meta" #> \'{"c"}\') IS NULL'];
    }

    #[DataProvider('conditions')]
    public function testConditions(string $driver, string $expected): void
    {
        $this->assertSame($expected, $this->compile($driver, fn (Database $db) => $db->from('t')
            ->where('tags')->jsonContains('x')
            ->andWhere('meta->roles')->jsonNotContains(['a' => 1])
            ->andWhere('meta->a->b')->jsonExists()
            ->orWhere('meta->c')->jsonNotExists()
            ->select()));
    }

    public function testScalarConditionsOnSqliteAndSqlServer(): void
    {
        $this->assertSame(
            'SELECT * FROM "t" WHERE EXISTS (SELECT 1 FROM json_each("tags") WHERE value = \'x\')'
            . ' AND NOT EXISTS (SELECT 1 FROM json_each("meta", \'$."roles"\') WHERE value = 3)'
            . ' AND json_type("meta", \'$."a"\') IS NOT NULL',
            $this->compile('sqlite', fn (Database $db) => $db->from('t')
                ->where('tags')->jsonContains('x')
                ->andWhere('meta->roles')->jsonNotContains(3)
                ->andWhere('meta->a')->jsonExists()
                ->select()),
        );
        $this->assertSame(
            'SELECT * FROM [t] WHERE EXISTS (SELECT 1 FROM OPENJSON([tags], \'$."on"\') WHERE [value] = \'true\')'
            . ' AND JSON_PATH_EXISTS([meta], \'$."a"\') = 0',
            $this->compile('sqlsrv', fn (Database $db) => $db->from('t')
                ->where('tags->on')->jsonContains(true)
                ->andWhere('meta->a')->jsonNotExists()
                ->select()),
        );

        $this->expectException(LogicException::class);
        $this->compile('sqlite', fn (Database $db) => $db->from('t')->where('tags')->jsonContains(['a'])->select());
    }

    public function testJsonExistsNeedsArrowPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compile('mysql', fn (Database $db) => $db->from('t')->where('meta')->jsonExists()->select());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function updates(): iterable
    {
        yield 'mysql' => ['mysql', 'UPDATE `users` SET `meta` = JSON_SET(`meta`, \'$."city"\', \'Sofia\', \'$."age"\', JSON_EXTRACT(\'30\', \'$\'),'
            . ' \'$."tags"\', JSON_EXTRACT(\'["a","b"]\', \'$\'), \'$."ok"\', JSON_EXTRACT(\'true\', \'$\'), \'$."n"\', JSON_EXTRACT(\'null\', \'$\')), `name` = \'x\''
            . ' WHERE `id` = 1'];
        yield 'pgsql' => ['pgsql', 'UPDATE "users" SET "meta" = jsonb_set(jsonb_set(jsonb_set(jsonb_set(jsonb_set(CAST("meta" AS jsonb),'
            . ' \'{"city"}\', CAST(\'"Sofia"\' AS jsonb)), \'{"age"}\', CAST(\'30\' AS jsonb)), \'{"tags"}\', CAST(\'["a","b"]\' AS jsonb)),'
            . ' \'{"ok"}\', CAST(\'true\' AS jsonb)), \'{"n"}\', CAST(\'null\' AS jsonb)), "name" = \'x\' WHERE "id" = 1'];
        yield 'sqlite' => ['sqlite', 'UPDATE "users" SET "meta" = json_set("meta", \'$."city"\', \'Sofia\', \'$."age"\', json(\'30\'),'
            . ' \'$."tags"\', json(\'["a","b"]\'), \'$."ok"\', json(\'true\'), \'$."n"\', json(\'null\')), "name" = \'x\' WHERE "id" = 1'];
        yield 'sqlsrv' => ['sqlsrv', 'UPDATE [users] SET [meta] = JSON_MODIFY(JSON_MODIFY(JSON_MODIFY(JSON_MODIFY(JSON_MODIFY([meta],'
            . ' \'$."city"\', \'Sofia\'), \'$."age"\', 30), \'$."tags"\', JSON_QUERY(\'["a","b"]\')), \'$."ok"\', CAST(TRUE AS BIT)),'
            . ' \'strict $."n"\', NULL), [name] = \'x\' WHERE [id] = 1'];
    }

    #[DataProvider('updates')]
    public function testUpdatePaths(string $driver, string $expected): void
    {
        $this->assertSame($expected, $this->compile($driver, fn (Database $db) => $db->update('users')
            ->where('id')->is(1)
            ->set([
                'meta->city' => 'Sofia',
                'meta->age' => 30,
                'meta->tags' => ['a', 'b'],
                'name' => 'x',
                'meta->ok' => true,
                'meta->n' => null,
            ])));
    }

    public function testWholeAndPathUpdateConflict(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compile('mysql', fn (Database $db) => $db->update('t')->set(['meta' => '{}', 'meta->a' => 1]));
    }

    /**
     * @return array{string, list<string|int>}
     */
    private static function arrow(string $value): array
    {
        $result = JsonPath::fromArrow($value);
        self::assertNotNull($result);

        return [$result[0], $result[1]->segments];
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
