# Transactions

`transaction()` runs a callback inside a transaction. It commits and returns the callback's
value when the callback succeeds, and rolls back when a `PDOException` is thrown.

```php
use Noirapi\Database\Database;

$orderId = $db->transaction(function (Database $db) use ($cart): string|false {
    $db->insert(['user_id' => $cart->userId, 'total' => $cart->total])->into('orders');
    $orderId = $db->lastInsertId();

    $db->insertMany(array_map(
        fn (Item $item) => ['order_id' => $orderId, 'sku' => $item->sku, 'qty' => $item->qty],
        $cart->items,
    ))->into('order_items');

    return $orderId;
});
```

When the callback is called while a transaction is already open, it runs inside that
transaction, so transactional helpers can call each other.

## Retrying after deadlocks

Under concurrent writes the database may abort a transaction to break a deadlock, or give up
waiting for a lock. Running it again usually succeeds. Pass `attempts` to do that
automatically:

```php
$db->transaction(function (Database $db) use ($orderId): void {
    $db->update('stock')->where('sku')->in($skus)->decrement('qty');
    $db->update('orders')->where('id')->is($orderId)->setBits('flags', OrderFlags::RESERVED);
}, attempts: 3);
```

The transaction is rolled back and run again (after a short, growing, random pause) when it
fails with SQLSTATE `40001` (MySQL deadlock, SQL Server deadlock victim, serialization
failure) or `40P01` (PostgreSQL deadlock), or with MySQL error 1213 / 1205 or SQL Server error
1205. Other errors are not retried. Because the callback may run more than once, it must not
have side effects outside the database (sending mail, calling APIs) before it returns.
`Connection::isRetryable($exception)` exposes the same test.

## On failure

By default a failed transaction is rolled back and `transaction()` returns its second
argument (`null` if none is given):

```php
$result = $db->transaction(fn (Database $db) => $db->insert($row)->into('users'), false);
// false when the insert failed
```

To get the exception instead, enable `throwTransactionExceptions()` on the connection. The
transaction is still rolled back before the exception is re-thrown.

```php
$db->getConnection()->throwTransactionExceptions();
```

Any other exception, such as a business-rule failure thrown from the callback, also rolls the
transaction back and is then always re-thrown:

```php
try {
    $db->transaction(function (Database $db) use ($order): void {
        $db->insert($order)->into('orders');
        if ($order['total'] > $limit) {
            throw new LimitExceeded();          // insert is rolled back
        }
    });
} catch (LimitExceeded $e) {
    // ...
}
```

(Up to 5.0.0-beta2 only `PDOException` rolled back; other exceptions left the transaction
open.)
