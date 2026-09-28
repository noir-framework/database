# Schema

`$db->schema()` returns the schema builder for the connection: introspection, DDL and views.

```php
$schema = $db->schema();
```

Pages: [creating tables](schema-creating-tables.md), [modifying tables](schema-modifying-tables.md).

## Tables

```php
$schema->getTables();                // ['users' => 'users', 'order_items' => 'Order_Items', ...]
$schema->hasTable('users');          // case-insensitive
```

`getTables()` returns lower-case name => actual name and is cached. Pass `true`
(`getTables(true)`, `hasTable('users', true)`) to reload the list. `create()`, `drop()` and
`renameTable()` refresh it automatically. Views are not included.

## Columns

```php
$schema->getColumns('users');                     // ['id', 'name', 'email']
$schema->getColumns('users', false, false);       // ['id' => ['name' => 'id', 'type' => 'int(11)'], ...]
```

Returns `false` when the table does not exist. The second argument reloads the cached list;
the third one, `false`, returns name => `['name', 'type']` instead of just names.

## Views

```php
use Noirapi\Database\SQL\SelectStatement;

$schema->createView('active_users', 'users', function (SelectStatement $query): void {
    $query->where('active')->is(1)->andWhere('deleted_at')->isNull()->select(['id', 'name', 'email']);
});

$schema->hasView('active_users');       // true
$schema->getViews();                    // ['active_users' => 'active_users']
$db->from('active_users')->select()->all();

$schema->dropView('active_users');
```
```sql
CREATE VIEW `active_users` AS SELECT `id`, `name`, `email` FROM `users` WHERE `active` = 1 AND `deleted_at` IS NULL
```

The callback builds the view's query with the usual builder: filters, joins, grouping and
columns. Databases do not accept bound parameters in `CREATE VIEW`, so the query's values are
written into the statement, quoted with the driver's own `PDO::quote()`. That is safe for
strings containing quotes or backslashes, but values that have no SQL literal form, such as
stream resources or objects, throw an `InvalidArgumentException`.

`getViews()` and `hasView()` are cached like tables; `createView()` and `dropView()` refresh
the cache.

## Other operations

```php
$schema->renameTable('user', 'users');
$schema->truncate('logs');
$schema->drop('tmp');
$schema->getCurrentDatabase();          // database (MySQL), schema (PostgreSQL, SQL Server) or file (SQLite)
```

All DDL methods return `void` and throw a `PDOException` on failure.
