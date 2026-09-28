# Limits and offsets

```php
$db->from('users')->orderBy('name')->limit(25)->offset(50)->select();
```
```sql
SELECT * FROM `users` ORDER BY `name` ASC LIMIT 25 OFFSET 50
```

`offset()` only has an effect together with `limit()`.

## Pagination

`paginate($page, $perPage, $columns = [])` fetches one page and counts all matching rows:

```php
$page = $db->from('users')->where('active')->is(1)->orderBy('name')->paginate($current, 20);

$users = $page->results->fetchClass(User::class)->all();   // at most 20 rows
$page->total;        // rows matched by the whole query
$page->lastPage();   // 1 when there are no rows
$page->hasMore();    // is there a page after this one?
```

It runs two queries: the rows (`LIMIT` / `OFFSET`), and a count. The count drops ORDER BY, and
for grouped or `DISTINCT` queries it is `SELECT COUNT(*) FROM (<query>) AS opis_page`, so the
total counts groups rather than rows. Always add an `orderBy()`: without one, the database may
return rows in a different order on each page. `$page` starts at 1.

## SQL Server

SQL Server has no `LIMIT`. A limit alone becomes `TOP`, and a limit with an offset becomes a
`ROW_NUMBER()` window (the `opis_rownum` alias is kept from opis/database):

```sql
SELECT TOP 25 * FROM [users] ORDER BY [name] ASC
SELECT * FROM (SELECT *, ROW_NUMBER() OVER (ORDER BY [name] ASC) AS opis_rownum FROM [users]) AS m1
    WHERE opis_rownum BETWEEN 51 AND 75
```

With an offset, rows therefore carry an extra `opis_rownum` column on SQL Server.
