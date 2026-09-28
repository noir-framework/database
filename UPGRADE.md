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

Native parameter types are now declared everywhere. Code that passed unexpected types (for
example `null` where a string is expected) will get a `TypeError` instead of silently
producing SQL.
