<?php

declare(strict_types=1);

namespace Rector\Custom;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Name;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

use function count;
use function str_starts_with;

/**
 * Replaces redundant isset() + is_*() checks with a single null-coalesce call:
 *
 *   isset($x) && is_string($x)          → is_string($x ?? null)
 *   ! isset($x) || ! is_string($x)      → ! is_string($x ?? null)
 *
 * Works for any is_*() function (is_string, is_int, is_array, is_bool, …).
 */
final class IssetTypeCheckToNullCoalesceRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace isset($x) && is_*($x) / ! isset($x) || ! is_*($x) with is_*($x ?? null)',
            [
                new CodeSample(
                    'isset($data[\'key\']) && is_string($data[\'key\'])',
                    'is_string($data[\'key\'] ?? null)',
                ),
                new CodeSample(
                    '! isset($data[\'key\']) || ! is_string($data[\'key\'])',
                    '! is_string($data[\'key\'] ?? null)',
                ),
            ],
        );
    }

    /** @return array<class-string<Node>> */
    public function getNodeTypes(): array
    {
        return [BooleanAnd::class, BooleanOr::class];
    }

    public function refactor(Node $node): ?Node
    {
        if ($node instanceof BooleanAnd) {
            return $this->refactorPositive($node);
        }

        return $this->refactorNegative($node);
    }

    /**
     * isset($x) && is_*($x)  →  is_*($x ?? null)
     */
    private function refactorPositive(BooleanAnd $node): ?Node
    {
        $isset   = $this->matchIsset($node->left);
        $isCheck = $this->matchIsTypeCall($node->right);

        if ($isset === null || $isCheck === null) {
            return null;
        }

        if (! $this->nodeComparator->areNodesEqual($isset, $isCheck->args[0]->value)) {
            return null;
        }

        return $this->buildIsTypeWithCoalesce($isCheck, $isset);
    }

    /**
     * ! isset($x) || ! is_*($x)  →  ! is_*($x ?? null)
     */
    private function refactorNegative(BooleanOr $node): ?Node
    {
        if (! $node->left instanceof BooleanNot || ! $node->right instanceof BooleanNot) {
            return null;
        }

        $isset   = $this->matchIsset($node->left->expr);
        $isCheck = $this->matchIsTypeCall($node->right->expr);

        if ($isset === null || $isCheck === null) {
            return null;
        }

        if (! $this->nodeComparator->areNodesEqual($isset, $isCheck->args[0]->value)) {
            return null;
        }

        return new BooleanNot($this->buildIsTypeWithCoalesce($isCheck, $isset));
    }

    private function matchIsset(Node $node): ?Node
    {
        if (! $node instanceof Isset_ || count($node->vars) !== 1) {
            return null;
        }

        return $node->vars[0];
    }

    private function matchIsTypeCall(Node $node): ?FuncCall
    {
        if (
            ! $node instanceof FuncCall
            || ! $node->name instanceof Name
            || count($node->args) !== 1
            || ! $node->args[0] instanceof Arg
        ) {
            return null;
        }

        // Restrict to is_* functions to ensure semantic equivalence (is_*(null) === false).
        if (! str_starts_with($this->getName($node->name) ?? '', 'is_')) {
            return null;
        }

        return $node;
    }

    private function buildIsTypeWithCoalesce(FuncCall $isCheck, Node $var): FuncCall
    {
        $coalesce = new Coalesce($var, new ConstFetch(new Name('null')));

        return new FuncCall($isCheck->name, [new Arg($coalesce)]);
    }
}
