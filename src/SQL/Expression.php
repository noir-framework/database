<?php

/* ===========================================================================
 * Copyright 2018 Zindex Software
 * Copyright 2026 noir-framework
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * ============================================================================ */

declare(strict_types=1);

namespace Noirapi\Database\SQL;

use Closure;
use InvalidArgumentException;
use LogicException;
use Noirapi\Database\SQL\Clause\AggregateFunction;
use Noirapi\Database\SQL\Clause\AggregateName;
use Noirapi\Database\SQL\Clause\CallPart;
use Noirapi\Database\SQL\Clause\ColumnPart;
use Noirapi\Database\SQL\Clause\ExpressionPart;
use Noirapi\Database\SQL\Clause\FunctionName;
use Noirapi\Database\SQL\Clause\GroupPart;
use Noirapi\Database\SQL\Clause\OperatorPart;
use Noirapi\Database\SQL\Clause\SqlFunction;
use Noirapi\Database\SQL\Clause\SubqueryPart;
use Noirapi\Database\SQL\Clause\ValuePart;

use function array_map;
use function array_values;
use function count;
use function is_array;
use function preg_match;

/**
 * A raw SQL expression assembled from columns, operators, values and functions.
 *
 * Any undefined property read appends that name as an operator, so
 * `$expr->column('a')->{'+'}->value(1)` produces `"a" + ?`, and any undefined method call
 * appends a function call, so `$expr->COALESCE(fn ($e) => $e->column('a'), 0)` produces
 * `COALESCE("a", ?)`.
 *
 * @psalm-type ColumnArg = string|Expression|(Closure(Expression): mixed)
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Creates every expression token type.
 */
class Expression
{
    /** @var list<ExpressionPart> */
    protected array $expressions = [];

    /**
     * @param Closure(Expression): mixed $func
     */
    public static function fromClosure(Closure $func): self
    {
        $expression = new self();
        $func($expression);

        return $expression;
    }

    public static function fromColumn(string $column): self
    {
        return (new self())->column($column);
    }

    /**
     * @throws InvalidArgumentException When the function name is not a plain identifier
     */
    public static function fromCall(string $func, mixed ...$args): self
    {
        return (new self())->call($func, ...$args);
    }

    /**
     * @return list<ExpressionPart>
     */
    public function getExpressions(): array
    {
        return $this->expressions;
    }

    public function column(string|self $value): static
    {
        return $this->addExpression(new ColumnPart($value));
    }

    public function op(string $value): static
    {
        return $this->addExpression(new OperatorPart($value));
    }

    public function value(mixed $value): static
    {
        return $this->addExpression(new ValuePart($value));
    }

    /**
     * @param Closure(Expression): mixed $closure
     */
    public function group(Closure $closure): static
    {
        return $this->addExpression(new GroupPart(self::fromClosure($closure)));
    }

    /**
     * @param string|array<int|string, string> $tables
     */
    public function from(string|array $tables): SelectStatement
    {
        $subquery = new Subquery();
        $this->addExpression(new SubqueryPart($subquery));

        return $subquery->from($tables);
    }

    /**
     * @param ColumnArg|list<ColumnArg> $column
     */
    public function count(string|self|Closure|array $column = '*', bool $distinct = false): static
    {
        $columns = is_array($column) ? array_map(self::normalize(...), $column) : [self::normalize($column)];
        if ($columns === []) {
            $columns = ['*'];
        }

        return $this->addExpression(
            new AggregateFunction(AggregateName::Count, $columns, $distinct || count($columns) > 1),
        );
    }

    /**
     * @param ColumnArg $column
     */
    public function sum(string|self|Closure $column, bool $distinct = false): static
    {
        return $this->aggregate(AggregateName::Sum, $column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function avg(string|self|Closure $column, bool $distinct = false): static
    {
        return $this->aggregate(AggregateName::Avg, $column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function max(string|self|Closure $column, bool $distinct = false): static
    {
        return $this->aggregate(AggregateName::Max, $column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function min(string|self|Closure $column, bool $distinct = false): static
    {
        return $this->aggregate(AggregateName::Min, $column, $distinct);
    }

    /**
     * @param ColumnArg $column
     */
    public function ucase(string|self|Closure $column): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::Ucase, self::normalize($column)));
    }

    /**
     * @param ColumnArg $column
     */
    public function lcase(string|self|Closure $column): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::Lcase, self::normalize($column)));
    }

    /**
     * @param ColumnArg $column
     */
    public function mid(string|self|Closure $column, int $start = 1, int $length = 0): static
    {
        return $this->addExpression(
            new SqlFunction(FunctionName::Mid, self::normalize($column), start: $start, length: $length),
        );
    }

    /**
     * @param ColumnArg $column
     */
    public function len(string|self|Closure $column): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::Len, self::normalize($column)));
    }

    /**
     * @param ColumnArg $column
     */
    public function round(string|self|Closure $column, int $decimals = 0): static
    {
        return $this->addExpression(
            new SqlFunction(FunctionName::Round, self::normalize($column), decimals: $decimals),
        );
    }

    public function now(): static
    {
        return $this->addExpression(new SqlFunction(FunctionName::Now));
    }

    /**
     * @param ColumnArg $column
     */
    public function format(string|self|Closure $column, mixed $format): static
    {
        return $this->addExpression(
            new SqlFunction(FunctionName::Format, self::normalize($column), format: $format),
        );
    }

    /**
     * Appends a call to an SQL function. Arguments that are expressions (or closures building one)
     * are inlined; anything else is bound as a parameter, so columns must be passed as
     * `fn (Expression $e) => $e->column('name')` or `Expression::fromColumn('name')`.
     *
     * @throws InvalidArgumentException When the function name is not a plain identifier
     */
    public function call(string $func, mixed ...$args): static
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $func) !== 1) {
            throw new InvalidArgumentException('Invalid SQL function name: ' . $func);
        }

        return $this->addExpression(new CallPart($func, array_map(self::argument(...), array_values($args))));
    }

    /**
     * Unknown methods are SQL function calls: `$expr->COALESCE(...)` is `$expr->call('COALESCE', ...)`.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @throws InvalidArgumentException When the function name is not a plain identifier
     */
    public function __call(string $name, array $arguments): static
    {
        return $this->call($name, ...array_values($arguments));
    }

    /**
     * @throws LogicException Always: property writes would silently be lost
     */
    public function __set(string $name, mixed $value): void
    {
        throw new LogicException('Cannot set property "' . $name . '" on ' . self::class);
    }

    /**
     * Appends the property name as an operator, e.g. `$expr->{'*'}`.
     */
    public function __get(string $value): static
    {
        return $this->addExpression(new OperatorPart($value));
    }

    /**
     * @param ColumnArg $column
     */
    protected function aggregate(AggregateName $name, string|self|Closure $column, bool $distinct): static
    {
        return $this->addExpression(new AggregateFunction($name, [self::normalize($column)], $distinct));
    }

    protected function addExpression(ExpressionPart $part): static
    {
        $this->expressions[] = $part;

        return $this;
    }

    /**
     * Closures become expressions; anything else is a value.
     */
    private static function argument(mixed $arg): mixed
    {
        if ($arg instanceof Closure) {
            /** @var Closure(Expression): mixed $arg */
            return self::fromClosure($arg);
        }

        return $arg;
    }

    /**
     * @param ColumnArg $column
     */
    private static function normalize(string|self|Closure $column): string|self
    {
        return $column instanceof Closure ? self::fromClosure($column) : $column;
    }
}
