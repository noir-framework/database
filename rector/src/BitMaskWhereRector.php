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

namespace Noirapi\Database\Rector;

use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression as ExpressionStmt;
use PhpParser\Node\Stmt\Return_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

use function count;
use function in_array;
use function strtolower;

/**
 * `->where(fn (Expression $e) => $e->column(C)->op('&')->value(M), true)->is(0)` becomes
 * `->where(C)->hasNoBits(M)`, and `->isNot(0)` / `->ne(0)` becomes `->hasAnyBits(M)`.
 *
 * @SuppressWarnings("PHPMD.CyclomaticComplexity") AST matching is a chain of guard clauses.
 * @SuppressWarnings("PHPMD.NPathComplexity") AST matching is a chain of guard clauses.
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") AST matching is a chain of guard clauses.
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Matches many PhpParser node types.
 */
final class BitMaskWhereRector extends AbstractRector
{
    private const array WHERE = ['where', 'andwhere', 'orwhere'];

    private const array TESTS = [
        'is' => 'hasNoBits',
        'eq' => 'hasNoBits',
        'isnot' => 'hasAnyBits',
        'ne' => 'hasAnyBits',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Use hasNoBits() / hasAnyBits() instead of a hand-built bit mask expression', [
            new CodeSample(
                '$q->where(fn (Expression $e) => $e->column(\'flags\')->op(\'&\')->value(4), true)->is(0);',
                '$q->where(\'flags\')->hasNoBits(4);',
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    #[Override]
    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    #[Override]
    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof MethodCall) {
            return null;
        }

        $test = $this->methodName($node);
        if ($test === null || !isset(self::TESTS[$test]) || !$this->isZero($node)) {
            return null;
        }

        $where = $node->var;
        if (!$where instanceof MethodCall || !in_array($this->methodName($where), self::WHERE, true)) {
            return null;
        }

        $args = $where->getArgs();
        if (count($args) < 1 || count($args) > 2) {
            return null;
        }

        $closure = $args[0]->value;
        if (!$closure instanceof Closure && !$closure instanceof ArrowFunction) {
            return null;
        }

        if (count($args) === 2 ? !$this->isTrue($args[1]->value) : !$this->isExpressionTyped($closure)) {
            return null;
        }

        $mask = $this->matchMask($closure);
        if ($mask === null) {
            return null;
        }

        // edit the existing nodes, so Rector keeps the original formatting of the chain
        [$column, $value] = $mask;
        $where->args = [new Arg($column)];
        $node->name = new Identifier(self::TESTS[$test]);
        $node->args = [new Arg($value)];

        return $node;
    }

    private function methodName(MethodCall $call): ?string
    {
        return $call->name instanceof Identifier ? strtolower($call->name->toString()) : null;
    }

    private function isZero(MethodCall $call): bool
    {
        $args = $call->getArgs();

        return count($args) === 1 && $args[0]->value instanceof Int_ && $args[0]->value->value === 0;
    }

    private function isTrue(Expr $expr): bool
    {
        return $expr instanceof ConstFetch && strtolower($expr->name->toString()) === 'true';
    }

    /**
     * Without `true`, only a closure whose parameter is typed Expression is an expression.
     */
    private function isExpressionTyped(Closure|ArrowFunction $closure): bool
    {
        $type = $closure->params[0]->type ?? null;

        return $type instanceof Name && $type->getLast() === 'Expression';
    }

    /**
     * `$p->column(C)->op('&')->value(M)` (or `->{'&'}`) as the closure's only statement.
     *
     * @return array{Expr, Expr}|null [C, M]
     */
    private function matchMask(Closure|ArrowFunction $closure): ?array
    {
        if (count($closure->params) !== 1 || !$closure->params[0]->var instanceof Variable) {
            return null;
        }

        $body = $closure instanceof ArrowFunction ? $closure->expr : $this->singleExpression($closure);
        if (!$body instanceof MethodCall || $this->methodName($body) !== 'value' || count($body->getArgs()) !== 1) {
            return null;
        }

        $and = $body->var;
        if (!$this->isAndOperator($and) || !$and instanceof MethodCall && !$and instanceof PropertyFetch) {
            return null;
        }

        $column = $and->var;
        if (
            !$column instanceof MethodCall || $this->methodName($column) !== 'column' || count($column->getArgs()) !== 1
            || !$column->var instanceof Variable || $column->var->name !== $closure->params[0]->var->name
        ) {
            return null;
        }

        return [$column->getArgs()[0]->value, $body->getArgs()[0]->value];
    }

    /**
     * `->op('&')` or `->{'&'}`.
     */
    private function isAndOperator(Expr $expr): bool
    {
        if ($expr instanceof PropertyFetch) {
            return $this->propertyName($expr) === '&';
        }

        if (!$expr instanceof MethodCall || $this->methodName($expr) !== 'op' || count($expr->getArgs()) !== 1) {
            return false;
        }

        $operator = $expr->getArgs()[0]->value;

        return $operator instanceof String_ && $operator->value === '&';
    }

    /**
     * `->{'&'}` has a string expression as its name, `->foo` an identifier.
     */
    private function propertyName(PropertyFetch $fetch): ?string
    {
        return match (true) {
            $fetch->name instanceof Identifier => $fetch->name->toString(),
            $fetch->name instanceof String_ => $fetch->name->value,
            default => null,
        };
    }

    private function singleExpression(Closure $closure): ?Expr
    {
        if (count($closure->stmts) !== 1) {
            return null;
        }

        $stmt = $closure->stmts[0];

        return $stmt instanceof ExpressionStmt || $stmt instanceof Return_ ? $stmt->expr : null;
    }
}
