<?php

declare(strict_types=1);

// Analysed by PHPStan only (never executed): locks in the types apps see from fluent chains.

namespace Noirapi\Database\Test\Types;

use Noirapi\Database\Database;
use Noirapi\Database\SQL\Expression;
use Noirapi\Database\SQL\Insert;
use Noirapi\Database\SQL\Interval;
use Noirapi\Database\SQL\Query;
use Noirapi\Database\SQL\Select;
use Noirapi\Database\SQL\Update;
use Noirapi\Database\SQL\WhereStatement;

use function PHPStan\Testing\assertType;

final class User
{
    public int $id;
}

function check(Database $db): void
{
    assertType(Query::class, $db->from('users'));
    assertType('Noirapi\Database\SQL\Where<' . Query::class . '>', $db->from('users')->where('id'));
    assertType(Query::class, $db->from('users')->where('id')->is(1));
    assertType(Query::class, $db->from('users')->where(static fn (WhereStatement $w) => $w->where('a')->is(1)));
    assertType('Noirapi\Database\SQL\Where<' . Query::class . '>', $db->from('users')->where(static fn () => null, true));
    assertType(Query::class, $db->from('users')->where('a')->is(1)->andWhere('b')->in([1, 2])->orWhere('c')->isNull());

    assertType('Noirapi\Database\ResultSet<mixed>', $db->from('users')->where('id')->is(1)->select());
    assertType('Noirapi\Database\ResultSet<' . User::class . '>', $db->from('users')->select()->fetchClass(User::class));
    assertType(User::class . '|false', $db->from('users')->select()->fetchClass(User::class)->first());
    assertType('list<' . User::class . '>', $db->from('users')->select()->fetchClass(User::class)->all());
    assertType('stdClass|false', $db->from('users')->select()->fetchObject()->first());
    assertType('list<array<string, mixed>>', $db->from('users')->select()->fetchAssoc()->all());
    assertType('Generator<int, ' . User::class . ', mixed, void>', $db->from('users')->select()->fetchClass(User::class)->lazy());
    assertType('Generator<int, mixed, mixed, void>', $db->from('users')->select()->lazy(\PDO::FETCH_COLUMN));
    assertType('Noirapi\Database\ResultSet<mixed>', $db->from('users')->stream(['a']));
    foreach ($db->from('users')->select()->fetchClass(User::class) as $user) {
        assertType(User::class, $user);
    }

    assertType(Select::class, $db->from('users')->where('a')->is(1)->orderBy('a')->limit(5));
    assertType('Noirapi\Database\ResultSet<mixed>', $db->from('users')->orderBy('a')->limit(5)->offset(1)->select(['a', 'b']));
    assertType('int', $db->from('users')->where('a')->is(1)->count());
    assertType('mixed', $db->from('users')->where('a')->is(1)->max('a'));

    assertType('Noirapi\Database\SQL\Where<' . Update::class . '>', $db->update('users')->where('id'));
    assertType('int', $db->update('users')->where('id')->is(1)->set(['a' => 1]));
    assertType('int', $db->update('users')->where('id')->is(1)->increment('hits'));
    assertType('int', $db->update('users')->where('flags')->hasAnyBits(4)->setBits('flags', 8));
    assertType(Query::class, $db->from('users')->where('flags')->hasAllBits(PHP_INT_MIN)->andWhere('f')->hasNoBits(1));
    assertType('int', $db->from('users')->where('id')->is(1)->delete());
    assertType('bool', $db->insert(['a' => 1])->into('users'));
    assertType(Insert::class, $db->insertMany([['a' => 1], ['a' => 2]]));
    assertType(Insert::class, $db->insert(['a' => 1])->upsert('a', ['b']));
    assertType('bool', $db->insertMany([['a' => 1]])->upsert(['a'])->into('users'));
    assertType('string|false', $db->lastInsertId());

    assertType('Noirapi\Database\SQL\Where<' . Query::class . '>', $db->from('users')->whereExpression(Expression::fromColumn('a')));
    assertType(Query::class, $db->from('users')->orWhereExpression(static fn (Expression $e) => $e->column('a'))->is(null));
    assertType(Query::class, $db->from('users')->where('a')->isNotNull());
    assertType(Expression::class, Expression::fromCall('NOW'));
    assertType(Query::class, $db->from('users')->where('meta->a->b')->is(1)->andWhere('tags')->jsonContains('x')->orWhere('meta->c')->jsonExists());
    assertType(Expression::class, (new Expression())->json('meta', 'a.b[0]'));
    assertType(Expression::class, (new Expression())->ago(4, Interval::Day));
    assertType(Query::class, $db->from('orders')->where('added_on')->atMost(static fn (Expression $e) => $e->ago(4, Interval::Day)));
    assertType(Expression::class, (new Expression())->call('COALESCE', 1)->call('NOW'));
}
