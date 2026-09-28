<?php

declare(strict_types=1);

namespace Noirapi\Database\Test\Integration;

use Noirapi\Database\Connection;
use Noirapi\Database\Database;
use Noirapi\Database\Schema\AlterTable;
use Noirapi\Database\Schema\CreateTable;
use Noirapi\Database\SQL\Expression;
use Noirapi\Database\SQL\Join;
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
}
