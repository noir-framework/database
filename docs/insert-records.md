# Inserting records

## One row

`insert()` takes a `column => value` array; `into()` names the table, runs the statement and
returns `true` on success.

```php
$db->insert(['name' => 'John Doe', 'email' => 'john@example.com'])->into('users');
```
```sql
INSERT INTO `users` (`name`, `email`) VALUES ('John Doe', 'john@example.com')
```

A closure value becomes an [expression](expressions.md):

```php
$db->insert(['name' => 'Ann', 'created_at' => fn (Expression $e) => $e->now()])->into('users');
```
```sql
INSERT INTO `users` (`name`, `created_at`) VALUES ('Ann', NOW())
```

Read the generated key with `$db->lastInsertId()`; see [Connections](connections.md#last-insert-id).

## Several rows

`insertMany()` sends all rows in one multi-row statement, which is much faster than a loop of
single inserts:

```php
$db->insertMany([
    ['name' => 'Ann', 'age' => 30],
    ['age' => 25, 'name' => 'Bob'],          // key order does not matter
    ['name' => 'Cid', 'age' => null],        // missing values must be given as null
])->into('users');
```
```sql
INSERT INTO `users` (`name`, `age`) VALUES ('Ann', 30), ('Bob', 25), ('Cid', NULL)
```

Every row must have exactly the columns of the first row. A row with a missing or an extra
column throws an `InvalidArgumentException` naming the row, so nothing is dropped or turned
into NULL silently.

`insertMany()` can be called several times on the same statement to append rows, and after
`insert()`, whose columns the extra rows then follow. Calling `insert()` after
`insertMany()` throws a `LogicException`.

Databases limit how many values one statement can bind: 65,535 on MySQL and PostgreSQL,
2,100 on SQL Server, 999 on older SQLite. When a batch exceeds the limit, `into()` splits it
into several statements and runs them in one transaction, so either every row is inserted or
none is. Inside a transaction you opened, the statements join it. MySQL's
`max_allowed_packet` still limits the size of one statement, so for very wide rows split huge
imports yourself.

## Upserts: insert or update on duplicate keys

`upsert($keys, $update = null)` makes the insert update the existing row when a row would
violate a unique or primary key:

```php
$db->insert(['page' => '/', 'hits' => 1, 'title' => 'Home'])
    ->upsert('page', [
        'title',                                                             // use the inserted value
        'hits' => fn (Expression $e) => $e->column('stats.hits')->op('+')->value(1), // explicit value
    ])
    ->into('stats');
```
```sql
INSERT INTO `stats` (`page`, `hits`, `title`) VALUES ('/', 1, 'Home')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `hits` = `stats`.`hits` + 1
```

`$keys` is the unique or primary key column (or list of columns) that detects the duplicate.
PostgreSQL, SQLite and SQL Server need it; MySQL reacts to any unique key.

`$update` says what to change on the existing row:

| `$update` | Effect |
|---|---|
| `null` (default) | every inserted column except the keys gets the inserted value |
| `['a', 'b']` | these columns get the inserted value |
| `['a' => $value]` | this column gets a value, expression or closure |
| `[]` | keep the existing row unchanged (insert-or-ignore) |

Names and `column => value` pairs can be mixed. Inside an expression, qualify the existing
row's columns with the table name (`stats.hits`); PostgreSQL and SQL Server reject a bare
`hits` as ambiguous.

It works with `insertMany()` too:

```php
$db->insertMany($rows)->upsert('id')->into('users');      // update all non-key columns
$db->insertMany($rows)->upsert('id', [])->into('users');  // skip rows that already exist
```

Each database gets its own form:

```sql
-- MySQL / MariaDB
INSERT INTO `users` (`id`, `name`) VALUES (1, 'Ann'), (2, 'Bob') ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)
-- PostgreSQL, SQLite 3.24+
INSERT INTO "users" ("id", "name") VALUES (1, 'Ann'), (2, 'Bob') ON CONFLICT ("id") DO UPDATE SET "name" = excluded."name"
-- SQL Server
MERGE INTO [users] WITH (HOLDLOCK) USING (VALUES (1, 'Ann'), (2, 'Bob')) AS [excluded] ([id], [name])
    ON [users].[id] = [excluded].[id]
    WHEN MATCHED THEN UPDATE SET [name] = [excluded].[name]
    WHEN NOT MATCHED THEN INSERT ([id], [name]) VALUES ([excluded].[id], [excluded].[name]);
```

Notes:

- On MySQL 8.0.19 and newer, the inserted row is referenced through a row alias,
  `... VALUES (...) AS `excluded` ON DUPLICATE KEY UPDATE name = excluded.name`. Older MySQL
  versions and MariaDB (which has no row alias) get `VALUES(col)`, which MySQL 8.0.20+ would
  log as deprecated. The connection checks the server version once, when it creates the
  compiler.
- For "do nothing", MySQL gets `ON DUPLICATE KEY UPDATE key = key` rather than `INSERT IGNORE`,
  which would also silence unrelated errors such as truncation.
- `HOLDLOCK` keeps two concurrent SQL Server upserts from inserting the same key.
