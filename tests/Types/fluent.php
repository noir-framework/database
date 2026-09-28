<?php

declare(strict_types=1);

// Analysed by PHPStan only (never executed): locks in the types apps see from fluent chains.

namespace Noirapi\Database\Test\Types;

use Noirapi\Database\Database;
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

    assertType(Select::class, $db->from('users')->where('a')->is(1)->orderBy('a')->limit(5));
    assertType('Noirapi\Database\ResultSet<mixed>', $db->from('users')->orderBy('a')->limit(5)->offset(1)->select(['a', 'b']));
    assertType('int', $db->from('users')->where('a')->is(1)->count());
    assertType('mixed', $db->from('users')->where('a')->is(1)->max('a'));

    assertType('Noirapi\Database\SQL\Where<' . Update::class . '>', $db->update('users')->where('id'));
    assertType('int', $db->update('users')->where('id')->is(1)->set(['a' => 1]));
    assertType('int', $db->update('users')->where('id')->is(1)->increment('hits'));
    assertType('int', $db->from('users')->where('id')->is(1)->delete());
    assertType('bool', $db->insert(['a' => 1])->into('users'));
}
