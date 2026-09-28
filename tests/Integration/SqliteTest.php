<?php

declare(strict_types=1);

namespace Noirapi\Database\Test\Integration;

use Noirapi\Database\Connection;
use Noirapi\Database\Database;
use Noirapi\Database\Schema\AlterTable;
use Noirapi\Database\Schema\CreateTable;
use Noirapi\Database\SQL\Expression;
use Noirapi\Database\SQL\Interval;
use Noirapi\Database\SQL\Join;
use Noirapi\Database\SQL\SelectStatement;
use Noirapi\Database\SQL\Subquery;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Runs the builders against a real in-memory SQLite database.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class SqliteTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(new Connection('sqlite::memory:'));

        $schema = $this->db->schema();
        $schema->create('users', static function (CreateTable $table): void {
            $table->integer('id')->autoincrement();
            $table->string('name', 64)->notNull();
            $table->integer('age')->defaultValue(0);
        });
        $schema->create('orders', static function (CreateTable $table): void {
            $table->integer('id')->autoincrement();
            $table->integer('user_id')->notNull()->index();
            $table->decimal('total', 10, 2);
        });

        foreach ([['Ann', 30], ['Bob', 25], ['Cid', 41]] as [$name, $age]) {
            $this->assertTrue($this->db->insert(['name' => $name, 'age' => $age])->into('users'));
        }
        foreach ([[1, 10.5], [1, 20.0], [3, 7.25]] as [$user, $total]) {
            $this->db->insert(['user_id' => $user, 'total' => $total])->into('orders');
        }
    }

    public function testSchemaIntrospection(): void
    {
        $schema = $this->db->schema();

        $this->assertTrue($schema->hasTable('USERS'));
        $this->assertSame(['id', 'name', 'age'], $schema->getColumns('users'));
        $this->assertFalse($schema->getColumns('missing'));
    }

    public function testSelectWithWhereOrderAndLimit(): void
    {
        $rows = $this->db->from('users')
            ->where('age')->gt(26)
            ->orderBy('age', 'desc')
            ->limit(1)
            ->select(['name'])
            ->fetchAssoc()
            ->all();

        $this->assertSame([['name' => 'Cid']], $rows);
    }

    public function testFetchClassAndFirst(): void
    {
        $user = $this->db->from('users')->where('name')->is('Bob')->select()->fetchClass(UserRow::class)->first();

        $this->assertInstanceOf(UserRow::class, $user);
        $this->assertSame(25, $user->age);
        $this->assertFalse($this->db->from('users')->where('id')->is(999)->select()->first());
    }

    public function testAggregatesJoinsAndSubqueries(): void
    {
        $this->assertSame(3, $this->db->from('users')->count());
        $this->assertSame(0, $this->db->from('users')->where('age')->gt(100)->count());
        $this->assertSame(0, $this->db->from('users')->where('age')->gt(100)->groupBy('age')->count());
        $this->assertSame(41, $this->db->from('users')->max('age'));

        $names = $this->db->from(['users' => 'u'])
            ->join(['orders' => 'o'], static function (Join $join): void {
                $join->on('u.id', 'o.user_id');
            })
            ->groupBy('u.name')
            ->having('o.total', static fn ($having) => $having->sum()->gt(20))
            ->select(['u.name'])
            ->fetchNum()
            ->all();
        $this->assertSame([['Ann']], $names);

        $withOrders = $this->db->from('users')
            ->where('id')->in(static function (Subquery $query): void {
                $query->from('orders')->select('user_id');
            })
            ->orderBy('id')
            ->select('name')
            ->fetchNum()
            ->all();
        $this->assertSame([['Ann'], ['Cid']], $withOrders);
    }

    public function testUpdateIncrementAndDelete(): void
    {
        $this->assertSame(1, $this->db->update('users')->where('name')->is('Ann')->set(['age' => 31]));
        $this->assertSame(2, $this->db->update('users')->where('age')->lt(40)->increment('age', 2));
        $this->assertSame(
            33,
            $this->db->from('users')->where('name')->is('Ann')->column('age'),
        );

        $this->assertSame(1, $this->db->from('orders')->where('user_id')->is(3)->delete());
        $this->assertSame(2, $this->db->from('orders')->count());
    }

    public function testExpressionsAndNestedConditions(): void
    {
        $rows = $this->db->from('users')
            ->where(static function ($group): void {
                $group->where('age')->lt(26)->orWhere('age')->gt(40);
            })
            ->andWhere(static fn (Expression $expr) => $expr->column('age')->op('+')->value(1), true)->gt(0)
            ->orderBy('name')
            ->select(['name'])
            ->fetchObject()
            ->all();

        $this->assertEquals([(object) ['name' => 'Bob'], (object) ['name' => 'Cid']], $rows);
        $this->assertContainsOnlyInstancesOf(stdClass::class, $rows);
    }

    public function testTransactionAndAlter(): void
    {
        $result = $this->db->transaction(static function (Database $db): string {
            $db->insert(['name' => 'Dan'])->into('users');

            return 'done';
        });
        $this->assertSame('done', $result);
        $this->assertSame(4, $this->db->from('users')->count());

        $this->db->schema()->alter('users', static function (AlterTable $table): void {
            $table->string('email')->defaultValue('none');
        });
        $this->assertSame(['id', 'name', 'age', 'email'], $this->db->schema()->getColumns('users', true));
    }

    public function testInsertManyAndLastInsertId(): void
    {
        $this->assertTrue($this->db->insertMany([
            ['name' => 'Dan', 'age' => 50],
            ['age' => 51, 'name' => 'Eve'],
        ])->into('users'));

        $this->assertSame('5', $this->db->lastInsertId());
        $this->assertSame(
            [['Dan', 50], ['Eve', 51]],
            $this->db->from('users')->where('id')->gt(3)->orderBy('id')->select(['name', 'age'])->fetchNum()->all(),
        );
        $this->assertSame($this->db->getConnection()->getDatabase(), $this->db->getConnection()->getDatabase());
    }

    public function testUpsert(): void
    {
        $this->db->schema()->create('stats', static function (CreateTable $table): void {
            $table->string('page', 32)->notNull();
            $table->integer('hits')->notNull();
            $table->string('title', 32);
            $table->primary('page');
        });

        $hit = fn (string $page, string $title) => $this->db->insert(['page' => $page, 'hits' => 1, 'title' => $title])
            ->upsert('page', ['title', 'hits' => static fn (Expression $e) => $e->column('stats.hits')->op('+')->value(1)])
            ->into('stats');

        $hit('/', 'Home');
        $hit('/', 'Home 2');
        $hit('/about', 'About');

        $this->db->insertMany([['page' => '/', 'hits' => 0, 'title' => 'x'], ['page' => '/new', 'hits' => 7, 'title' => 'y']])
            ->upsert('page', [])
            ->into('stats');

        $this->assertSame(
            [['/', 2, 'Home 2'], ['/about', 1, 'About'], ['/new', 7, 'y']],
            $this->db->from('stats')->orderBy('page')->select()->fetchNum()->all(),
        );

        $this->db->insert(['page' => '/about', 'hits' => 9, 'title' => 'New'])->upsert('page')->into('stats');
        $this->assertSame([9, 'New'], $this->db->from('stats')->where('page')->is('/about')->select(['hits', 'title'])->fetchNum()->first());
    }

    public function testWhereIsNull(): void
    {
        $this->db->insert(['name' => 'Nul', 'age' => null])->into('users');

        $this->assertSame('Nul', $this->db->from('users')->where('age')->is(null)->column('name'));
        $this->assertSame(3, $this->db->from('users')->where('age')->isNot(null)->count());
    }

    public function testJsonColumnKeepsText(): void
    {
        $this->db->schema()->alter('users', static function (AlterTable $table): void {
            $table->json('meta');
        });
        $this->db->update('users')->where('id')->is(1)->set(['meta' => '1']);

        $this->assertSame('1', $this->db->from('users')->where('id')->is(1)->column('meta'));
    }

    public function testViews(): void
    {
        $schema = $this->db->schema();
        $schema->createView('adults', 'users', static function (SelectStatement $query): void {
            $query->where('age')->gte(30)->andWhere('name')->isNot("O'Neil")->orderBy('name')->select(['id', 'name']);
        });

        $this->assertTrue($schema->hasView('ADULTS'));
        $this->assertArrayNotHasKey('adults', $schema->getTables(true));
        $this->assertSame([['Ann'], ['Cid']], $this->db->from('adults')->select('name')->fetchNum()->all());

        $schema->dropView('adults');
        $this->assertFalse($schema->hasView('adults'));
    }

    public function testRenameColumn(): void
    {
        $this->db->schema()->alter('users', static function (AlterTable $table): void {
            $table->renameColumn('age', 'years');
        });

        $this->assertSame(['id', 'name', 'years'], $this->db->schema()->getColumns('users', true));
    }

    public function testLobParameter(): void
    {
        $this->db->schema()->create('files', static function (CreateTable $table): void {
            $table->binary('data');
        });
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, "\x00\x01binary");
        rewind($stream);

        $this->db->insert(['data' => $stream])->into('files');

        $this->assertSame("\x00\x01binary", $this->db->from('files')->column('data'));
    }

    public function testLazyAndIteration(): void
    {
        $names = [];
        foreach ($this->db->from('users')->orderBy('id')->select()->fetchClass(UserRow::class) as $user) {
            $this->assertInstanceOf(UserRow::class, $user);
            $names[] = $user->name;
        }
        $this->assertSame(['Ann', 'Bob', 'Cid'], $names);

        foreach ($this->db->from('users')->orderBy('id')->select('name')->fetchNum()->lazy() as $row) {
            $this->assertSame(['Ann'], $row);
            break;
        }

        $this->db->insert(['name' => '0', 'age' => 0])->into('users');
        $column = iterator_to_array(
            $this->db->from('users')->orderBy('id')->select('name')->lazy(\PDO::FETCH_COLUMN),
            false,
        );
        $this->assertSame(['Ann', 'Bob', 'Cid', '0'], $column);
        $this->assertSame(['Ann', 'Bob'], iterator_to_array(
            $this->db->from('users')->orderBy('id')->limit(2)->stream('name')->lazy(\PDO::FETCH_COLUMN),
            false,
        ));
    }

    public function testFunctionsAndDates(): void
    {
        $this->db->schema()->alter('users', static function (AlterTable $table): void {
            $table->dateTime('seen');
        });
        $this->db->update('users')->where('name')->is('Ann')->set(['seen' => static fn (Expression $e) => $e->ago(10, Interval::Day)]);
        $this->db->update('users')->where('name')->is('Bob')->set(['seen' => static fn (Expression $e) => $e->ago(2, Interval::Hour)]);
        $this->db->update('users')->where('name')->is('Cid')->set(['seen' => static fn (Expression $e) => $e->fromNow(1, Interval::Week)]);

        $recent = $this->db->from('users')
            ->where('seen')->gte(static fn (Expression $e) => $e->ago(1, Interval::Day))
            ->andWhere('seen')->lt(static fn (Expression $e) => $e->now())
            ->select(['name'])
            ->fetchNum()
            ->all();
        $this->assertSame([['Bob']], $recent);

        $row = $this->db->from('users')->where('name')->is('Ann')->select([
            static fn (Expression $e) => $e->ucase('name'),
            static fn (Expression $e) => $e->mid('name', 2),
            static fn (Expression $e) => $e->len('name'),
            static fn (Expression $e) => $e->dateAdd('seen', Expression::fromColumn('age'), Interval::Day),
        ])->fetchNum()->first();
        $this->assertIsArray($row);
        $this->assertSame(['ANN', 'nn', 3], array_slice($row, 0, 3));
        $this->assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+20 days')->format('Y-m-d'),
            substr((string) $row[3], 0, 10),
        );
    }

    public function testBitsWithBit63(): void
    {
        $this->db->getConnection()->command('CREATE TABLE bits (id INTEGER PRIMARY KEY, f INTEGER NOT NULL DEFAULT 0)');
        $this->db->insert(['id' => 1])->into('bits');

        $top = PHP_INT_MIN;
        $this->db->update('bits')->setBits('f', $top | 5);
        $this->db->update('bits')->set(['f' => static fn (Expression $e) => $e->bits('f', set: 8, clear: 1)]);
        $this->assertSame($top | 12, $this->db->from('bits')->column('f'));

        $this->assertSame(1, $this->db->from('bits')->where('f')->hasAllBits($top | 12)->count());
        $this->assertSame(0, $this->db->from('bits')->where('f')->hasAllBits($top | 1)->count());
        $this->assertSame(1, $this->db->from('bits')->where('f')->hasAnyBits($top)->count());
        $this->assertSame(1, $this->db->from('bits')->where('f')->hasNoBits(3)->count());

        $this->db->update('bits')->clearBits('f', $top);
        $this->assertSame(12, $this->db->from('bits')->column('f'));
    }

    public function testJsonDocuments(): void
    {
        $this->db->schema()->alter('users', static function (AlterTable $table): void {
            $table->json('meta');
        });
        $this->db->insertMany([
            ['name' => 'Jan', 'age' => 30, 'meta' => '{"city": "Sofia", "tags": ["a", "b"], "n": null, "addr": {"zip": "1000"}}'],
            ['name' => 'Kim', 'age' => 20, 'meta' => '{"city": "Varna", "tags": ["c"], "addr": {"zip": "9000"}}'],
        ])->into('users');

        $q = fn () => $this->db->from('users')->where('name')->in(['Jan', 'Kim'])->orderBy('name');
        $this->assertSame([['Jan', 'Sofia'], ['Kim', 'Varna']], $q()->select(['name', 'meta->city' => 'city'])->fetchNum()->all());
        $this->assertSame('Kim', $q()->where('meta->addr->zip')->is('9000')->column('name'));
        $this->assertSame('b', $q()->where('name')->is('Jan')->column(static fn (Expression $e) => $e->json('meta', 'tags[1]')));
        $this->assertSame('Jan', $q()->where('meta->tags')->jsonContains('b')->column('name'));
        $this->assertSame(1, $q()->where('meta->tags')->jsonNotContains('b')->count());
        $this->assertSame(1, $q()->where('meta->n')->jsonExists()->count());
        $this->assertSame(2, $q()->where('meta->n')->isNull()->count());
        $this->assertSame(1, $q()->where('meta->n')->jsonNotExists()->count());

        $this->db->update('users')->where('name')->is('Jan')->set([
            'meta->city' => 'Plovdiv',
            'meta->visits' => 3,
            'meta->vip' => true,
            'meta->tags' => ['x'],
            'meta->addr->zip' => '4000',
            'age' => 31,
        ]);
        $meta = json_decode((string) $q()->where('name')->is('Jan')->column('meta'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($meta);
        $this->assertSame(['Plovdiv', 3, true, ['x'], ['zip' => '4000'], null], [$meta['city'], $meta['visits'], $meta['vip'], $meta['tags'], $meta['addr'], $meta['n']]);
        $this->assertSame(31, $q()->where('name')->is('Jan')->column('age'));
    }

    public function testTransactionRollsBackOnAnyException(): void
    {
        try {
            $this->db->transaction(static function (Database $db): void {
                $db->insert(['name' => 'Tx'])->into('users');
                throw new \DomainException('business rule');
            });
            $this->fail('A non-PDO exception must be re-thrown');
        } catch (\DomainException $e) {
            $this->assertSame('business rule', $e->getMessage());
        }
        $this->assertFalse($this->db->getConnection()->getPDO()->inTransaction());
        $this->assertSame(0, $this->db->from('users')->where('name')->is('Tx')->count());

        $result = $this->db->transaction(static function (Database $db): void {
            $db->insert(['name' => 'Tx'])->into('users');
            $db->insert(['id' => 1, 'name' => 'duplicate'])->into('users');
        }, 'failed');
        $this->assertSame('failed', $result);
        $this->assertSame(0, $this->db->from('users')->where('name')->is('Tx')->count());
    }

    public function testEmptyInLists(): void
    {
        $this->assertSame(0, $this->db->from('users')->where('id')->in([])->count());
        $this->assertSame(3, $this->db->from('users')->where('id')->notIn([])->count());
    }

    public function testInsertManySplitsLargeBatches(): void
    {
        $connection = $this->db->getConnection();
        $connection->logQueries();
        $rows = array_map(static fn (int $i): array => ['name' => 'bulk' . $i, 'age' => $i], range(1, 700));

        $this->assertTrue($this->db->insertMany($rows)->into('users'));

        $this->assertSame(700, $this->db->from('users')->where('name')->like('bulk%')->count());
        $inserts = array_filter($connection->getLog(), static fn (array $e): bool => str_starts_with($e['query'], 'INSERT'));
        $this->assertCount(2, $inserts); // 1400 values > 999 per statement on SQLite

        // a failing later chunk rolls back the earlier ones
        $rows = array_map(static fn (int $i): array => ['id' => 1000 + $i, 'name' => 'dup'], range(1, 699));
        $rows[] = ['id' => 1, 'name' => 'dup'];
        try {
            $this->db->insertMany($rows)->into('users');
            $this->fail('The duplicate key must fail the insert');
        } catch (\PDOException) {
            $this->assertSame(0, $this->db->from('users')->where('name')->is('dup')->count());
            $this->assertFalse($connection->getPDO()->inTransaction());
        }

        // inside the caller's transaction, the chunks join it
        $this->db->transaction(function (Database $db): void {
            $db->insertMany(array_map(static fn (int $i): array => ['name' => 'tx' . $i, 'age' => $i], range(1, 600)))->into('users');
            $this->assertTrue($db->getConnection()->getPDO()->inTransaction());
        });
        $this->assertSame(600, $this->db->from('users')->where('name')->like('tx%')->count());
    }

    public function testLogLimitAndQueryListener(): void
    {
        $connection = $this->db->getConnection();
        $seen = [];
        $connection->logQueries(true, 2)->onQuery(static function (string $sql, array $params, float $seconds) use (&$seen): void {
            $seen[] = [$sql, $params];
        });

        $this->db->from('users')->where('id')->is(1)->column('name');
        $this->db->from('users')->where('id')->is(2)->column('name');
        $this->db->from('users')->where('id')->is(3)->column('name');

        $this->assertSame(
            ['SELECT "name" FROM "users" WHERE "id" = 2', 'SELECT "name" FROM "users" WHERE "id" = 3'],
            array_column($connection->getLog(), 'query'),
        );
        $this->assertSame(['SELECT "name" FROM "users" WHERE "id" = ?', [1]], $seen[0]);
        $this->assertCount(3, $seen);

        $connection->onQuery(null)->clearLog();
        $this->db->from('users')->count();
        $this->assertCount(3, $seen);
        $this->assertCount(1, $connection->getLog());
    }

    public function testTransactionAttempts(): void
    {
        $deadlock = static function (): \PDOException {
            $e = new \PDOException('Deadlock found');
            $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

            return $e;
        };

        $runs = 0;
        $result = $this->db->transaction(function (Database $db) use (&$runs, $deadlock): int {
            $db->insert(['name' => 'Try' . ++$runs])->into('users');
            if ($runs < 3) {
                throw $deadlock();
            }

            return $runs;
        }, 'failed', attempts: 3);
        $this->assertSame(3, $result);
        $this->assertSame(['Try3'], iterator_to_array($this->db->from('users')->where('name')->like('Try%')->select('name')->lazy(\PDO::FETCH_COLUMN)));

        $runs = 0;
        $this->assertSame('failed', $this->db->transaction(function () use (&$runs, $deadlock): void {
            $runs++;
            throw $deadlock();
        }, 'failed', attempts: 2));
        $this->assertSame(2, $runs);

        $runs = 0;
        $this->assertSame('failed', $this->db->transaction(function () use (&$runs): void {
            $runs++;
            throw new \PDOException('syntax error');
        }, 'failed', attempts: 5));
        $this->assertSame(1, $runs);
    }
}
