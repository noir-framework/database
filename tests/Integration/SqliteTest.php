<?php

declare(strict_types=1);

namespace Noirapi\Database\Test\Integration;

use Noirapi\Database\Connection;
use Noirapi\Database\Database;
use Noirapi\Database\Schema\AlterTable;
use Noirapi\Database\Schema\CreateTable;
use Noirapi\Database\SQL\Expression;
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
}
