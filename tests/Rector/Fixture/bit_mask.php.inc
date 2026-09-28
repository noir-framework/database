<?php

use Noirapi\Database\SQL\Expression;

function bits($db, $user, $pending)
{
    $db->from('entries')
        ->where('parent_id')->is(1)
        ->andWhere(static function (Expression $expression) {
            $expression->column('features')->op('&')->value(Features::$entry_folder);
        }, true)->is(0)
        ->select();

    $db->from('orders')->where(fn (Expression $e) => $e->column('orders.flags')->{'&'}->value($pending), true)->isNot(0)->count();

    // closure typed Expression without `true`: a TypeError at runtime today
    $db->from('orders')->where(function (Expression $expr): void {
        $expr->column('orders.flags')->op('&')->value(OrderFlags::PENDING);
    })->ne(0)->select();

    // not a bit test: left alone
    $db->from('t')->where(fn (Expression $e) => $e->column('a')->op('+')->value(1), true)->is(0)->select();
    $db->from('t')->where(fn (Expression $e) => $e->column('a')->op('&')->value(4), true)->is(4)->select();
}
?>
-----
<?php

use Noirapi\Database\SQL\Expression;

function bits($db, $user, $pending)
{
    $db->from('entries')
        ->where('parent_id')->is(1)->andWhere('features')->hasNoBits(Features::$entry_folder)
        ->select();

    $db->from('orders')->where('orders.flags')->hasAnyBits($pending)->count();

    // closure typed Expression without `true`: a TypeError at runtime today
    $db->from('orders')->where('orders.flags')->hasAnyBits(OrderFlags::PENDING)->select();

    // not a bit test: left alone
    $db->from('t')->where(fn (Expression $e) => $e->column('a')->op('+')->value(1), true)->is(0)->select();
    $db->from('t')->where(fn (Expression $e) => $e->column('a')->op('&')->value(4), true)->is(4)->select();
}
?>
