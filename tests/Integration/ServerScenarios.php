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
use PHPUnit\Framework\TestCase;

/**
 * The same scenarios against every real database server; each subclass supplies the connection.
 * Tables and views are prefixed with s_ and dropped before and after each test.
 */
abstract class ServerScenarios extends TestCase
{
    private const array TABLES = ['s_stats', 's_users', 's_bits'];

    protected Database $db;

    /**
     * @return array{string, string, string, string} PDO extension, DSN, user, password
     */
    abstract protected static function server(): array;

    protected function setUp(): void
    {
        [$extension, $dsn, $user, $password] = static::server();
        if (!extension_loaded($extension)) {
            $this->markTestSkipped($extension . ' is not installed');
        }

        $connection = new Connection($dsn, $user, $password);
        try {
            $connection->getPDO();
        } catch (PDOException $e) {
            $this->markTestSkipped('Server not available: ' . $e->getMessage());
        }

        $this->db = new Database($connection);
        $this->dropAll();

        $this->db->schema()->create('s_users', static function (CreateTable $table): void {
            $table->integer('id')->autoincrement();
            $table->string('name', 64)->notNull();
            $table->integer('age');
            $table->json('meta');
            $table->dateTime('seen');
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->dropAll();
        }
    }

    public function testInsertManyUpsertAndEmptyIn(): void
    {
        $this->db->schema()->create('s_stats', static function (CreateTable $table): void {
            $table->string('page', 32)->notNull()->primary();
            $table->integer('hits')->notNull();
            $table->string('title', 32);
        });

        $hit = fn (string $page, string $title) => $this->db->insert(['page' => $page, 'hits' => 1, 'title' => $title])
            ->upsert('page', ['title', 'hits' => static fn (Expression $e) => $e->column('s_stats.hits')->op('+')->value(1)])
            ->into('s_stats');
        $hit('/', 'Home');
        $hit('/', 'Home 2');
        $hit('/about', 'About');

        $this->db->insertMany([['page' => '/', 'hits' => 0, 'title' => 'x'], ['page' => '/new', 'hits' => 7, 'title' => 'y']])
            ->upsert('page', [])
            ->into('s_stats');
        $this->db->insert(['page' => '/about', 'hits' => 9, 'title' => 'New'])->upsert('page')->into('s_stats');

        // pdo_sqlsrv returns numbers as strings, so compare values
        $this->assertEquals(
            [['/', 2, 'Home 2'], ['/about', 9, 'New'], ['/new', 7, 'y']],
            $this->db->from('s_stats')->orderBy('page')->select(['page', 'hits', 'title'])->fetchNum()->all(),
        );

        $this->assertSame(0, $this->db->from('s_stats')->where('page')->in([])->count());
        $this->assertSame(3, $this->db->from('s_stats')->where('page')->notIn([])->count());
    }

    public function testInsertManySplitsAtTheParameterLimit(): void
    {
        $max = $this->db->getConnection()->getCompiler()->getMaxParams();
        $count = intdiv($max, 2) + 10;
        $rows = array_map(static fn (int $i): array => ['name' => 'u' . $i, 'age' => $i], range(1, $count));

        $this->assertTrue($this->db->insertMany($rows)->into('s_users'));
        $this->assertSame($count, $this->db->from('s_users')->count());
    }

    public function testJson(): void
    {
        $this->db->insertMany([
            ['name' => 'Jan', 'age' => 30, 'meta' => '{"city": "Sofia", "tags": ["a", "b"], "n": null, "addr": {"zip": "1000"}}'],
            ['name' => 'Kim', 'age' => 20, 'meta' => '{"city": "Varna", "tags": ["c"], "addr": {"zip": "9000"}}'],
        ])->into('s_users');

        $q = fn () => $this->db->from('s_users')->orderBy('name');
        $this->assertSame([['Jan', 'Sofia'], ['Kim', 'Varna']], $q()->select(['name', 'meta->city' => 'city'])->fetchNum()->all());
        $this->assertSame('Kim', $q()->where('meta->addr->zip')->is('9000')->column('name'));
        $this->assertSame('Jan', $q()->where('meta->tags')->jsonContains('b')->column('name'));
        $this->assertSame(1, $q()->where('meta->tags')->jsonNotContains('b')->count());
        $this->assertSame(1, $q()->where('meta->n')->jsonExists()->count());
        $this->assertSame(2, $q()->where('meta->n')->isNull()->count());

        $this->db->update('s_users')->where('name')->is('Jan')->set([
            'meta->city' => 'Plovdiv',
            'meta->visits' => 3,
            'meta->vip' => true,
            'meta->tags' => ['x'],
            'meta->addr->zip' => '4000',
            'age' => 31,
        ]);
        $meta = json_decode((string) $q()->where('name')->is('Jan')->column('meta'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($meta);
        $this->assertSame(
            ['Plovdiv', 3, true, ['x'], ['zip' => '4000']],
            [$meta['city'], $meta['visits'], $meta['vip'], $meta['tags'], $meta['addr']],
        );
    }

    public function testBitsWithBit63(): void
    {
        $this->db->schema()->create('s_bits', static function (CreateTable $table): void {
            $table->integer('id')->primary();
            $table->integer('f')->size('big')->notNull()->defaultValue(0);
        });
        $this->db->insert(['id' => 1])->into('s_bits');

        $top = PHP_INT_MIN;
        $this->db->update('s_bits')->setBits('f', $top | 5, signed: true);
        $this->db->update('s_bits')->set(['f' => static fn (Expression $e) => $e->bits('f', set: 8, clear: 1, signed: true)]);
        $this->assertSame($top | 12, (int) $this->db->from('s_bits')->column('f'));

        $count = fn (string $test, int $mask): int => $this->db->from('s_bits')->where('f')->{$test}($mask)->count();
        $this->assertSame([1, 1, 0, 0], [$count('hasAllBits', $top | 12), $count('hasAllBits', $top), $count('hasAllBits', $top | 1), $count('hasAllBits', 2)]);
        $this->assertSame([1, 1, 0], [$count('hasAnyBits', $top), $count('hasAnyBits', 3 | 4), $count('hasAnyBits', 3)]);
        $this->assertSame([1, 0], [$count('hasNoBits', 3), $count('hasNoBits', $top)]);

        $this->db->update('s_bits')->clearBits('f', $top, signed: true);
        $this->assertSame(12, (int) $this->db->from('s_bits')->column('f'));
    }

    public function testDatesAndFunctions(): void
    {
        $this->db->insertMany([
            ['name' => 'Ann', 'age' => 30, 'seen' => static fn (Expression $e) => $e->ago(10, Interval::Day)],
            ['name' => 'Bob', 'age' => 1, 'seen' => static fn (Expression $e) => $e->ago(2, Interval::Hour)],
        ])->into('s_users');

        $this->assertSame([['Bob']], $this->db->from('s_users')
            ->where('seen')->gte(static fn (Expression $e) => $e->ago(1, Interval::Day))
            ->andWhere('seen')->lt(static fn (Expression $e) => $e->fromNow(1, Interval::Minute))
            ->select(['name'])->fetchNum()->all());
        $this->assertSame(1, $this->db->from('s_users')
            ->whereExpression(static fn (Expression $e) => $e->dateAdd('seen', Expression::fromColumn('age'), Interval::Day))
            ->gt(static fn (Expression $e) => $e->fromNow(15, Interval::Day))
            ->count());
        $this->assertSame(2, $this->db->from('s_users')
            ->where('seen')->lt(static fn (Expression $e) => $e->dateAdd(static fn (Expression $n) => $n->currentDate(), 1, Interval::Day))
            ->count());

        $row = $this->db->from('s_users')->where('name')->is('Ann')->select([
            static fn (Expression $e) => $e->ucase('name'),
            static fn (Expression $e) => $e->lcase('name'),
            static fn (Expression $e) => $e->mid('name', 2),
            static fn (Expression $e) => $e->len('name'),
        ])->fetchNum()->first();
        $this->assertIsArray($row);
        $this->assertSame(['ANN', 'ann', 'nn', 3], [$row[0], $row[1], $row[2], (int) $row[3]]);
    }

    public function testViewsRenameAndTransactions(): void
    {
        $this->db->insertMany([['name' => "O'Ne\\il", 'age' => 40], ['name' => 'Bob', 'age' => 20]])->into('s_users');

        $schema = $this->db->schema();
        $schema->createView('s_adults', 's_users', static function (SelectStatement $query): void {
            $query->where('age')->gte(30)->andWhere('name')->in(["O'Ne\\il", 'x'])->select(['id', 'name']);
        });
        $this->assertTrue($schema->hasView('s_adults'));
        $this->assertSame([["O'Ne\\il"]], $this->db->from('s_adults')->select('name')->fetchNum()->all());
        $schema->dropView('s_adults');
        $this->assertFalse($schema->hasView('s_adults', true));

        $schema->alter('s_users', static function (AlterTable $table): void {
            $table->renameColumn('age', 'years');
        });
        $this->assertContains('years', (array) $schema->getColumns('s_users', true));

        try {
            $this->db->transaction(static function (Database $db): void {
                $db->insert(['name' => 'Tx', 'years' => 1])->into('s_users');
                throw new \DomainException('rule');
            });
        } catch (\DomainException) {
        }
        $this->assertSame(0, $this->db->from('s_users')->where('name')->is('Tx')->count());
        $this->assertSame(2, iterator_count($this->db->from('s_users')->stream(['name'])));
    }

    protected static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }

    private function dropAll(): void
    {
        $schema = $this->db->schema();
        if ($schema->hasView('s_adults', true)) {
            $schema->dropView('s_adults');
        }
        foreach (self::TABLES as $table) {
            if ($schema->hasTable($table, true)) {
                $schema->drop($table);
            }
        }
    }
}
