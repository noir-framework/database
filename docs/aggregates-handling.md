# Grouping and HAVING

## GROUP BY

```php
use Noirapi\Database\SQL\ColumnExpression;
use Noirapi\Database\SQL\Join;

$db->from('customers')
    ->leftJoin('orders', fn (Join $join) => $join->on('customers.id', 'orders.customer_id'))
    ->groupBy('customers.name')
    ->select(function (ColumnExpression $include): void {
        $include->column('customers.name', 'name')->count('orders.id', 'orders');
    })
    ->all();
```
```sql
SELECT `customers`.`name` AS `name`, COUNT(`orders`.`id`) AS `orders` FROM `customers`
LEFT JOIN `orders` ON `customers`.`id` = `orders`.`customer_id` GROUP BY `customers`.`name`
```

`groupBy()` takes a column, a list of columns, or expressions.

## HAVING

`having($column, $closure)` filters groups. The closure picks the aggregate (`count()`,
`sum()`, `avg()`, `min()` or `max()`, each with an optional `$distinct` flag) and then the
comparison: `eq`, `ne`, `lt`, `gt`, `lte`, `gte`, `in`, `notIn`, `between` or `notBetween`.

```php
->groupBy('customers.name')
->having('orders.id', fn ($column) => $column->count()->gt(10))
```
```sql
GROUP BY `customers`.`name` HAVING COUNT(`orders`.`id`) > 10
```

Combine conditions with `andHaving()` and `orHaving()`. Passing a single closure groups them in
parentheses:

```php
->having('orders.id', fn ($column) => $column->count()->gt(10))
->andHaving(function ($group): void {
    $group->having('orders.value', fn ($column) => $column->sum()->gte(1000))
        ->orHaving('orders.value', fn ($column) => $column->min()->gte(500));
})
```
```sql
HAVING COUNT(`orders`.`id`) > 10 AND (SUM(`orders`.`value`) >= 1000 OR MIN(`orders`.`value`) >= 500)
```
