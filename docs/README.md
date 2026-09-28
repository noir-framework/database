# noirapi/database

`noirapi/database` is a PDO wrapper with a fluent query builder and a schema builder. It is a
fork of [opis/database](https://github.com/opis/database) 4.x, maintained for PHP 8.4+ by
noir-framework. Code written for opis/database keeps working: class and method names are
unchanged, and the old `Opis\Database\*` names still resolve (see [UPGRADE.md](../UPGRADE.md)).

Supported databases: **MySQL / MariaDB**, **PostgreSQL**, **SQLite** and **SQL Server**.
Oracle, Firebird, DB2 and NuoDB were dropped in 5.0.

```php
use Noirapi\Database\Connection;
use Noirapi\Database\Database;

$db = new Database(new Connection('mysql:host=localhost;dbname=app', 'user', 'secret'));

$adults = $db->from('users')
    ->where('age')->atLeast(18)
    ->orderBy('name')
    ->select(['id', 'name'])
    ->fetchClass(User::class)
    ->all();                                  // list<User>
```

## Installation

```sh
composer require noirapi/database:^5.0@beta
```

Requirements: PHP 8.4 or newer and the PDO driver for your database.

### Minimum database versions

| Database | Minimum | Needed for |
|---|---|---|
| MySQL | **8.0.21** | `RENAME COLUMN` (8.0), `JSON_VALUE` for JSON paths (8.0.21) |
| MariaDB | **10.5.2** | `RENAME COLUMN` (10.5.2); JSON functions since 10.2.3 |
| PostgreSQL | **9.5** | `ON CONFLICT` upserts (9.5), `jsonb` and `make_interval` (9.4) |
| SQLite | **3.25** | `RENAME COLUMN` (3.25), upserts (3.24); JSON needs the JSON1 functions (built in since 3.38) |
| SQL Server | **2016** | `JSON_VALUE` / `OPENJSON` / `JSON_MODIFY` (2016); `jsonExists()` needs 2022 (`JSON_PATH_EXISTS`) |

Older servers still run everything that does not use the listed feature. For example,
MySQL 5.7 handles plain queries, inserts and upserts, but not `renameColumn()` or JSON paths.

## Contents

**Basics**
- [Connections](connections.md): connecting, PDO options, logging, the `Database` object
- [Transactions](transactions.md)

**Reading data**
- [Fetching records](fetching-records.md): `from()`, `select()`, `first()`, `column()`
- [Results handling](results-handling.md): fetch modes, typed rows, lazy iteration, streaming
- [Fields selection](fields-selection.md): columns, aliases, aggregates and functions in SELECT
- [Filters](filters.md): `where()` conditions
- [Joins](joins.md)
- [Ordering](ordering-criteria.md), [limits and offsets](limits-and-offsets.md)
- [Aggregate functions](aggregate-functions.md), [grouping and HAVING](aggregates-handling.md)

**Writing data**
- [Inserting records](insert-records.md): single rows, multi-row inserts, upserts
- [Updating records](update-records.md)
- [Deleting records](delete-records.md)

**Expressions**
- [Expressions](expressions.md): raw SQL fragments and arbitrary function calls
- [Date and time](date-and-time.md): `now()`, `ago()`, date arithmetic, `currentDate()`
- [JSON columns](json.md): path queries, containment, in-place updates
- [Bit fields](bit-fields.md): testing, setting and clearing flag bits

**Schema**
- [Schema](schema.md): tables, columns and views
- [Creating tables](schema-creating-tables.md)
- [Modifying tables](schema-modifying-tables.md)

## About these docs

The SQL shown under each example is what the MySQL compiler produces, with parameters shown
inline for readability. Queries are always sent with bound parameters. The other databases
get the equivalent SQL for their dialect.

These pages started from the [opis/database 4.x documentation](https://opis.io/database/4.x/)
and were rewritten for noirapi/database 5.0. `.cache/docs-bot/fetch-opis-docs.php` (not
committed) can re-import the original pages for comparison.

Licensed under the Apache License, Version 2.0.
