# Joins

| Method | SQL |
|---|---|
| `join($table, $closure)` | `INNER JOIN` |
| `leftJoin($table, $closure)` | `LEFT JOIN` |
| `rightJoin($table, $closure)` | `RIGHT JOIN` |
| `fullJoin($table, $closure)` | `FULL JOIN` (not supported by MySQL itself) |
| `crossJoin($table)` | `CROSS JOIN` |

The closure receives a `Join` and declares the `ON` conditions with `on($column1, $column2,
$operator = '=')`, `andOn()` and `orOn()`. The operator can be `=`, `!=`, `<`, `>`, `<=` or
`>=`.

```php
use Noirapi\Database\SQL\Join;

$db->from('users')
    ->join('profiles', function (Join $join): void {
        $join->on('users.id', 'profiles.user_id');
    })
    ->select();
```
```sql
SELECT * FROM `users` INNER JOIN `profiles` ON `users`.`id` = `profiles`.`user_id`
```

## Several conditions

```php
$db->from('users')
    ->leftJoin('profiles', function (Join $join): void {
        $join->on('users.id', 'profiles.user_id')
            ->andOn('users.email', 'profiles.primary_email')
            ->orOn('users.email', 'profiles.secondary_email');
    })
    ->select();
```
```sql
SELECT * FROM `users` LEFT JOIN `profiles` ON `users`.`id` = `profiles`.`user_id`
AND `users`.`email` = `profiles`.`primary_email` OR `users`.`email` = `profiles`.`secondary_email`
```

Passing a closure to `on()`, `andOn()` or `orOn()` groups conditions in parentheses:

```php
$join->on('users.id', 'profiles.user_id')
    ->andOn(function (Join $group): void {
        $group->on('users.email', 'profiles.primary_email')
            ->orOn('users.email', 'profiles.secondary_email');
    });
```
```sql
ON `users`.`id` = `profiles`.`user_id` AND (`users`.`email` = `profiles`.`primary_email`
OR `users`.`email` = `profiles`.`secondary_email`)
```

`on()` needs both columns; calling it with one throws an `InvalidArgumentException` (4.x
generated invalid SQL).

## Aliases

A `table => alias` array aliases the joined table:

```php
$db->from(['users' => 'u'])
    ->join(['profiles' => 'p'], fn (Join $join) => $join->on('u.id', 'p.user_id'))
    ->select(['u.name', 'p.bio']);
```
```sql
SELECT `u`.`name`, `p`.`bio` FROM `users` AS `u` INNER JOIN `profiles` AS `p` ON `u`.`id` = `p`.`user_id`
```

## Cross joins

```php
$db->from('sizes')->crossJoin('colors')->select();
```
```sql
SELECT * FROM `sizes` CROSS JOIN `colors`
```
