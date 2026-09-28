# Upgrading from opis/database 4.x to noirapi/database 5.0

The public API is source-compatible: class and method names are unchanged, only the
namespace moved. Most applications need no code changes to keep running.

## 1. Switch the package

```json
"repositories": [
    { "type": "git", "url": "git@github.com:noir-framework/database.git" }
],
"require": {
    "noirapi/database": "^5.0@beta"
}
```

Remove `opis/database` from `require`; `noirapi/database` declares `"replace": {"opis/database": "*"}`,
so the two can never be installed together. Then run `composer update noirapi/database`.

PHP 8.4 or newer is required.

## 2. Keep running on the old namespace (optional, temporary)

`Opis\Database\*` classes resolve to `Noirapi\Database\*` automatically through lazy aliases.
Each alias raises an `E_USER_DEPRECATED` notice (silenced with `@`, so it only shows up in
handlers that log silenced notices, such as Tracy's bar). The aliases are removed in 6.0.

PHPStan cannot see these runtime aliases. Until the imports are migrated, add:

```neon
parameters:
    bootstrapFiles:
        - vendor/noirapi/database/compat/phpstan-bootstrap.php
```

## 3. Migrate the imports

```sh
vendor/bin/noirapi-database-migrate app tests phpstan.neon psalm.xml        # dry run
vendor/bin/noirapi-database-migrate --write app tests phpstan.neon psalm.xml
```

It rewrites `Opis\Database` in `.php`, `.phtml`, `.latte`, `.neon` and `.xml` files, including
regex-escaped forms in `ignoreErrors`. Afterwards remove the bootstrap file from step 2.

## 4. Drop static-analysis workarounds

The fluent builder is now generic, so ignore rules like this one can be deleted:

```neon
-
    message: '#Call to an undefined method Opis\\Database\\SQL\\[A-Za-z]+::[a-zA-Z]+\(\)#'
```

`/** @var User|false $user */` casts after `->fetchClass(User::class)->first()` are no longer
needed either: the result is typed as `User|false`.

## Behaviour changes to check

| Area | 4.x | 5.0 |
|---|---|---|
| Oracle, Firebird, DB2, NuoDB drivers | dialect compilers | `RuntimeException` |
| `Schema::create()`, `alter()`, `drop()`, `truncate()`, `renameTable()` | `null` | `void` |
| `CreateColumn::getTable()`, `AlterColumn::getTable()` | `TypeError` | the table object |
| `Join::on('col')` with no second column | invalid SQL | `InvalidArgumentException` |
| `Connection::setDateFormat()` etc. with unknown compiler options | dynamic property | `InvalidArgumentException` |
| `SQLStatement::getWheres()` and other clause getters | `array` rows | readonly `SQL\Clause\*` objects |
| `AlterTable::getCommands()` | `array` rows | `Schema\AlterCommand` objects |
| `Schema\Compiler::currentDatabase()` | `['result' => ...]` or query | `string` or query |
| `Connection` serialization | `Serializable` | `__serialize()` / `__unserialize()` |
| `where('a')->is(null)`, `isNot(null)`, `eq(null)`, `ne(null)` | `= NULL` (never matches) | `IS NULL` / `IS NOT NULL` |
| SQLite `CREATE TABLE` after a table with an auto-increment column | PRIMARY KEY dropped | PRIMARY KEY kept |
| MySQL `renameColumn()` | `CHANGE` (loses NOT NULL, default, comment) | `RENAME COLUMN`: needs MySQL 8 / MariaDB 10.5.2+ |
| `ucase()`, `lcase()`, `mid()`, `len()`, `now()` on PostgreSQL / SQLite / SQL Server | MySQL names (invalid there) | `UPPER`, `LOWER`, `SUBSTR`/`SUBSTRING`, `LENGTH`/`LEN`, `datetime('now')`/`GETDATE()`; MySQL output unchanged |
| Column names containing `->` | quoted as one identifier | JSON path (`meta->a` reads `$.a`) |
| Non-PDO exception inside `transaction()` | re-thrown, transaction left open | rolled back, then re-thrown |
| SQL Server `double()` column | `DOUBLE` (invalid) | `FLOAT(53)` |
| `in([])` / `notIn([])` | `IN ()` (syntax error) | `1 = 0` / `1 = 1` |

Native parameter types are now declared everywhere. Code that passed unexpected types (for
example `null` where a string is expected) will get a `TypeError` instead of silently
producing SQL.

## New in 5.0

Full documentation is in [docs/](docs/README.md). Replacements for common hand-built workarounds:

| Before | Now | Docs |
|---|---|---|
| `where(fn ($e) => $e->column('flags')->op('&')->value($m), true)->is(0)` | `where('flags')->hasNoBits($m)` | [bit fields](docs/bit-fields.md) |
| `->isNot(0)` on the same | `hasAnyBits($m)`; all bits: `hasAllBits($m)` | |
| `set(['flags' => fn ($e) => $e->group(...)->op('\|')->value($b)])` | `setBits()`, `clearBits()`, `$e->bits($col, set:, clear:)` | |
| raw `DATE_SUB(NOW(), INTERVAL 4 day)` | `->atMost(fn ($e) => $e->ago(4, Interval::Day))` | [date and time](docs/date-and-time.md) |
| `$e->op('INET6_NTOA(')->column('ip')->op(')')` | `$e->inet6Ntoa('ip')` | [expressions](docs/expressions.md) |
| `$e->op('JSON_CONTAINS(')->column($c)->op(',')->value('"x"')->op(')')` | `where($c)->jsonContains('x')` | [JSON](docs/json.md) |
| `$e->op('FUNC(')->...->op(')')` | `$e->call('FUNC', ...)` | [expressions](docs/expressions.md) |
| `->select()->all()` over huge tables | `foreach ($q->select() as $row)`, or `stream()` on MySQL | [results](docs/results-handling.md) |
| loop of `insert()` | `insertMany($rows)` | [inserting](docs/insert-records.md) |
| select, then insert or update | `->upsert($keys, $update)` | [inserting](docs/insert-records.md) |

## Multi-row inserts and upserts

`insert()` keeps its single-row signature. Loops of single inserts can move to `insertMany()`:

```php
$db->insertMany([
    ['name' => 'Ann', 'age' => 30],
    ['name' => 'Bob', 'age' => null],   // every row needs the same columns
])->into('users');
```

Replace "select, then insert or update" code with an upsert. `$keys` are the unique columns
that detect the duplicate (required except on MySQL):

```php
$db->insert(['page' => '/', 'hits' => 1, 'title' => 'Home'])
    ->upsert('page', [
        'title',                                                     // take the inserted value
        'hits' => fn (Expression $e) => $e->column('stats.hits')->op('+')->value(1),
    ])
    ->into('stats');

$db->insertMany($rows)->upsert('id')->into('users');      // update every non-key column
$db->insertMany($rows)->upsert('id', [])->into('users');  // insert, skip existing rows
```

Qualify columns of the existing row with the table name (`stats.hits`): PostgreSQL and SQL Server
reject the bare name as ambiguous. On MySQL `lastInsertId()` after a multi-row insert is the
first row's id.

`$expr->COALESCE(...)` works at runtime, but PHPStan reports magic methods as undefined, so use
`$expr->call('COALESCE', ...)` in analysed code.

