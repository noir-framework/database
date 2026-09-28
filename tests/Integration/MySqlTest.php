<?php

declare(strict_types=1);

namespace Noirapi\Database\Test\Integration;

use Noirapi\Database\Connection;
use Noirapi\Database\Database;
use Noirapi\Database\Schema\AlterTable;
use Noirapi\Database\Schema\CreateTable;
use Noirapi\Database\SQL\Expression;
use Noirapi\Database\SQL\Interval;
use Noirapi\Database\SQL\SelectStatement;
use PDOException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real MySQL / MariaDB server. Configure it with NOIRAPI_DB_MYSQL_DSN,
 * NOIRAPI_DB_MYSQL_USER and NOIRAPI_DB_MYSQL_PASSWORD (default: database, user and password
 * "test" on localhost); skipped when the server is unreachable. Tables are prefixed with t_.
 */
#[RequiresPhpExtension('pdo_mysql')]
final class MySqlTest extends TestCase
{
    private const array TABLES = ['t_stats', 't_users', 't_files', 't_bits'];

    private Database $db;

    protected function setUp(): void
    {
        $connection = new Connection(
            self::env('NOIRAPI_DB_MYSQL_DSN', 'mysql:host=localhost;dbname=test'),
            self::env('NOIRAPI_DB_MYSQL_USER', 'test'),
            self::env('NOIRAPI_DB_MYSQL_PASSWORD', 'test'),
        );

        try {
            $connection->getPDO();
        } catch (PDOException $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }

        $this->db = new Database($connection);
        $this->dropAll();

        $this->db->schema()->create('t_users', static function (CreateTable $table): void {
            $table->integer('id')->autoincrement();
            $table->string('name', 64)->notNull()->unique();
            $table->integer('age');
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->dropAll();
        }
    }

    public function testInsertManyAndLastInsertId(): void
    {
        $this->db->insertMany([['name' => 'Ann', 'age' => 30], ['name' => 'Bob', 'age' => null]])->into('t_users');

        $this->assertSame('1', $this->db->lastInsertId());
        $this->assertSame('Bob', $this->db->from('t_users')->where('age')->is(null)->column('name'));
    }

    public function testUpsert(): void
    {
        $this->db->schema()->create('t_stats', static function (CreateTable $table): void {
            $table->string('page', 32)->notNull()->primary();
            $table->integer('hits')->notNull();
            $table->string('title', 32);
        });

        $hit = fn (string $page, string $title) => $this->db->insert(['page' => $page, 'hits' => 1, 'title' => $title])
            ->upsert('page', ['title', 'hits' => static fn (Expression $e) => $e->column('t_stats.hits')->op('+')->value(1)])
            ->into('t_stats');

        $hit('/', 'Home');
        $hit('/', 'Home 2');
        $hit('/about', 'About');

        $this->db->insertMany([['page' => '/', 'hits' => 0, 'title' => 'x'], ['page' => '/new', 'hits' => 7, 'title' => 'y']])
            ->upsert('page', [])
            ->into('t_stats');

        $this->db->insert(['page' => '/about', 'hits' => 9, 'title' => 'New'])->upsert('page')->into('t_stats');

        $this->assertSame(
            [['/', 2, 'Home 2'], ['/about', 9, 'New'], ['/new', 7, 'y']],
            $this->db->from('t_stats')->orderBy('page')->select()->fetchNum()->all(),
        );
    }

    public function testUpsertOnSecondaryUniqueKey(): void
    {
        $this->db->insert(['name' => 'Ann', 'age' => 30])->into('t_users');
        $this->db->insert(['name' => 'Ann', 'age' => 31])->upsert('name')->into('t_users');

        $this->assertSame([[1, 'Ann', 31]], $this->db->from('t_users')->select()->fetchNum()->all());
    }

    public function testViewsInlineQuotedValues(): void
    {
        $this->db->insertMany([['name' => "O'Ne\\il", 'age' => 40], ['name' => 'Bob', 'age' => 20]])->into('t_users');

        $schema = $this->db->schema();
        $schema->createView('t_adults', 't_users', static function (SelectStatement $query): void {
            $query->where('age')->gte(30)->andWhere('name')->in(["O'Ne\\il", 'x'])->select(['id', 'name']);
        });

        $this->assertTrue($schema->hasView('t_adults'));
        $this->assertArrayNotHasKey('t_adults', $schema->getTables(true));
        $this->assertSame([["O'Ne\\il"]], $this->db->from('t_adults')->select('name')->fetchNum()->all());

        $schema->dropView('t_adults');
        $this->assertFalse($schema->hasView('t_adults'));
    }

    public function testJsonAndRenameColumn(): void
    {
        $schema = $this->db->schema();
        $schema->alter('t_users', static function (AlterTable $table): void {
            $table->json('meta');
        });
        $this->db->insert(['name' => 'Ann', 'age' => 1, 'meta' => '{"a": 1}'])->into('t_users');

        $schema->alter('t_users', static function (AlterTable $table): void {
            $table->renameColumn('age', 'years');
        });

        $schema->alter('t_users', static function (AlterTable $table): void {
            $table->renameColumn('name', 'full_name');
        });

        $this->assertSame(['id', 'full_name', 'years', 'meta'], $schema->getColumns('t_users', true));
        $this->assertSame(
            'NO',
            $this->db->getConnection()->column('SELECT IS_NULLABLE FROM information_schema.columns'
                . ' WHERE table_schema = database() AND table_name = ? AND column_name = ?', ['t_users', 'full_name']),
        );
        $this->assertSame(1, $this->db->from('t_users')->column('years'));
        $this->assertSame(
            1,
            $this->db->from('t_users')->whereExpression(static fn (Expression $e) => $e->JSON_EXTRACT(
                Expression::fromColumn('meta'),
                '$.a',
            ))->is(1)->count(),
        );
    }

    public function testLobParameter(): void
    {
        $this->db->schema()->create('t_files', static function (CreateTable $table): void {
            $table->binary('data');
        });
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, "\x00\x01binary");
        rewind($stream);

        $this->db->insert(['data' => $stream])->into('t_files');

        $this->assertSame("\x00\x01binary", $this->db->from('t_files')->column('data'));
    }

    public function testStreamUnbuffered(): void
    {
        $this->db->insertMany(array_map(
            static fn (int $i): array => ['name' => 'user' . $i, 'age' => $i],
            range(1, 500),
        ))->into('t_users');

        $sum = 0;
        foreach ($this->db->from('t_users')->orderBy('id')->stream(['age'])->fetchAssoc() as $row) {
            $sum += $row['age'];
        }
        $this->assertSame(125250, $sum);

        foreach ($this->db->from('t_users')->orderBy('id')->stream(['age'])->fetchAssoc() as $row) {
            $this->assertSame(1, $row['age']);
            try {
                $this->db->from('t_users')->count();
                $this->fail('A second query must fail while the stream is open');
            } catch (PDOException) {
                // unbuffered: the server is still sending the streamed rows
            }
            break;
        }
        // the early break closed the cursor, so the connection is usable again
        $this->assertSame(500, $this->db->from('t_users')->count());
    }

    public function testFunctionsAndDates(): void
    {
        $this->db->schema()->alter('t_users', static function (AlterTable $table): void {
            $table->dateTime('seen');
            $table->binary('ip')->size('tiny');
        });
        $this->db->insertMany([
            ['name' => 'Ann', 'age' => 30, 'seen' => static fn (Expression $e) => $e->ago(10, Interval::Day), 'ip' => static fn (Expression $e) => $e->inet6Aton('2001:db8::1')],
            ['name' => 'Bob', 'age' => 1, 'seen' => static fn (Expression $e) => $e->ago(2, Interval::Hour), 'ip' => static fn (Expression $e) => $e->inet6Aton('10.0.0.1')],
        ])->into('t_users');

        $this->assertSame(
            [['Bob', '10.0.0.1']],
            $this->db->from('t_users')
                ->where('seen')->gte(static fn (Expression $e) => $e->ago(1, Interval::Day))
                ->select(['name', 'ip' => static fn (Expression $e) => $e->inet6Ntoa('ip')])
                ->fetchNum()
                ->all(),
        );
        $this->assertSame('Ann', $this->db->from('t_users')->where('ip')->is(static fn (Expression $e) => $e->inet6Aton('2001:db8::1'))->column('name'));
        $this->assertSame(
            1,
            $this->db->from('t_users')
                ->where(static fn (Expression $e) => $e->dateAdd('seen', Expression::fromColumn('age'), Interval::Day), true)
                ->gt(static fn (Expression $e) => $e->fromNow(15, Interval::Day))
                ->count(),
        );
    }

    public function testBitsWithBit63OnSignedAndUnsigned(): void
    {
        $this->db->getConnection()->command(
            'CREATE TABLE t_bits (id INT PRIMARY KEY, s BIGINT NOT NULL DEFAULT 0, u BIGINT UNSIGNED NOT NULL DEFAULT 0)',
        );
        $this->db->insert(['id' => 1])->into('t_bits');

        $top = PHP_INT_MIN; // bit 63
        $this->assertSame(1, $this->db->update('t_bits')->setBits('u', $top | 5));
        $this->assertSame(1, $this->db->update('t_bits')->setBits('s', $top | 5, signed: true));
        $this->assertSame(1, $this->db->update('t_bits')->set([
            's' => static fn (Expression $e) => $e->bits('s', set: 8, clear: 1, signed: true),
            'u' => static fn (Expression $e) => $e->bits('u', set: 8, clear: 1),
        ]));

        $row = $this->db->from('t_bits')->select(['s', 'u'])->fetchAssoc()->first();
        $this->assertSame(['s' => $top | 12, 'u' => '9223372036854775820'], $row);

        foreach (['s', 'u'] as $column) {
            $count = fn (string $test, int $mask): int => $this->db->from('t_bits')->where($column)->{$test}($mask)->count();
            $this->assertSame([1, 1, 0, 0], [$count('hasAllBits', $top | 12), $count('hasAllBits', $top), $count('hasAllBits', $top | 1), $count('hasAllBits', 2)], $column);
            $this->assertSame([1, 1, 0], [$count('hasAnyBits', $top), $count('hasAnyBits', 3 | 4), $count('hasAnyBits', 3)], $column);
            $this->assertSame([1, 0, 0], [$count('hasNoBits', 3), $count('hasNoBits', $top), $count('hasNoBits', 4)], $column);
        }

        $this->db->update('t_bits')->clearBits('s', $top, signed: true);
        $this->db->update('t_bits')->clearBits('u', $top);
        $this->assertSame([12, 12], $this->db->from('t_bits')->select(['s', 'u'])->fetchNum()->first());
    }

    private function dropAll(): void
    {
        $pdo = $this->db->getConnection()->getPDO();
        $pdo->exec('DROP VIEW IF EXISTS t_adults');
        foreach (self::TABLES as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }
}
