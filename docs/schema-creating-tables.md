# Creating tables

```php
use Noirapi\Database\Schema\CreateTable;

$db->schema()->create('users', function (CreateTable $table): void {
    $table->integer('id')->autoincrement();
    $table->string('email', 128)->notNull()->unique();
    $table->string('role', 32)->defaultValue('user');
    $table->integer('age')->size('small')->unsigned();
    $table->decimal('balance', 12, 2);
    $table->json('meta');
    $table->timestamps();
    $table->index(['role', 'age']);
});
```
```sql
CREATE TABLE `users`(
`id` INT AUTO_INCREMENT,
`email` VARCHAR(128) NOT NULL,
`role` VARCHAR(32) DEFAULT 'user',
`age` SMALLINT UNSIGNED,
`balance` DECIMAL(12, 2),
`meta` JSON,
`created_at` DATETIME NOT NULL,
`updated_at` DATETIME,
CONSTRAINT `users_pk_id` PRIMARY KEY (`id`),
CONSTRAINT `users_uk_email` UNIQUE (`email`)
)
CREATE INDEX `users_ik_role_age` ON `users`(`role`, `age`)
```

## Column types

| Method | MySQL | PostgreSQL | SQLite | SQL Server |
|---|---|---|---|---|
| `integer($name)` | `INT` (see sizes) | `INTEGER` | `INTEGER` | `INTEGER` |
| `float($name)` | `FLOAT` | `REAL` | `FLOAT` | `FLOAT` |
| `double($name)` | `DOUBLE` | `DOUBLE PRECISION` | `DOUBLE` | `FLOAT(53)`¹ |
| `decimal($name, $length, $precision)` | `DECIMAL(l, p)` | `DECIMAL (l, p)` | `DECIMAL` | `DECIMAL (l, p)` |
| `boolean($name)` | `TINYINT(1)` | `BOOLEAN` | `BOOLEAN` | `BIT` |
| `string($name, $length = 255)` | `VARCHAR(n)` | `VARCHAR(n)` | `VARCHAR(n)` | `NVARCHAR(n)` |
| `fixed($name, $length = 255)` | `CHAR(n)` | `CHAR(n)` | `CHAR(n)` | `NCHAR(n)` |
| `text($name)` | `TEXT` (see sizes) | `TEXT` | `TEXT` | `NVARCHAR(max)` |
| `binary($name)` | `BLOB` (see sizes) | `BYTEA` | `BLOB` | `VARBINARY(max)` |
| `json($name)` | `JSON` | `JSON` | `TEXT` | `NVARCHAR(max)` |
| `date($name)` | `DATE` | `DATE` | `DATE` | `DATE` |
| `time($name)` | `TIME` | `TIME(0) WITHOUT TIME ZONE` | `DATETIME` | `TIME` |
| `dateTime($name)` | `DATETIME` | `TIMESTAMP(0) WITHOUT TIME ZONE` | `DATETIME` | `DATETIME` |
| `timestamp($name)` | `TIMESTAMP` | `TIMESTAMP(0) WITHOUT TIME ZONE` | `DATETIME` | `DATETIME` |

¹ SQL Server has no `DOUBLE` type, and opis/database emitted it anyway. `FLOAT(53)` is SQL Server's
double precision (its `FLOAT` default is also 53 bits).

`json()` is `TEXT` on SQLite on purpose: a column declared `JSON` would get numeric affinity
there and store `'1'` as the number `1`. On MariaDB, `JSON` is an alias of `LONGTEXT` with a
validity check.

Shortcuts: `timestamps($created = 'created_at', $updated = 'updated_at')` adds a `NOT NULL`
creation time and a nullable update time; `softDelete($column = 'deleted_at')` adds a nullable
`dateTime`.

### Sizes

`size()` applies to `integer`, `text` and `binary` columns on MySQL (the other databases
ignore it except for integers):

| Size | `integer` | `text` | `binary` |
|---|---|---|---|
| `tiny` | `TINYINT` | `TINYTEXT` | `TINYBLOB` |
| `small` | `SMALLINT` | `TINYTEXT` | `TINYBLOB` |
| `normal` (default) | `INT` | `TEXT` | `BLOB` |
| `medium` | `MEDIUMINT` | `MEDIUMTEXT` | `MEDIUMBLOB` |
| `big` | `BIGINT` | `LONGTEXT` | `LONGBLOB` |

PostgreSQL maps integer sizes to `SMALLINT` / `INTEGER` / `BIGINT` (and to `SMALLSERIAL` /
`SERIAL` / `BIGSERIAL` when auto-incrementing).

## Column modifiers

| Method | Effect |
|---|---|
| `notNull()` | `NOT NULL` (columns are nullable by default) |
| `defaultValue($value)` | `DEFAULT ...` |
| `unsigned()` | `UNSIGNED` (MySQL) |
| `size($size)` | see above |
| `length($n)` | string, fixed and decimal length |
| `description($text)` | stores a comment on the column definition (not emitted in the SQL yet) |

## Keys and indexes

On a column:

```php
$table->integer('id')->autoincrement();     // auto-increment primary key
$table->integer('id')->primary();           // primary key
$table->string('email')->unique();          // unique key
$table->string('name')->index();            // index
```

Each takes an optional constraint name (`->unique('uk_email')`). Otherwise the name is
`<table>_pk_<columns>`, `_uk_`, `_ik_` or `_fk_`.

On the table, including composite keys:

```php
$table->primary(['id', 'group']);
$table->unique(['email', 'tenant_id'], 'uk_tenant_email');
$table->index(['last_name', 'first_name']);
```

A table has one primary key; the last `primary()` call wins.

## Foreign keys

```php
$db->schema()->create('orders', function (CreateTable $table): void {
    $table->integer('id')->autoincrement();
    $table->integer('user_id')->notNull();
    $table->foreign('user_id')->references('users', 'id')->onDelete('cascade');
    $table->engine('InnoDB');
});
```
```sql
CREATE TABLE `orders`(
`id` INT AUTO_INCREMENT,
`user_id` INT NOT NULL,
CONSTRAINT `orders_pk_id` PRIMARY KEY (`id`),
CONSTRAINT `orders_fk_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE = INNODB
```

`onDelete()` and `onUpdate()` accept `cascade`, `restrict`, `no action` and `set null`.
`references($table, ...$columns)` takes several columns for composite keys, matching
`foreign([...])`. `engine()` only affects MySQL.

## SQLite notes

An auto-increment column becomes `INTEGER PRIMARY KEY AUTOINCREMENT` inline, as SQLite
requires. (Before 5.0, every table created afterwards on the same connection lost its primary
key; this is fixed.)
