# Filters

`where('column')` starts a condition, and a comparison method finishes it and returns the
query, so conditions chain:

```php
$db->from('users')
    ->where('age')->is(18)
    ->andWhere('city')->in(['London', 'Paris'])
    ->orWhere('vip')->is(true)
    ->select();
```
```sql
SELECT * FROM `users` WHERE `age` = 18 AND `city` IN ('London', 'Paris') OR `vip` = TRUE
```

`andWhere()` joins with `AND` (same as `where()`), `orWhere()` with `OR`. The chain is typed:
`where('a')` returns `Where<Query>` and `->is(1)` returns the `Query` again, so static
analysis follows it.

## Comparisons

| Method | Alias | SQL |
|---|---|---|
| `is($value)` | `eq()` | `= ?` (`IS NULL` for `null`) |
| `isNot($value)` | `ne()` | `!= ?` (`IS NOT NULL` for `null`) |
| `lessThan($value)` | `lt()` | `< ?` |
| `greaterThan($value)` | `gt()` | `> ?` |
| `atMost($value)` | `lte()` | `<= ?` |
| `atLeast($value)` | `gte()` | `>= ?` |
| `between($a, $b)` / `notBetween($a, $b)` | | `[NOT] BETWEEN ? AND ?` |
| `in([...])` / `notIn([...])` | | `[NOT] IN (?, ?)` |
| `like($pattern)` / `notLike($pattern)` | | `[NOT] LIKE ?` |
| `isNull()` | | `IS NULL` |
| `notNull()` | `isNotNull()` | `IS NOT NULL` |
| `nop()` | | the column or expression alone |

```php
$db->from('users')
    ->where('age')->gt(18)->andWhere('age')->lte(65)
    ->andWhere('name')->like('Jo%')
    ->andWhere('email')->notLike('%@test.%')
    ->select();
```

### Comparing with null

`is(null)` and `isNot(null)`, and their aliases `eq(null)` and `ne(null)`, produce
`IS NULL` / `IS NOT NULL`:

```php
$db->from('users')->where('deleted_at')->is(null)->andWhere('email')->isNot(null)->select();
```
```sql
SELECT * FROM `users` WHERE `deleted_at` IS NULL AND `email` IS NOT NULL
```

opis/database 4.x produced `= NULL`, which never matches any row. Code that passes a
nullable variable to `is()` now gets the intended result.

### Comparing two columns

Pass `true` as the second argument to compare with another column instead of a value:

```php
$db->from('users')->where('city')->eq('birthplace', true)->select();
```
```sql
SELECT * FROM `users` WHERE `city` = `birthplace`
```

Without `true`, `'birthplace'` would be a string value. The right-hand side can also be a
closure building an [expression](expressions.md):

```php
->where('updated_at')->lt(fn (Expression $e) => $e->ago(30, Interval::Day))
```

### Empty lists

`in([])` matches no rows and `notIn([])` matches every row. They compile to `1 = 0` and
`1 = 1`, because `IN ()` is a syntax error. Passing a list that may be empty is safe.

### Sub-queries

`in()` and `notIn()` accept a closure that builds a sub-query:

```php
use Noirapi\Database\SQL\Subquery;

$db->from('users')
    ->where('city')->in(function (Subquery $query): void {
        $query->from('cities')->where('population')->atLeast(10_000_000)->select('name');
    })
    ->select();
```
```sql
SELECT * FROM `users` WHERE `city` IN (SELECT `name` FROM `cities` WHERE `population` >= 10000000)
```

## Grouping conditions

A closure passed to `where()`, `andWhere()` or `orWhere()` becomes a parenthesized group:

```php
use Noirapi\Database\SQL\WhereStatement;

$db->from('users')
    ->where('age')->is(18)
    ->andWhere(function (WhereStatement $group): void {
        $group->where('city')->is('London')->orWhere('city')->is('Paris');
    })
    ->select();
```
```sql
SELECT * FROM `users` WHERE `age` = 18 AND (`city` = 'London' OR `city` = 'Paris')
```

## Expressions on the left-hand side

`whereExpression()`, `andWhereExpression()` and `orWhereExpression()` take an expression (or a
closure building one) as the left-hand side. They are shortcuts for `where($closure, true)`:

```php
$db->from('users')
    ->whereExpression(fn (Expression $e) => $e->lcase('name'))->like('%foo%')
    ->select();
```
```sql
SELECT * FROM `users` WHERE LCASE(`name`) LIKE '%foo%'
```

Use `nop()` when the expression is the whole condition:

```php
$db->from('articles')
    ->whereExpression(fn (Expression $e) => $e->op('MATCH(')->column('body')->op(') AGAINST(')->value('php')->op(')'))
    ->nop()
    ->select();
```

## EXISTS

`whereExists()` and `whereNotExists()` (plus `andWhere...` / `orWhere...` forms) take a
sub-query closure:

```php
$db->from('users')
    ->whereExists(function (Subquery $query): void {
        $query->from('orders')->where('orders.user_id')->eq('users.id', true)->select();
    })
    ->select();
```
```sql
SELECT * FROM `users` WHERE EXISTS (SELECT * FROM `orders` WHERE `orders`.`user_id` = `users`.`id`)
```

## Special conditions

- **Bit fields:** `where('flags')->hasAllBits($mask)`, `hasAnyBits()` and `hasNoBits()`; see
  [Bit fields](bit-fields.md)
- **JSON:** `where('meta->address->city')->is('Sofia')`, `jsonContains()` and `jsonExists()`;
  see [JSON columns](json.md)
- **Dates:** `where('created_at')->gte(fn (Expression $e) => $e->ago(7, Interval::Day))`; see
  [Date and time](date-and-time.md)
