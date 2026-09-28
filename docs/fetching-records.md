# Fetching records

Start a query with `from()`, then run it with `select()`, which returns a
[`ResultSet`](results-handling.md).

```php
$users = $db->from('users')->select()->all();
```
```sql
SELECT * FROM `users`
```

`all()` returns every row, or `[]` when there are none. By default rows are `stdClass` objects
with one property per column:

```php
foreach ($users as $user) {
    echo $user->name;
}
```

## One row

`first()` returns the first row, or `false` when the query matched nothing:

```php
$user = $db->from('users')->where('id')->is(42)->select()->first();

if ($user === false) {
    // not found
}
```

## One value

`column()` runs the query and returns the first column of the first row, or `false` when
there are no rows:

```php
$email = $db->from('users')->where('id')->is(42)->column('email');
```
```sql
SELECT `email` FROM `users` WHERE `id` = 42
```

Always add a [filter](filters.md) (or a [limit](limits-and-offsets.md)) before `first()` or
`column()`: the database still has to produce every matching row even though only one is read.

## Choosing columns

Pass a column name, or a list of names, to `select()`. A string key gives the column an alias:

```php
$users = $db->from('users')
    ->select(['name' => 'n', 'email', 'age' => 'a'])
    ->all();

echo $users[0]->n;
```
```sql
SELECT `name` AS `n`, `email`, `age` AS `a` FROM `users`
```

For aggregates, functions and computed columns, pass a closure instead; see
[Fields selection](fields-selection.md).

## DISTINCT

```php
$db->from('users')->distinct()->select('country')->all();
```
```sql
SELECT DISTINCT `country` FROM `users`
```

## Several tables

`from()` takes a list of tables, and string keys become table aliases:

```php
$db->from(['users' => 'u', 'profiles' => 'p'])
    ->where('u.id')->eq('p.user_id', true)
    ->select()
    ->all();
```
```sql
SELECT * FROM `users` AS `u`, `profiles` AS `p` WHERE `u`.`id` = `p`.`user_id`
```

For explicit joins, see [Joins](joins.md).

## SELECT ... INTO

```php
$db->from('users')->where('active')->is(1)->into('users_backup')->select();
```
```sql
SELECT * INTO `users_backup` FROM `users` WHERE `active` = 1
```

`into($table, $database)` adds `IN <database>`. This is the SQL Server / Access form; MySQL
and PostgreSQL use `INSERT INTO ... SELECT` instead.
