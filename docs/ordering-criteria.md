# Ordering

`orderBy($columns, $order = 'ASC', $nulls = null)` adds an `ORDER BY` criterion. Call it again
for further criteria; a list of columns shares one direction.

```php
$db->from('users')
    ->orderBy(['name', 'age'])
    ->orderBy('created_at', 'desc')
    ->select();
```
```sql
SELECT * FROM `users` ORDER BY `name`, `age` ASC, `created_at` DESC
```

## Where NULLs go

The third argument, `'nulls first'` or `'nulls last'`, places NULL values explicitly. It
compiles to a `CASE` expression, so it works on every database, including MySQL, which has no
`NULLS FIRST` syntax:

```php
$db->from('users')->orderBy('last_login', 'desc', 'nulls last')->select();
```
```sql
SELECT * FROM `users` ORDER BY (CASE WHEN `last_login` IS NULL THEN 1 ELSE 0 END), `last_login` DESC
```

Columns can also be expressions or [JSON paths](json.md): `orderBy('meta->rank', 'desc')`.
