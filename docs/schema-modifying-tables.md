# Modifying tables

```php
use Noirapi\Database\Schema\AlterTable;

$db->schema()->alter('users', function (AlterTable $table): void {
    $table->integer('score')->defaultValue(0);       // add a column
    $table->toJson('settings');                      // change a column's type
    $table->renameColumn('name', 'username');
    $table->dropColumn('legacy');
    $table->setDefaultValue('role', 'member');
    $table->unique('username');
    $table->dropIndex('users_ik_role_age');
});
```
```sql
ALTER TABLE `users` ADD COLUMN `score` INT DEFAULT 0
ALTER TABLE `users` MODIFY COLUMN `settings` JSON
ALTER TABLE `users` RENAME COLUMN `name` TO `username`
ALTER TABLE `users` DROP COLUMN `legacy`
ALTER TABLE `users` ALTER `role` SET DEFAULT 'member'
ALTER TABLE `users` ADD CONSTRAINT `users_uk_username` UNIQUE (`username`)
ALTER TABLE `users` DROP INDEX `users_ik_role_age`
```

Each operation is a separate statement, run in order.

## Columns

- **Add:** the same type methods as [creating tables](schema-creating-tables.md#column-types)
  (`integer()`, `string()`, `json()`, ...), with modifiers. Keys are added separately (below).
- **Change type:** `toInteger()`, `toFloat()`, `toDouble()`, `toDecimal()`, `toBoolean()`,
  `toBinary()`, `toString()`, `toFixed()`, `toText()`, `toJson()`, `toTime()`,
  `toTimestamp()`, `toDate()`, `toDateTime()`. The new definition replaces the old one, so
  repeat `notNull()`, etc.
- **Rename:** `renameColumn($from, $to)` emits `RENAME COLUMN`, which keeps the column's type,
  nullability, default and comment. It needs MySQL 8, MariaDB 10.5.2+, PostgreSQL or
  SQLite 3.25+; SQL Server uses `sp_rename`. (opis/database used MySQL's `CHANGE` with only the
  looked-up type, which dropped `NOT NULL`, the default and the comment.)
- **Drop:** `dropColumn($name)`.
- **Defaults:** `setDefaultValue($column, $value)`, `dropDefaultValue($column)`.

## Keys and indexes

| Add | Drop |
|---|---|
| `primary($columns, $name = null)` | `dropPrimary($name)` |
| `unique($columns, $name = null)` | `dropUnique($name)` |
| `index($columns, $name = null)` | `dropIndex($name)` |
| `foreign($columns, $name = null)->references(...)` | `dropForeign($name)` |

Default names follow the create-table rules (`<table>_uk_<columns>`, ...), so a key created
without a name is dropped by that generated name. On PostgreSQL, index names are prefixed with
the table name, because they are unique per schema.

## Tables

```php
$db->schema()->renameTable('user', 'users');
$db->schema()->truncate('logs');
$db->schema()->drop('tmp');
```
