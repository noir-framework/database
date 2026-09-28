# Deleting records

`delete()` runs the DELETE and returns the number of deleted rows.

```php
$deleted = $db->from('users')->where('last_login')->lt('2020-01-01')->delete();
```
```sql
DELETE FROM `users` WHERE `last_login` < '2020-01-01'
```

Without a `where()`, every row is deleted.

To delete from several tables at once (MySQL), join them and list the tables:

```php
$db->from('users')
    ->join('orders', fn (Join $join) => $join->on('users.id', 'orders.user_id'))
    ->where('users.id')->is(2014)
    ->delete(['users', 'orders']);
```
```sql
DELETE `users`, `orders` FROM `users` INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id` WHERE `users`.`id` = 2014
```

To empty a table quickly, use `$db->schema()->truncate('users')`.
