# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`noirapi/database` is a fork of `opis/database` 4.x: a PDO wrapper with a fluent query builder and a schema builder. It targets PHP ^8.4. The GitHub repo is `noir-framework/database`, and development happens on the `5.x` branch. Around 27 internal NoirAPI apps (in sibling directories such as `../encode` and `../qb`) consume it, so the **public API must stay source-compatible with opis/database**: never rename public classes, methods or parameters, because apps may use named arguments. Old `Opis\Database\*` names resolve through `opis-aliases.php`, a lazy `class_alias` that raises a silenced `E_USER_DEPRECATED`. The aliases are planned for removal in 6.0.

Supported dialects are MySQL, PostgreSQL, SQLite and SQL Server. Oracle, Firebird, DB2 and NuoDB were removed deliberately; `Connection` throws for those drivers.

## Commands

```bash
composer check                                   # all gates: phpcs, phpstan, psalm, phpmd, tests
composer test                                    # phpunit
vendor/bin/phpunit --testsuite SQL               # suites: SQL, Integration, Schema
vendor/bin/phpunit --filter testWhereIs          # single test
composer phpstan                                 # level 10 + strict-rules, also checks tests/Types
composer psalm                                   # level 1
composer cs / composer cs-fix                    # phpcs / phpcbf (PSR-12 + Slevomat)
composer phpmd                                   # PHPMD 3 (`phpmd analyze ...` syntax)
```

Every gate must stay at zero, with no baseline files. If PHPMD reports results that don't match the code, the user-level PDepend cache (`~/.pdepend`) is stale. Run it with `HOME=<tmpdir>` instead of deleting the cache.

## Architecture

**Entry points:** `Database` handles DML (`from()`, `insert()`, `update()`, `transaction()`) and `Schema` handles DDL. Both wrap a `Connection`, which runs PDO and picks a dialect compiler from the `SQL_DIALECTS` / `SCHEMA_DIALECTS` class maps using the PDO driver name.

**The SQL builder has three layers:**
1. `SQL\SQLStatement` is a mutable bag of **readonly clause value objects** from `SQL\Clause\*`. It holds `Condition` subclasses for WHERE, HAVING and JOIN ON, plus `JoinClause`, `SelectColumn`, `UpdateColumn` and `OrderClause`. `Expression` holds `ExpressionPart` tokens, along with `AggregateFunction`/`SqlFunction` and their name enums.
2. The `*Statement` classes (`WhereStatement` → `BaseStatement` → `Select/Update/DeleteStatement`, plus `InsertStatement`) hold the fluent API and have no connection. `Where<TStatement>` is **generic**: `where('col')` returns `Where<$this>`, and its comparison methods return `TStatement`, so chains stay typed for apps. The conditional return types on `where()`/`orWhere()` depend on this; `tests/Types/fluent.php` locks it in with `assertType`.
3. `Query`, `Select`, `Update`, `Delete` and `Insert` hold a `Connection`, compile, and execute. The connection-less parent terminal methods (`select()`, `set()`, `delete()`, `into()`) return `mixed`/`null` so the executing subclasses can narrow the type to `ResultSet`, `int` or `bool`.

`ResultSet<TRow>` is generic. The fetch-mode setters re-bind `TRow` (for example `fetchClass(Foo::class)` gives `ResultSet<Foo>`) through the private `rebind()` helper.

**Compilers:** `SQL\Compiler::handleCondition()` and `handleExpressions()` dispatch with `match (true) { $x instanceof ... }` to protected per-clause methods such as `whereColumn(WhereColumn $w)` and `sqlFunctionROUND(SqlFunction $f)`, which dialects override. `Schema\Compiler` works the same way: `handleColumnType()` matches the column type string, `handleColumnModifiers()` walks the `$modifiers` list, and `handleAlterCommand()` matches on `AlterAction` to call `handle<Action>(AlterTable, AlterCommand)`. To add a clause type, create the value object, the `SQLStatement::add*` method, a `match` arm, and the compiler method.

Generated SQL must stay byte-identical to opis/database. That includes quirks such as MySQL `ROUND` compiling to `FORMAT(...)` and the `opis_rownum` alias in SQL Server paging.

## Tests

- **SQL** (`tests/SQL`): `tests/Connection.php` is a fake connection that records the last SQL, with parameters inlined, instead of executing it. Tests wrap a chain in `$this->sql(fn () => ...)` and compare the string. The driver is `''`, so the generic compiler and `"double-quoted"` identifiers apply.
- **Schema** (`tests/Schema`): all cases are in `BaseClass`; each dialect subclass only sets `$schema_name`. Expected SQL comes from `tests/data/schema/<dialect>.yaml`, keyed by test method name. A missing key skips the test rather than failing it.
- **Integration** (`tests/Integration`): runs against real in-memory SQLite.
- `tests/Types/fluent.php` is analysed by PHPStan only and never executed.

## Conventions

- Every `src` file starts with the Apache-2.0 header (Zindex Software plus noir-framework), then a blank line, `declare(strict_types=1);`, and another blank line.
- `psalm.xml` and `phpmd.xml` suppressions each carry their reason in a comment. Don't add new suppressions without one. Purity annotations (`@psalm-mutation-free` etc.) are intentionally absent: the builders are mutable and fluent.
- Record user-visible changes in `CHANGELOG.md`, and in `UPGRADE.md` when they change behaviour.
