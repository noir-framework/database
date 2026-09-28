# JSON columns

## Reading values by path

Write a JSON path into a column name with `->`. It works in `where()`, `select()`,
`orderBy()` and `groupBy()`, and reads the scalar at that path as text:

```php
$db->from('users')
    ->where('meta->address->city')->is('Sofia')
    ->orderBy('meta->rank', 'desc')
    ->select(['id', 'meta->address->city' => 'city']);
```
```sql
SELECT `id`, JSON_VALUE(`meta`, '$."address"."city"') AS `city` FROM `users`
WHERE JSON_VALUE(`meta`, '$."address"."city"') = 'Sofia' ORDER BY JSON_VALUE(`meta`, '$."rank"') DESC
```

Array elements use `[n]`: `meta->tags[0]`, `meta->matrix[1][2]`. The column part may be
qualified: `u.meta->city`.

The same path as an expression, with a dotted string or a list of keys and indexes:

```php
$e->json('meta', 'address.city')       // also '$.address.city' or ['address', 'city']
$e->json('meta', 'items[0].sku')
```

| Database | Compiles to | JSON `null` / missing key |
|---|---|---|
| MySQL 8.0.21+, MariaDB 10.2.3+ | `JSON_VALUE(col, '$."a"."b"')` | SQL `NULL` |
| PostgreSQL | `(col #>> '{"a","b"}')` | SQL `NULL` |
| SQLite | `json_extract(col, '$."a"."b"')` | SQL `NULL` |
| SQL Server | `JSON_VALUE(col, '$."a"."b"')` | SQL `NULL` |

A JSON `null` and a missing key both read as SQL `NULL`, so `->isNull()` matches either; use
`jsonExists()` to tell them apart. MySQL's `JSON_VALUE()` returns at most 512 characters
unless told otherwise. SQLite returns numbers as numbers; the others return text, which
compares correctly with bound numbers.

Keys are written into the SQL, not bound, so they cannot contain quotes, backslashes or
control characters; such keys throw an `InvalidArgumentException`. Values are always bound.

## Conditions

| Method | True when |
|---|---|
| `jsonContains($value)` | the document (or the value at the path) contains `$value` |
| `jsonNotContains($value)` | it does not |
| `jsonExists()` | the path exists, even if its value is `null` (needs an arrow path) |
| `jsonNotExists()` | the path does not exist |

```php
$db->from('users')
    ->where('tags')->jsonContains('php')
    ->andWhere('meta->roles')->jsonNotContains('banned')
    ->andWhere('meta->deleted_at')->jsonNotExists()
    ->select('id');
```
```sql
SELECT `id` FROM `users` WHERE JSON_CONTAINS(`tags`, '"php"')
AND NOT JSON_CONTAINS(`meta`, '"banned"', '$."roles"') AND NOT JSON_CONTAINS_PATH(`meta`, 'one', '$."deleted_at"')
```

| Database | `jsonContains()` | `jsonExists()` |
|---|---|---|
| MySQL / MariaDB | `JSON_CONTAINS(doc, ?[, path])`, any JSON value | `JSON_CONTAINS_PATH(doc, 'one', path)` |
| PostgreSQL | `CAST(doc AS jsonb) @> CAST(? AS jsonb)`, any JSON value | `(doc #> path) IS NOT NULL` |
| SQLite | `EXISTS (SELECT 1 FROM json_each(doc[, path]) WHERE value = ?)`, scalars in arrays | `json_type(doc, path) IS NOT NULL` |
| SQL Server | `EXISTS (SELECT 1 FROM OPENJSON(doc[, path]) WHERE [value] = ?)`, scalars in arrays | `JSON_PATH_EXISTS(doc, path) = 1` (SQL Server 2022+) |

On MySQL and PostgreSQL, `jsonContains()` also matches objects and sub-documents
(`jsonContains(['role' => 'admin'])`). SQLite and SQL Server can only look for a scalar
inside an array, and throw a `LogicException` for arrays or objects.

## Updating paths

Use arrow keys in `set()` to change values inside the document without rewriting it. All the
paths of one column go into one assignment, and other columns can be set in the same call:

```php
$db->update('users')->where('id')->is(1)->set([
    'meta->address->city' => 'Plovdiv',
    'meta->visits' => 3,
    'meta->vip' => true,
    'meta->tags' => ['a', 'b'],
    'name' => 'Ann',
]);
```
```sql
UPDATE `users` SET `meta` = JSON_SET(`meta`, '$."address"."city"', 'Plovdiv', '$."visits"', JSON_EXTRACT('3', '$'),
'$."vip"', JSON_EXTRACT('true', '$'), '$."tags"', JSON_EXTRACT('["a","b"]', '$')), `name` = 'Ann' WHERE `id` = 1
```

Value types are kept: `3` is stored as a number, `true` as a boolean, and arrays as JSON
arrays or objects. Strings are bound directly; other values go in as JSON text. MariaDB would
otherwise store a bound integer as the string `"3"`.

| Database | Compiles to |
|---|---|
| MySQL / MariaDB | `JSON_SET(col, path, value, ...)` |
| PostgreSQL | nested `jsonb_set(CAST(col AS jsonb), '{path}', CAST(? AS jsonb))` |
| SQLite | `json_set(col, path, value, ...)` |
| SQL Server | nested `JSON_MODIFY(col, path, value)` |

Caveats:

- The parent of the path must already exist on MySQL, MariaDB and PostgreSQL, where setting
  `meta->address->city` on a document without `address` changes nothing. SQLite creates the
  missing objects.
- A column cannot be set whole and by path in the same `set()`; that throws an
  `InvalidArgumentException`.
- On SQL Server, `null` uses a `strict` path, because lax mode would delete the key. The key
  must therefore exist.

## Storing documents

Declare the column with [`json()`](schema-creating-tables.md#column-types) and insert JSON
text:

```php
$db->insert(['name' => 'Ann', 'meta' => json_encode($meta, JSON_THROW_ON_ERROR)])->into('users');
```

PHP arrays are not encoded automatically: a value is only treated as JSON when you encode
it, so a string is never turned into JSON by accident. Decode values when reading, for example
with `json_decode($row->meta, true)`.
