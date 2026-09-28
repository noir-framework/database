noirapi/database
================
[![Quality](https://github.com/noir-framework/database/actions/workflows/tests.yml/badge.svg)](https://github.com/noir-framework/database/actions)

A strictly typed PDO abstraction layer with a fluent query builder and a schema builder,
forked from [opis/database](https://github.com/opis/database) 4.x.

- PHP 8.4+, native types everywhere, `declare(strict_types=1)`
- Generic fluent API: `$db->from('users')->where('id')->is(1)->select()->fetchClass(User::class)->first()`
  is typed as `User|false` for PHPStan and Psalm
- Checked with PHPStan (level 10 + strict rules), Psalm (level 1), phpcs (PSR-12 + Slevomat) and PHPMD
- Dialects: MySQL, PostgreSQL, SQLite and Microsoft SQL Server

Upgrading from `opis/database`? See [UPGRADE.md](UPGRADE.md).

## Installation

```json
"repositories": [
    { "type": "git", "url": "git@github.com:noir-framework/database.git" }
],
"require": {
    "noirapi/database": "^5.0"
}
```

## Development

```bash
composer check      # phpcs, PHPStan, Psalm, PHPMD and PHPUnit
composer test       # PHPUnit only
```

## License

Apache License, Version 2.0. Original work copyright Zindex Software (see NOTICE).
