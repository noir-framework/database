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
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

use function count;
use function preg_match;
use function strtolower;
use function strtoupper;

/**
 * `$e->op('FUNC(')->column(X)->op(')')` becomes `$e->call('FUNC', Expression::fromColumn(X))`, and
 * `->op('FUNC(')->value(V)->op(')')` becomes `->call('FUNC', V)`. The INET functions use their helpers.
 *
 * @SuppressWarnings("PHPMD.CyclomaticComplexity") AST matching is a chain of guard clauses.
 * @SuppressWarnings("PHPMD.NPathComplexity") AST matching is a chain of guard clauses.
 */
final class RawFunctionCallRector extends AbstractRector
{
    /** Helper => whether it takes a column (true) or a value (false). */
    private const array HELPERS = [
        'INET6_NTOA' => ['inet6Ntoa', true],
        'INET_NTOA' => ['inetNtoa', true],
        'INET6_ATON' => ['inet6Aton', false],
        'INET_ATON' => ['inetAton', false],
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Use call() / inet helpers instead of op(\'FUNC(\') ... op(\')\')', [
            new CodeSample(
                '$e->op(\'INET6_NTOA(\')->column(\'ip\')->op(\')\');',
                '$e->inet6Ntoa(\'ip\');',
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

        if ($this->opText($node) !== ')') {
            return null;
        }

        $inner = $node->var;
        if (!$inner instanceof MethodCall || !$inner->name instanceof Identifier || count($inner->getArgs()) !== 1) {
            return null;
        }

        $kind = strtolower($inner->name->toString());
        if ($kind !== 'column' && $kind !== 'value') {
            return null;
        }

        $open = $inner->var;
        $opening = $open instanceof MethodCall ? (string) $this->opText($open) : '';
        if (!$open instanceof MethodCall || preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\($/', $opening, $match) !== 1) {
            return null;
        }

        $function = strtoupper($match[1]);
        $argument = $inner->getArgs()[0]->value;

        if (isset(self::HELPERS[$function]) && self::HELPERS[$function][1] === ($kind === 'column')) {
            return new MethodCall($open->var, self::HELPERS[$function][0], [new Arg($argument)]);
        }

        return new MethodCall($open->var, 'call', [
            new Arg(new String_($match[1])),
            new Arg($kind === 'column' ? $this->fromColumn($argument) : $argument),
        ]);
    }

    private function opText(MethodCall $call): ?string
    {
        if (!$call->name instanceof Identifier || strtolower($call->name->toString()) !== 'op') {
            return null;
        }

        $args = $call->getArgs();

        return count($args) === 1 && $args[0]->value instanceof String_ ? $args[0]->value->value : null;
    }

    private function fromColumn(Expr $column): StaticCall
    {
        $expression = new FullyQualified('Noirapi\\Database\\SQL\\Expression');

        return new StaticCall($expression, 'fromColumn', [new Arg($column)]);
    }
}
