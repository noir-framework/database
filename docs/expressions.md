# Expressions

An `Expression` is a small SQL fragment built from tokens. Wherever the builder takes a value
or a column, it also takes a closure receiving an `Expression`:

```php
use Noirapi\Database\SQL\Expression;

$db->from('numbers')
    ->where('c')->eq(fn (Expression $e) => $e->column('a')->op('+')->column('b')->op('*')->value(2))
    ->select();
```
```sql
SELECT * FROM `numbers` WHERE `c` = `a` + `b` * 2
```

## Building blocks

| Method | Adds |
|---|---|
| `column('name')` | a quoted column (`table.column` and [JSON paths](json.md) work) |
| `value($v)` | a bound parameter |
| `op('+')` | raw SQL text, never escaped: do not pass user input |
| `$e->{'+'}` | same as `op('+')` |
| `group(fn (Expression $g) => ...)` | a parenthesized sub-expression |
| `from('table')` | a sub-query; continue with `where()`, `select()` ... |
| `call('FUNC', ...$args)` | a function call, see below |

```php
$db->from('t')
    ->where('total')->gt(fn (Expression $e) => $e->group(fn (Expression $g) => $g->column('a')->op('+')->column('b'))->op('*')->value(2))
    ->select();
```
```sql
SELECT * FROM `t` WHERE `total` > (`a` + `b`) * 2
```

Sub-queries can be used as values or as selected columns:

```php
$db->from('users')->where('age')->gt(fn (Expression $e) => $e->from('users')->select(fn ($c) => $c->avg('age')))->select();

$db->from('users')->select([
    'name',
    'orders' => fn (Expression $e) => $e->from('orders')->where('orders.user_id')->eq('users.id', true)->select(fn ($c) => $c->count()),
]);
```
```sql
SELECT * FROM `users` WHERE `age` > (SELECT AVG(`age`) FROM `users`)
SELECT `name`, (SELECT COUNT(*) FROM `orders` WHERE `orders`.`user_id` = `users`.`id`) AS `orders` FROM `users`
```

## Calling SQL functions

`call($name, ...$args)` calls any function. Plain arguments are bound as parameters;
expressions, and closures building one, are inlined. To pass a column, use
`Expression::fromColumn()` or `fn (Expression $e) => $e->column(...)`:

```php
$db->from('users')->where('name')->is(fn (Expression $e) => $e->call('TRIM', Expression::fromColumn('nick')))->select();
```
```sql
SELECT * FROM `users` WHERE `name` = TRIM(`nick`)
```

Any method that `Expression` does not define is treated as a function call, so the same can
be written as `$e->TRIM(...)` or `$e->COALESCE(...)`:

```php
$db->from('users')
    ->whereExpression(fn (Expression $e) => $e->COALESCE(Expression::fromColumn('nick'), Expression::fromColumn('name'), 'anon'))
    ->like('A%')
    ->select();
```
```sql
SELECT * FROM `users` WHERE COALESCE(`nick`, `name`, 'anon') LIKE 'A%'
```

PHPStan reports these magic calls as undefined methods, so prefer `call()` in code it
analyses. Function names must be plain identifiers (`[A-Za-z_][A-Za-z0-9_]*`, dotted for
schema-qualified names); anything else throws an `InvalidArgumentException`, so a name taken
from input cannot inject SQL.

Two static constructors are handy for arguments:

```php
Expression::fromColumn('users.name');          // `users`.`name`
Expression::fromCall('NOW');                   // NOW()
Expression::fromClosure(fn (Expression $e) => $e->column('a')->op('*')->value(2));
```

## Built-in functions

These compile to the right function for each database:

| Method | Notes |
|---|---|
| `ucase()`, `lcase()`, `mid()`, `len()`, `round()`, `format()` | see [Fields selection](fields-selection.md#functions) |
| `count()`, `sum()`, `avg()`, `min()`, `max()` | aggregates |
| `now()`, `currentDate()`, `dateAdd()`, `dateSub()`, `ago()`, `fromNow()` | see [Date and time](date-and-time.md) |
| `inet6Aton()`, `inet6Ntoa()`, `inetAton()`, `inetNtoa()` | MySQL / MariaDB only, see below |
| `json()` | see [JSON columns](json.md) |
| `bits()` | see [Bit fields](bit-fields.md) |

## IP addresses (MySQL / MariaDB)

`inet6Aton($value)` converts an IPv4 or IPv6 address to its 16-byte binary form
(`VARBINARY(16)`), and `inet6Ntoa($column)` converts it back. `inetAton()` and `inetNtoa()` do
the same for IPv4 integers.

```php
$db->insert(['host' => 'gw', 'ip' => fn (Expression $e) => $e->inet6Aton('2001:db8::1')])->into('hosts');

$db->from('hosts')
    ->where('ip')->is(fn (Expression $e) => $e->inet6Aton($address))
    ->select(['host', 'ip' => fn (Expression $e) => $e->inet6Ntoa('ip')]);
```
```sql
SELECT `host`, INET6_NTOA(`ip`) AS `ip` FROM `hosts` WHERE `ip` = INET6_ATON('2001:db8::1')
```

`inet6Aton()` takes a value (bound as a parameter) and `inet6Ntoa()` takes a column. Other
databases have no equivalent, so these helpers throw a `LogicException` there.

## Expressions are write-only

`Expression` reads undefined properties as operators (`$e->{'+'}`), so writing a property is
always a mistake and throws a `LogicException`.
