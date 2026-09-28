# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`noirapi/database` is a fork of `opis/database` 4.x: a PDO wrapper with a fluent query builder and a schema builder. It targets PHP ^8.4. The GitHub repo is `noir-framework/database`, and development happens on the `5.x` branch. Around 27 internal NoirAPI apps (in sibling directories such as `../encode` and `../qb`) consume it, so the **public API must stay source-compatible with opis/database**: never rename public classes, methods or parameters, because apps may use named arguments. Old `Opis\Database\*` names resolve through `opis-aliases.php`, a lazy `class_alias` that raises a silenced `E_USER_DEPRECATED`. The aliases are planned for removal in 6.0.

Supported dialects are MySQL, PostgreSQL, SQLite and SQL Server. Oracle, Firebird, DB2 and NuoDB were removed deliberately; `Connection` throws for those drivers. The apps use MariaDB (`mysql:` DSNs), SQL Server through `dblib`, and SQLite in tests; none use PostgreSQL.

User documentation lives in `docs/` (see `docs/README.md`). It was rewritten from the opis 4.x docs, whose source repo has no license, so it must stay our own wording rather than copied text.

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
php .cache/docs-bot/sql.php '$db->from("t")->...' [driver]   # print the SQL a snippet compiles to (docs examples)
php .cache/docs-bot/fetch-opis-docs.php --out=<dir>        # re-import the original opis docs (never --force into docs/)
```

`.cache/` is gitignored, so the two docs-bot scripts are local tools and are never committed.

Real PostgreSQL 16, SQL Server 2022 and MariaDB run in Docker (PHP here has no pdo_pgsql or pdo_sqlsrv, so the tests run inside a PHP 8.4 image that has them):

```bash
docker compose -f tests/docker/compose.yml up -d --wait mariadb postgres mssql
docker compose -f tests/docker/compose.yml --profile php run --rm php vendor/bin/phpunit --testsuite Integration --fail-on-skipped
docker compose -f tests/docker/compose.yml down
```

`tests/Integration/ServerScenarios.php` holds the shared scenarios. `PostgreSqlTest`, `SqlServerTest` and `MySqlScenariosTest` extend it, each configured by `NOIRAPI_DB_<PGSQL|SQLSRV|MYSQL>_DSN/_USER/_PASSWORD`, and each skips when its driver or server is missing. CI (`.github/workflows/tests.yml`, job `integration`) runs them with service containers and `--fail-on-skipped`. Run the Docker suite before a release: its first run found four bugs that string tests had missed.

`tests/Integration/MySqlTest.php` runs against a real MySQL or MariaDB server: `NOIRAPI_DB_MYSQL_DSN`, `_USER` and `_PASSWORD`, defaulting to database, user and password `test` on localhost (MariaDB 10.11 on this machine). It skips when the server is unreachable, and it creates and drops only `t_*` tables. 
Every gate must stay at zero, with no baseline files. If PHPMD reports results that don't match the code, the user-level PDepend cache (`~/.pdepend`) is stale. Run it with `HOME=<tmpdir>` instead of deleting the cache.

## Architecture

**Entry points:** `Database` handles DML (`from()`, `insert()`, `update()`, `transaction()`) and `Schema` handles DDL. Both wrap a `Connection`, which runs PDO and picks a dialect compiler from the `SQL_DIALECTS` / `SCHEMA_DIALECTS` class maps using the PDO driver name.

`Connection::stream()` runs a query unbuffered on MySQL. pdo_mysql reads `Pdo\Mysql::ATTR_USE_BUFFERED_QUERY` from the connection when the statement *executes*: a prepare-time driver option is ignored, and verifying that took a live test. So the attribute is switched off around prepare+execute and restored afterwards.

**The SQL builder has three layers:**
1. `SQL\SQLStatement` is a mutable bag of **readonly clause value objects** from `SQL\Clause\*`. It holds `Condition` subclasses for WHERE, HAVING and JOIN ON (including `WhereBits`, `WhereJsonContains` and `WhereJsonExists`), plus `JoinClause`, `SelectColumn`, `UpdateColumn`, `OrderClause` and `UpsertClause`. INSERT rows live in `values` (the first row, filled by `addValue()`) plus `rows`. `getInsertRows()` returns them all, and `getValues()` stays the public opis getter. `Expression` holds `ExpressionPart` tokens: column, value, operator, group, subquery, `AggregateFunction`, `SqlFunction` (with the `FunctionName` enum), `CallPart` for arbitrary `call()`/`__call` functions, `DateArithmetic` (with the public `SQL\Interval` enum), `BitsPart` and `JsonPart`.
2. The `*Statement` classes (`WhereStatement` → `BaseStatement` → `Select/Update/DeleteStatement`, plus `InsertStatement`) hold the fluent API and have no connection. `Where<TStatement>` is **generic**: `where('col')` returns `Where<$this>`, and its comparison methods return `TStatement`, so chains stay typed for apps. The conditional return types on `where()`/`orWhere()` depend on this; `tests/Types/fluent.php` locks it in with `assertType`.
3. `Query`, `Select`, `Update`, `Delete` and `Insert` hold a `Connection`, compile, and execute. The connection-less parent terminal methods (`select()`, `set()`, `delete()`, `into()`) return `mixed`/`null` so the executing subclasses can narrow the type to `ResultSet`, `int` or `bool`.

`ResultSet<TRow>` is generic. The fetch-mode setters re-bind `TRow` (for example `fetchClass(Foo::class)` gives `ResultSet<Foo>`) through the private `rebind()` helper.

**Compilers:** `SQL\Compiler` is the generic dialect, and its output is MySQL-flavoured (it is what the `''` test driver uses). `SQL\Compiler\{MySQL, PostgreSQL, SQLite, SQLServer}` override it. `SQL\Compiler::handleCondition()` and `handleExpressions()` dispatch with `match (true) { $x instanceof ... }` to protected per-clause methods such as `whereColumn(WhereColumn $w)` and `sqlFunctionROUND(SqlFunction $f)`, which dialects override. `Schema\Compiler` works the same way: `handleColumnType()` matches the column type string, `handleColumnModifiers()` walks the `$modifiers` list, and `handleAlterCommand()` matches on `AlterAction` to call `handle<Action>(AlterTable, AlterCommand)`. To add a clause type, create the value object, the `SQLStatement::add*` method, a `match` arm, and the compiler method.

Generated SQL must stay byte-identical to opis/database for anything opis could already express. That includes quirks such as MySQL `ROUND` compiling to `FORMAT(...)` and the `opis_rownum` alias in SQL Server paging. The deliberate exceptions are listed in UPGRADE.md's behaviour table:
- `is(null)` now produces `IS NULL`.
- MySQL `renameColumn()` emits `RENAME COLUMN`.
- SQL Server `double()` emits `FLOAT(53)` instead of the invalid `DOUBLE`.
- `ucase`/`lcase`/`mid`/`len`/`now` compile to real functions on PostgreSQL, SQLite and SQL Server.
- Column names containing `->` are JSON paths.

Add a new exception only if the old output was broken, and record it there.

Dialect helpers that a database cannot express call `unsupportedFunction()` (a `LogicException`) instead of emitting invalid SQL. INET6_* is MySQL/MariaDB only, and JSON containment of arrays or objects is unsupported on SQLite and SQL Server.

**Pieces that are easy to get wrong:**
- **JSON paths** (`SQL\JsonPath`) are parsed in `Compiler::wrap()`, so they work anywhere a column does. Path keys are *inlined* rather than bound (placeholders break GROUP BY matching), which is why `assertKey()` rejects quotes, backslashes and control characters. `handleSetColumns()` groups the `col->path` keys of one column into a single `jsonSet()`. MariaDB stores a bound int as a JSON string, so non-string values go through `JSON_EXTRACT(?, '$')`.
- **Bit operators on MySQL** return unsigned 64-bit values. The all-bits test is therefore `(~col & mask) = 0`, never `(col & mask) = mask`. Updating a signed BIGINT that holds bit 63 needs `signed: true`, which compiles to `CAST(... AS SIGNED)`.
- **Upserts:** MySQL uses `VALUES(col)`, since MariaDB lacks the row-alias form. SQL Server gets `MERGE ... WITH (HOLDLOCK)`, and everything else gets `ON CONFLICT`.
- **SQLite:** in date arithmetic `||` binds tighter than `*`, so the modifier is `(((amount) * n) || ' unit')`. `Schema\Compiler\SQLite::create()` resets the `$nopk` flag; before that fix it leaked into every later table.
- **Views:** `Schema::createView()` compiles its query with `SQL\Compiler::selectInline()`, which inlines values with `PDO::quote()`, because `CREATE VIEW` cannot take parameters.

## Tests

- **SQL** (`tests/SQL`): `tests/Connection.php` is a fake connection that records the last SQL, with parameters inlined, instead of executing it. Tests wrap a chain in `$this->sql(fn () => ...)` and compare the string. The driver is `''`, so the generic compiler and `"double-quoted"` identifiers apply. The per-dialect tests (`FunctionsTest`, `JsonTest`, `UpsertTest`, `BitsTest`) build a fake connection per driver with a private `compile($driver, fn)` helper and data providers.
- **Schema** (`tests/Schema`): all cases are in `BaseClass`; each dialect subclass only sets `$schema_name`. Expected SQL comes from `tests/data/schema/<dialect>.yaml`, keyed by test method name. A missing key skips the test rather than failing it.
- **Integration** (`tests/Integration`): `SqliteTest` uses real in-memory SQLite, and `MySqlTest` uses a real MySQL or MariaDB server (see Commands). New features that compile differently per dialect should be exercised in both.
- `tests/Types/fluent.php` is analysed by PHPStan only and never executed.

## Conventions

- Every `src` file starts with the Apache-2.0 header (Zindex Software plus noir-framework), then a blank line, `declare(strict_types=1);`, and another blank line.
- `psalm.xml` and `phpmd.xml` suppressions each carry their reason in a comment. Don't add new suppressions without one. Purity annotations (`@psalm-mutation-free` etc.) are intentionally absent: the builders are mutable and fluent.
- Record user-visible changes in `CHANGELOG.md`, and in `UPGRADE.md` when they change behaviour. Update the matching `docs/` page too. Generate the SQL shown in docs with `.cache/docs-bot/sql.php` rather than writing it by hand.
