# Fields selection

Besides a list of column names, `select()` accepts a closure that receives a
`ColumnExpression`. Use it to mix plain columns, aggregates, functions and computed values in
one query, instead of running a query per value.

```php
use Noirapi\Database\SQL\ColumnExpression;

$stats = $db->from('users')
    ->select(function (ColumnExpression $include): void {
        $include->count('*', 'total')
            ->min('age', 'youngest')
            ->max('age', 'oldest')
            ->avg('wallet', 'avg_wallet');
    })
    ->first();
```
```sql
SELECT COUNT(*) AS `total`, MIN(`age`) AS `youngest`, MAX(`age`) AS `oldest`, AVG(`wallet`) AS `avg_wallet` FROM `users`
```

## Columns

`column($name, $alias = null)` adds one column; `columns([...])` adds several, and a string
key sets the alias:

```php
$db->from('users')->select(function (ColumnExpression $include): void {
    $include->column('name', 'n')->columns(['email', 'age' => 'a']);
});
```
```sql
SELECT `name` AS `n`, `email`, `age` AS `a` FROM `users`
```

## Aggregates

`count()`, `sum()`, `avg()`, `min()` and `max()` take the column, an optional alias and a
`$distinct` flag:

```php
$include->count('*', 'total')
    ->count('email', 'emails', true)
    ->max('age', 'oldest')
    ->min('age')
    ->avg('wallet', 'avg_wallet')
    ->sum('wallet', null, true);
```
```sql
SELECT COUNT(*) AS `total`, COUNT(DISTINCT `email`) AS `emails`, MAX(`age`) AS `oldest`,
MIN(`age`), AVG(`wallet`) AS `avg_wallet`, SUM(DISTINCT `wallet`) FROM `users`
```

`count()` also takes a list of columns (`count(['a', 'b'])`), which always counts distinct
combinations. To count rows without selecting them, use the
[aggregate shortcuts](aggregate-functions.md).

## Functions

| Method | MySQL | PostgreSQL / SQLite | SQL Server |
|---|---|---|---|
| `ucase($col, $alias)` | `UCASE()` | `UPPER()` | `UPPER()` |
| `lcase($col, $alias)` | `LCASE()` | `LOWER()` | `LOWER()` |
| `mid($col, $start, $alias, $length)` | `MID()` | `SUBSTR()` | `SUBSTRING()` |
| `len($col, $alias)` | `LENGTH()` | `LENGTH()` | `LEN()` |
| `format($col, $decimals, $alias)` | `FORMAT()` | `FORMAT()`¹ | `FORMAT()` |
| `round($col, $decimals, $alias)` | `FORMAT()`² | `ROUND()` | `ROUND()` |
| `now($alias)` | `NOW()` | `NOW()` / `datetime('now')` | `GETDATE()` |

¹ PostgreSQL and SQLite have no `FORMAT(number, decimals)`; use `round()` there.
² Kept from opis/database for identical output: MySQL's `round()` is emitted as `FORMAT()`,
which returns a formatted string.

`mid()` counts from 1; a `$length` of 0 means "to the end of the string".

```php
$include->ucase('name', 'upper')->len('name', 'length')->mid('name', 3, 'part')->now('t');
```
```sql
SELECT UCASE(`name`) AS `upper`, LENGTH(`name`) AS `length`, MID(`name`, 3) AS `part`, NOW() AS `t` FROM `users`
```

## Computed columns

`column()` also accepts a closure building an [expression](expressions.md), including calls
to any SQL function:

```php
use Noirapi\Database\SQL\Expression;

$include->column(fn (Expression $e) => $e->column('price')->op('*')->column('qty'), 'total')
    ->column(fn (Expression $e) => $e->call('COALESCE', Expression::fromColumn('nick'), 'anonymous'), 'display');
```
```sql
SELECT `price` * `qty` AS `total`, COALESCE(`nick`, 'anonymous') AS `display` FROM `users`
```

The array form works too: in `select(['total' => fn (Expression $e) => ...])` the key is the
alias when the value is an expression.

JSON paths can be selected directly, `select(['meta->address->city' => 'city'])`; see
[JSON columns](json.md).
