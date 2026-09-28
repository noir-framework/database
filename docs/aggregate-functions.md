# Aggregate functions

These shortcuts run the query and return a single value.

| Method | SQL | Returns |
|---|---|---|
| `count($column = '*', $distinct = false)` | `COUNT(...)` | `int` |
| `sum($column, $distinct = false)` | `SUM(...)` | the value, `null` for no rows |
| `avg($column, $distinct = false)` | `AVG(...)` | the value, `null` for no rows |
| `min($column, $distinct = false)` | `MIN(...)` | the value, `null` for no rows |
| `max($column, $distinct = false)` | `MAX(...)` | the value, `null` for no rows |

```php
$users = $db->from('users')->count();                          // SELECT COUNT(*) FROM `users`
$withEmail = $db->from('users')->count('email');               // NULLs are not counted
$countries = $db->from('users')->count('country', true);       // COUNT(DISTINCT `country`)
$averageAge = $db->from('users')->where('active')->is(1)->avg('age');
```
```sql
SELECT AVG(`age`) FROM `users` WHERE `active` = 1
```

`count()` always returns an `int`: a numeric string from the driver is converted, and a
grouped query without rows gives `0`. The other functions return what the driver returns, so
`sum()` and `avg()` may be strings for DECIMAL columns.

Without `groupBy()`, any `orderBy()` on the query is left out of the aggregate query, since the
result is a single row (PostgreSQL and SQL Server reject `COUNT(*) ... ORDER BY`). This lets you
reuse a list query for its count. With `groupBy()`, the order is kept, because it decides which
group's value is returned.

To compute several aggregates in one query, select them with a
[closure](fields-selection.md#aggregates).
