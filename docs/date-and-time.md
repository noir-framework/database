# Date and time

Date helpers are `Expression` methods, so they work anywhere an expression does: as a value
in `where()`, in `set()` and `insert()`, or as a selected column. Each database gets its own
syntax.

```php
use Noirapi\Database\SQL\Expression;
use Noirapi\Database\SQL\Interval;

// orders still open after 4 days
$db->from('orders')
    ->where('status')->is('opened')
    ->andWhere('added_on')->atMost(fn (Expression $e) => $e->ago(4, Interval::Day))
    ->select();
```
```sql
SELECT * FROM `orders` WHERE `status` = 'opened' AND `added_on` <= DATE_SUB(NOW(), INTERVAL 4 DAY)
```

## Methods

| Method | Meaning |
|---|---|
| `now()` | current date and time |
| `currentDate()` | today, without time |
| `ago($amount, $unit)` | now minus the interval |
| `fromNow($amount, $unit)` | now plus the interval |
| `dateAdd($date, $amount, $unit)` | `$date` plus the interval |
| `dateSub($date, $amount, $unit)` | `$date` minus the interval |

`$unit` is the `Interval` enum: `Second`, `Minute`, `Hour`, `Day`, `Week`, `Month`, `Year`.
Using an enum rather than a string means the unit can never inject SQL.

`$date` is a column name or an expression (`fn (Expression $e) => $e->now()`). `$amount` is an
`int`, bound as a parameter, or an expression, for example another column:

```php
// extend every subscription by a month
$db->update('subscriptions')->where('id')->is(7)
    ->set(['expires_at' => fn (Expression $e) => $e->dateAdd('expires_at', 1, Interval::Month)]);

// trials whose own length has passed
$db->from('trials')
    ->where('started_at')->lt(fn (Expression $e) => $e->dateSub(fn (Expression $n) => $n->now(), Expression::fromColumn('trial_days'), Interval::Day))
    ->select();
```
```sql
UPDATE `subscriptions` SET `expires_at` = DATE_ADD(`expires_at`, INTERVAL 1 MONTH) WHERE `id` = 7
SELECT * FROM `trials` WHERE `started_at` < DATE_SUB(NOW(), INTERVAL (`trial_days`) DAY)
```

## Per database

| | MySQL / MariaDB | PostgreSQL | SQLite | SQL Server |
|---|---|---|---|---|
| `now()` | `NOW()` | `NOW()` | `datetime('now')` | `GETDATE()` |
| `currentDate()` | `CURRENT_DATE` | `CURRENT_DATE` | `CURRENT_DATE` | `CAST(GETDATE() AS DATE)` |
| `ago(4, Day)` | `DATE_SUB(NOW(), INTERVAL 4 DAY)` | `(NOW() - make_interval(days => 4))` | `datetime(datetime('now'), '-4 days')` | `DATEADD(day, -4, GETDATE())` |
| `dateAdd('d', 1, Month)` | `DATE_ADD(d, INTERVAL 1 MONTH)` | `(d + make_interval(months => 1))` | `datetime(d, '1 months')` | `DATEADD(month, 1, d)` |

Differences worth knowing:

- **Time zone.** `NOW()` on MySQL uses the session time zone; SQLite's `datetime('now')` is
  always UTC. Store UTC, or set the MySQL session zone with an
  [init command](connections.md#commands-run-after-connecting).
- **SQLite** date results are `YYYY-MM-DD HH:MM:SS` text. Comparing them with a `DATE` column
  that holds `YYYY-MM-DD` compares strings, so wrap the column in `date()` if it has no time.
- **Month arithmetic** at the end of a month differs: MySQL and PostgreSQL clamp Jan 31 + 1
  month to Feb 28/29; SQLite normalizes it to early March.

## Other date functions

Anything else is available through [`call()`](expressions.md#calling-sql-functions):

```php
$db->from('users')->select([
    'joined' => fn (Expression $e) => $e->call('DATE_FORMAT', Expression::fromColumn('created_at'), '%Y-%m'),
    'ts' => fn (Expression $e) => $e->call('UNIX_TIMESTAMP', Expression::fromColumn('created_at')),
]);
```
