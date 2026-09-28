# Bit fields

For integer columns used as sets of flags (`flags`, `features`, ...), with masks written as
PHP integers such as class constants.

## Testing bits

| Method | True when | SQL |
|---|---|---|
| `hasAllBits($mask)` | every bit of the mask is set | `(~col & mask) = 0` |
| `hasAnyBits($mask)` | at least one bit of the mask is set | `(col & mask) != 0` |
| `hasNoBits($mask)` | no bit of the mask is set | `(col & mask) = 0` |

```php
final class Features
{
    public const int FOLDER = 1 << 0;
    public const int SHARED = 1 << 1;
    public const int DELETED = 1 << 3;
}

final class OrderFlags
{
    public const int PAID = 1 << 0;
    public const int SHIPPED = 1 << 1;
    public const int PENDING = 1 << 2;
    public const int REFUNDED = 1 << 3;
    public const int DISPUTED = 1 << 4;
}

$db->from('entries')
    ->where('user_id')->is(5)
    ->andWhere('features')->hasNoBits(Features::DELETED)
    ->select('id');

$db->from('orders')
    ->where('flags')->hasAllBits(OrderFlags::PAID | OrderFlags::SHIPPED)
    ->orWhere('flags')->hasAnyBits(OrderFlags::REFUNDED | OrderFlags::DISPUTED)
    ->select('id');
```
```sql
SELECT `id` FROM `entries` WHERE `user_id` = 5 AND (`features` & 8) = 0
SELECT `id` FROM `orders` WHERE (~`flags` & 3) = 0 OR (`flags` & 24) != 0
```

This replaces the hand-built expression used before:

```php
// before
->andWhere(fn (Expression $e) => $e->column('features')->op('&')->value(Features::DELETED), true)->is(0)
// now
->andWhere('features')->hasNoBits(Features::DELETED)
```

## Updating bits

```php
$db->update('orders')->where('id')->is(9)->setBits('flags', OrderFlags::PENDING);   // flags | 4
$db->update('orders')->where('id')->is(9)->clearBits('flags', OrderFlags::PENDING); // flags & ~4
```
```sql
UPDATE `orders` SET `flags` = `flags` | 4 WHERE `id` = 9
UPDATE `orders` SET `flags` = (`flags` & ~4) WHERE `id` = 9
```

Both run the update and return the number of affected rows. To set and clear bits in one
statement, or to combine them with other columns, use `Expression::bits()` in `set()`:

```php
$db->update('orders')->where('id')->is(9)->set([
    'flags' => fn (Expression $e) => $e->bits('flags', set: OrderFlags::SHIPPED, clear: OrderFlags::PAID | OrderFlags::PENDING),
    'shipped_at' => fn (Expression $e) => $e->now(),
]);
```
```sql
UPDATE `orders` SET `flags` = (`flags` & ~5) | 2, `shipped_at` = NOW() WHERE `id` = 9
```

## 64-bit flags and bit 63

PHP integers are signed 64-bit, so bit 63 is `PHP_INT_MIN` (`1 << 63`). The helpers are
correct for it on every database and for both signed and `UNSIGNED BIGINT` columns: the
"all bits" test uses the complement form `(~col & mask) = 0` because it stays correct where
`(col & mask) = mask` does not. (MySQL's bit operators return unsigned values, which never
equal a negative PHP mask.)

One MySQL / MariaDB case needs a hint. When updating a **signed** `BIGINT` whose bit 63 is or
becomes set (a negative number), the bit operation yields an unsigned value that no longer
fits the column, and MySQL fails with "Out of range value". Pass `signed: true` to cast the
result back:

```php
$db->update('orders')->where('id')->is(9)->setBits('flags', PHP_INT_MIN, signed: true);
$e->bits('flags', set: $a, clear: $b, signed: true);
```
```sql
UPDATE `orders` SET `flags` = CAST(`flags` | -9223372036854775808 AS SIGNED) WHERE `id` = 9
```

Without the flag the update fails loudly rather than storing a wrong value. `UNSIGNED`
columns, and PostgreSQL, SQLite and SQL Server (signed integers only), never need it.

Reading an `UNSIGNED BIGINT` with bit 63 set gives a string (for example
`"9223372036854775820"`), because the value exceeds `PHP_INT_MAX`.
