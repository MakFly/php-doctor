<?php

declare(strict_types=1);

namespace PhpDoctor\Rules\Laravel;

use PhpDoctor\Analysis\Ast\ParserPool;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Rule;
use PhpDoctor\Core\Rule\Severity;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use PhpDoctor\Analysis\Ast\Visitors\AbstractRuleVisitor;

/**
 * Heuristic detection of N+1 queries in Eloquent models.
 *
 * NOTE: This is an IMPERFECT HEURISTIC. False positives are expected when:
 *  - The collection was loaded with eager-loading outside the visible scope.
 *  - The relation access is actually a property (not a DB query).
 *  - The foreach variable is not an Eloquent model.
 *
 * The rule fires when ALL of the following are true:
 *  1. There is a foreach loop.
 *  2. The iterated expression does NOT have an immediately-prior ->with(...)
 *     call chained on it (checked on the expression only, no flow analysis).
 *  3. The loop body accesses a property or method on the iteration variable
 *     that looks like an Eloquent relation (not id, *_id, *_at, count, etc.).
 */
final class EloquentNPlusOneRule implements Rule
{
    public function __construct(
        private readonly ParserPool $parserPool,
    ) {}

    public function id(): string
    {
        return 'laravel.perf.eloquent-n-plus-one';
    }

    public function category(): Category
    {
        return Category::Performance;
    }

    public function severity(): Severity
    {
        return Severity::High;
    }

    public function appliesTo(FrameworkContext $ctx): bool
    {
        return $ctx->framework === Framework::Laravel;
    }

    /**
     * @return iterable<Finding>
     */
    public function analyze(AnalysisInput $input): iterable
    {
        if ($input->ast === null) {
            return;
        }

        foreach ($input->ast->entries() as $file => $stmts) {
            if ($stmts === null) {
                continue;
            }

            $bag     = new FindingBag();
            $visitor = new EloquentNPlusOneVisitor($bag, $file);

            $traverser = $this->parserPool->newTraverser();
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            foreach ($bag->all() as $finding) {
                yield $finding;
            }
        }
    }
}

/**
 * @internal
 */
final class EloquentNPlusOneVisitor extends AbstractRuleVisitor
{
    /** Properties/methods that are NOT Eloquent relations. */
    private const NON_RELATION_NAMES = [
        'id', 'count', 'first', 'last', 'save', 'delete', 'fill',
        'where', 'find', 'all', 'get', 'paginate', 'toArray', 'toJson',
    ];

    /** Suffixes that indicate a column name, not a relation. */
    private const NON_RELATION_SUFFIXES = ['_id', '_at', '_by', '_type', '_count'];

    public function enterNode(Node $node): ?int
    {
        if (!($node instanceof Node\Stmt\Foreach_)) {
            return null;
        }

        // Check if the iterated expression has ->with(...) chained (eagerly loaded)
        if ($this->hasWithCall($node->expr)) {
            return null;
        }

        // Find the iteration variable name
        $iterVar = $node->valueVar;
        if (!($iterVar instanceof Node\Expr\Variable) || !is_string($iterVar->name)) {
            return null;
        }

        $varName = $iterVar->name;

        // Look for suspect relation accesses in the loop body
        foreach ($node->stmts as $stmt) {
            $accessNode = $this->findRelationAccess($stmt, $varName);
            if ($accessNode !== null) {
                $accessName = $this->getAccessName($accessNode);
                $this->emit(
                    'laravel.perf.eloquent-n-plus-one',
                    Severity::High,
                    Category::Performance,
                    "Potential N+1 query: '\${$varName}->{$accessName}' accessed inside foreach without eager-loading.",
                    $accessNode->getStartLine(),
                    "Préchargez la relation: ->with('{$accessName}')",
                );
                return null; // one finding per foreach is enough
            }
        }

        return null;
    }

    /**
     * Check if the expression has a ->with(...) call anywhere in its chain.
     * Handles both instance-call chains (->with(...)) and mixed chains like
     * Model::with('rel')->get().
     */
    private function hasWithCall(Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\MethodCall) {
            if ($expr->name instanceof Node\Identifier && $expr->name->name === 'with') {
                return true;
            }
            return $this->hasWithCall($expr->var);
        }

        if ($expr instanceof Node\Expr\StaticCall) {
            if ($expr->name instanceof Node\Identifier && $expr->name->name === 'with') {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively find a property/method access on $varName that looks like a relation.
     */
    private function findRelationAccess(Node $node, string $varName): ?Expr
    {
        if ($node instanceof Node\Expr\PropertyFetch
            || $node instanceof Node\Expr\MethodCall
        ) {
            $var = $node instanceof Node\Expr\PropertyFetch ? $node->var : $node->var;
            if ($var instanceof Node\Expr\Variable
                && is_string($var->name)
                && $var->name === $varName
            ) {
                $name = $this->getAccessName($node);
                if ($name !== null && $this->looksLikeRelation($name)) {
                    return $node;
                }
            }
        }

        // Recurse into child nodes
        foreach ($node->getSubNodeNames() as $subName) {
            $sub = $node->$subName;
            if ($sub instanceof Node) {
                $found = $this->findRelationAccess($sub, $varName);
                if ($found !== null) {
                    return $found;
                }
            } elseif (is_array($sub)) {
                foreach ($sub as $child) {
                    if ($child instanceof Node) {
                        $found = $this->findRelationAccess($child, $varName);
                        if ($found !== null) {
                            return $found;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function getAccessName(Expr $node): ?string
    {
        if ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            return $node->name->name;
        }
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            return $node->name->name;
        }
        return null;
    }

    private function looksLikeRelation(string $name): bool
    {
        // Skip known non-relation names
        if (in_array($name, self::NON_RELATION_NAMES, true)) {
            return false;
        }

        // Skip column suffixes
        foreach (self::NON_RELATION_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return false;
            }
        }

        // Must be camelCase or a plural word (simple: has at least 3 chars)
        return strlen($name) >= 3;
    }
}
