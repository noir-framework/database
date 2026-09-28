<?php

use Noirapi\Database\SQL\Expression;

function raw($db, $ip)
{
    $db->from('services')->select(function ($include) {
        $include->column(function (Expression $expr) {
            $expr->op('INET6_NTOA(')->column('ip')->op(')');
        }, 'ip');
    });

    $db->from('hosts')->where('ip')->is(fn (Expression $e) => $e->op('INET6_ATON(')->value($ip)->op(')'))->select();
    $db->from('users')->where('name')->is(fn (Expression $e) => $e->op('TRIM(')->column('nick')->op(')'))->select();
    $db->from('users')->where('h')->is(fn (Expression $e) => $e->op('SHA2(')->value('x')->op(')'))->select();

    // more than one argument: left alone
    $db->from('t')->where(fn (Expression $e) => $e->op('MATCH(')->column('a')->op(') AGAINST(')->value('x')->op(')'), true)->nop();
}
?>
-----
<?php

use Noirapi\Database\SQL\Expression;

function raw($db, $ip)
{
    $db->from('services')->select(function ($include) {
        $include->column(function (Expression $expr) {
            $expr->inet6Ntoa('ip');
        }, 'ip');
    });

    $db->from('hosts')->where('ip')->is(fn (Expression $e) => $e->inet6Aton($ip))->select();
    $db->from('users')->where('name')->is(fn (Expression $e) => $e->call('TRIM', \Noirapi\Database\SQL\Expression::fromColumn('nick')))->select();
    $db->from('users')->where('h')->is(fn (Expression $e) => $e->call('SHA2', 'x'))->select();

    // more than one argument: left alone
    $db->from('t')->where(fn (Expression $e) => $e->op('MATCH(')->column('a')->op(') AGAINST(')->value('x')->op(')'), true)->nop();
}
?>
