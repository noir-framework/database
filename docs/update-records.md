# Updating records

`update($table)` starts an UPDATE, filters narrow it, and `set()` runs it and returns the
number of affected rows.

```php
$affected = $db->update('users')
    ->where('id')->is(2014)
    ->set([
        'email' => 'new@example.com',
        'friends' => fn (Expression $e) => $e->column('friends')->op('+')->value(1),
    ]);
```
```sql
UPDATE `users` SET `email` = 'new@example.com', `friends` = `friends` + 1 WHERE `id` = 2014
```

Without a `where()`, every row of the table is updated.

## Increment and decrement

```php
$db->update('users')->where('id')->is(2014)->increment('friends');          // + 1
$db->update('users')->where('id')->is(2014)->decrement('credits', 5);       // - 5
$db->update('users')->where('id')->is(2014)->increment(['friends', 'unread', 'age' => 5]);
```
```sql
UPDATE `users` SET `credits` = `credits` - 5 WHERE `id` = 2014
UPDATE `users` SET `friends` = `friends` + 1, `unread` = `unread` + 1, `age` = `age` + 5 WHERE `id` = 2014
```

## Updates with joins

```php
$db->update(['users' => 'u'])
    ->join(['plans' => 'p'], fn (Join $join) => $join->on('u.plan_id', 'p.id'))
    ->where('p.name')->is('free')
    ->set(['u.quota' => 10]);
```
```sql
UPDATE `users` AS `u` INNER JOIN `plans` AS `p` ON `u`.`plan_id` = `p`.`id` SET `u`.`quota` = 10 WHERE `p`.`name` = 'free'
```

SQL Server gets the equivalent `UPDATE ... SET ... FROM ... JOIN` form.

## Bit fields and JSON paths

- `setBits('flags', $mask)` and `clearBits('flags', $mask)` change individual bits; see
  [Bit fields](bit-fields.md#updating-bits)
- `set(['meta->address->city' => 'Sofia'])` changes one value inside a JSON document; see
  [JSON columns](json.md#updating-paths)
