# Limits and offsets

```php
$db->from('users')->orderBy('name')->limit(25)->offset(50)->select();
```
```sql
SELECT * FROM `users` ORDER BY `name` ASC LIMIT 25 OFFSET 50
```

`offset()` only has an effect together with `limit()`.

SQL Server has no `LIMIT`. A limit alone becomes `TOP`, and a limit with an offset becomes a
`ROW_NUMBER()` window (the `opis_rownum` alias is kept from opis/database):

```sql
SELECT TOP 25 * FROM [users] ORDER BY [name] ASC
SELECT * FROM (SELECT *, ROW_NUMBER() OVER (ORDER BY [name] ASC) AS opis_rownum FROM [users]) AS m1
    WHERE opis_rownum BETWEEN 51 AND 75
```
