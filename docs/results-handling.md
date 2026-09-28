# Results handling

`select()` runs the query and returns a `Noirapi\Database\ResultSet`, a cursor over the rows.
Pick a fetch mode first (optional), then read the rows.

## Reading rows

| Method | Returns |
|---|---|
| `all()` | every row as a list (`[]` when empty) |
| `first()` | the first row, or `false`; closes the cursor |
| `next()` | the next row, or `false` at the end |
| `column($index = 0)` | a column of the next row, or `false` at the end |
| `lazy()` / `foreach` | the rows one at a time, see [below](#iterating-lazily) |
| `allGroup($unique = false)` | rows grouped by the first column (`PDO::FETCH_GROUP`) |
| `count()` | number of rows affected (`PDOStatement::rowCount()`) |
| `flush()` | closes the cursor without reading the remaining rows |

## Fetch modes

Rows are `stdClass` objects by default. Each of these methods changes the mode and returns
the same result set, so it can be chained:

| Method | Row type |
|---|---|
| `fetchObject()` | `stdClass` (the default) |
| `fetchAssoc()` | `array<string, mixed>` |
| `fetchNum()` | `list<mixed>` |
| `fetchBoth()` | array indexed by name and by position |
| `fetchNamed()` | like `fetchAssoc()`, but repeated column names become arrays |
| `fetchKeyPair()` | first column => second column (`all()` gives one array) |
| `fetchClass(Foo::class, $ctorArgs)` | instances of `Foo`; columns are written to its public properties |
| `fetchCustom(fn (PDOStatement $s) => ...)` | whatever you configure on the statement |

```php
final class User
{
    public int $id;
    public string $name;
}

$users = $db->from('users')->select(['id', 'name'])->fetchClass(User::class)->all();
```

`ResultSet` is generic over its row type, so PHPStan and Psalm see `$users` as `list<User>`
and `->first()` as `User|false`, with no `@var` casts needed.

## Converting column values

Databases return most values as strings (JSON documents, booleans stored as `TINYINT`, dates,
and large `UNSIGNED BIGINT` values). `cast()` converts named columns as rows are read:

```php
$users = $db->from('users')
    ->select(['id', 'name', 'meta', 'vip', 'seen'])
    ->fetchClass(User::class)
    ->cast(['meta' => 'json', 'vip' => 'bool', 'seen' => 'datetime'])
    ->all();
```

| Cast | Result |
|---|---|
| `int`, `float`, `bool`, `string` | the PHP scalar |
| `json` | `json_decode($value, true)` (throws `JsonException` on invalid JSON) |
| `datetime` | `DateTimeImmutable` |
| a closure | whatever it returns: `fn (mixed $v) => Money::fromCents((int) $v)` |

`NULL` stays `NULL`. Casts apply to `all()`, `first()`, `next()` and iteration, in every fetch
mode that has column names (objects, arrays, classes).

With `fetchClass()`, the row is converted before the object is built, so typed properties such
as `public array $meta` or `public bool $vip` work; plain `fetchClass()` would hand them the raw
strings and fail with a `TypeError`. As with PDO, properties are set first and then the
constructor runs; columns without a declared property are skipped.

## Mapping rows with a callback

`all()` and `first()` take a callback that receives each row's columns as arguments:

```php
$users = $db->from('users')
    ->select(['name', 'email'])
    ->all(fn (string $name, string $email) => new Contact($name, $email));
```

With `first()`, the columns are passed as named arguments, so the parameter names must match
the column names.

## Iterating lazily

`all()` builds an array of every row. For large results, iterate instead: each row is
hydrated when it is reached and can be released before the next one.

```php
foreach ($db->from('orders')->select()->fetchClass(Order::class) as $order) {
    process($order);                          // Order, one at a time
}
```

A `ResultSet` is iterable (`IteratorAggregate`), and `lazy()` returns the underlying
generator, optionally with a different PDO fetch style:

```php
foreach ($db->from('users')->select('email')->lazy(PDO::FETCH_COLUMN) as $email) {
    send($email);
}
```

The cursor is closed when the loop finishes or when you `break` out of it. Iteration stops
only at the end of the rows, so a row whose value is `'0'` or `0` does not end it early.

## Streaming large results

On MySQL and MariaDB, PDO buffers the whole result in the client by default, so even a lazy
loop holds every row in driver memory. `stream()` runs the query unbuffered: rows arrive from
the server as you iterate, so memory stays flat for any result size.

```php
foreach ($db->from('events')->where('day')->is($day)->stream(['id', 'payload'])->fetchAssoc() as $row) {
    export($row);
}
```

Until the stream is fully read (or the loop exits and the result set is released), that
connection cannot run another query: MySQL answers with error 2014, "Cannot execute queries
while other unbuffered queries are active". Finish the loop first, or use a second
`Connection` for queries inside it.

`stream()` exists on queries (`$db->from(...)->stream()`) and on the connection
(`$connection->stream($sql, $params)`). On other drivers it behaves like `select()`.
