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
 *     call chained on it, AND no prior variable assignment in the same scope
 *     includes ->with(...) for the same variable.
 *  3. The loop body accesses a property or method on the iteration variable
 *     that looks like an Eloquent relation (not a common column name or a
 *     name matching a non-relation suffix).
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
    /**
     * Common Eloquent column names that are NOT relations.
     * Accessing $model->name, $model->email, etc. is reading a DB column, not
     * triggering a lazy-loaded relation query.
     */
    private const COLUMN_BLACKLIST = [
        'id', 'name', 'title', 'email', 'password', 'status', 'type',
        'slug', 'content', 'body', 'description', 'label', 'value', 'key',
        'code', 'number', 'count', 'total', 'amount', 'price', 'date',
        'time', 'year', 'month', 'day', 'address', 'city', 'country',
        'phone', 'url', 'link', 'path', 'file', 'image', 'photo',
        'avatar', 'color', 'icon', 'message', 'note', 'comment', 'tag',
        'role', 'level', 'score', 'rank', 'order', 'sort', 'position',
        'active', 'enabled', 'visible', 'public', 'hidden', 'deleted',
        'archived', 'published', 'completed', 'verified', 'confirmed',
        'locked', 'draft', 'pending', 'approved', 'rejected', 'paid',
        'sent', 'received', 'read', 'unread',
    ];

    /**
     * Known non-relation names (query builder methods / plain accessors).
     */
    private const NON_RELATION_NAMES = [
        'id', 'count', 'first', 'last', 'save', 'delete', 'fill',
        'where', 'find', 'all', 'get', 'paginate', 'toArray', 'toJson',
    ];

    /**
     * Suffixes that indicate a column name rather than a relation.
     * "_id" → FK column, "_at" → timestamp, "_url", "_path", etc.
     */
    private const NON_RELATION_SUFFIXES = [
        '_id', '_at', '_by', '_type', '_count', '_url', '_path', '_name',
        '_key', '_code', '_hash', '_token', '_slug', '_date', '_time',
        '_flag', '_status',
    ];

    /**
     * Map of variable-name → set of eager-loaded relation names discovered
     * in the current statement list (populated by scanEagerLoads()).
     *
     * @var array<string, array<string, true>>
     */
    private array $eagerLoaded = [];

    /**
     * @param Node[] $stmts
     */
    public function beforeTraverse(array $stmts): ?array
    {
        // Scan the top-level statements once before visiting nodes.
        // This fills $this->eagerLoaded for all assignments we can see.
        $this->scanEagerLoads($stmts);
        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if (!($node instanceof Node\Stmt\Foreach_)) {
            return null;
        }

        // Check if the iterated expression has ->with(...) chained (eagerly loaded inline)
        if ($this->hasWithCall($node->expr)) {
            return null;
        }

        // Collect the name of the variable being iterated over (e.g. $posts → "posts")
        $iterCollectionVar = null;
        if ($node->expr instanceof Node\Expr\Variable && is_string($node->expr->name)) {
            $iterCollectionVar = $node->expr->name;
        }

        // Find the iteration variable name (e.g. $post → "post")
        $iterVar = $node->valueVar;
        if (!($iterVar instanceof Node\Expr\Variable) || !is_string($iterVar->name)) {
            return null;
        }

        $varName = $iterVar->name;

        // Determine which relations are already eager-loaded for this collection
        $preloaded = ($iterCollectionVar !== null && isset($this->eagerLoaded[$iterCollectionVar]))
            ? $this->eagerLoaded[$iterCollectionVar]
            : [];

        // Look for suspect relation accesses in the loop body
        foreach ($node->stmts as $stmt) {
            $accessNode = $this->findRelationAccess($stmt, $varName);
            if ($accessNode !== null) {
                $accessName = $this->getAccessName($accessNode);

                // Skip if this relation was already eager-loaded upstream
                if ($accessName !== null && isset($preloaded[$accessName])) {
                    return null;
                }

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

    // -------------------------------------------------------------------------
    // Eager-load scanning
    // -------------------------------------------------------------------------

    /**
     * Scan a list of statements for patterns like:
     *   $var = Foo::with('rel1', 'rel2')->...->get();
     *   $var = $query->with(['rel1', 'rel2'])->get();
     *
     * Fills $this->eagerLoaded[$varName] with the set of relation names found.
     *
     * @param Node[] $stmts
     */
    private function scanEagerLoads(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            if (!($stmt instanceof Node\Stmt\Expression)) {
                continue;
            }
            $expr = $stmt->expr;
            if (!($expr instanceof Node\Expr\Assign)) {
                continue;
            }

            $lhs = $expr->var;
            if (!($lhs instanceof Node\Expr\Variable) || !is_string($lhs->name)) {
                continue;
            }

            $varName   = $lhs->name;
            $relations = $this->extractWithRelations($expr->expr);
            if ($relations !== []) {
                $this->eagerLoaded[$varName] = array_fill_keys($relations, true);
            }
        }
    }

    /**
     * Walk a call-chain expression and collect all relation names passed to
     * ->with() or ::with() calls found anywhere in the chain.
     *
     * @return string[]
     */
    private function extractWithRelations(Expr $expr): array
    {
        $relations = [];

        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall) {
            $name = $expr->name instanceof Node\Identifier ? $expr->name->name : null;

            if ($name === 'with') {
                foreach ($expr->args as $arg) {
                    if (!($arg instanceof Node\Arg)) {
                        continue;
                    }
                    // with('relation') — single string
                    if ($arg->value instanceof Node\Scalar\String_) {
                        $relations[] = $arg->value->value;
                    }
                    // with(['rel1', 'rel2']) — array of strings
                    if ($arg->value instanceof Node\Expr\Array_) {
                        foreach ($arg->value->items as $item) {
                            if ($item->value instanceof Node\Scalar\String_) {
                                $relations[] = $item->value->value;
                            }
                        }
                    }
                }
            }

            // Recurse into the left-hand side of the chain
            $innerExpr = $expr instanceof Node\Expr\MethodCall ? $expr->var : $expr->class;
            if ($innerExpr instanceof Expr) {
                $relations = array_merge($relations, $this->extractWithRelations($innerExpr));
            }
        }

        return $relations;
    }

    // -------------------------------------------------------------------------
    // Relation detection
    // -------------------------------------------------------------------------

    /**
     * Check if the expression has a ->with(...) call anywhere in its chain.
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
            $var = $node->var;
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

    /**
     * Multi-criteria heuristic deciding whether $name looks like an Eloquent
     * relation rather than a plain scalar column.
     *
     * Decision tree:
     *  1. Known non-relation names (query-builder methods, etc.) → false
     *  2. Known common column names (blacklist) → false
     *  3. Suffix matches a non-relation column suffix → false
     *  4. At least 4 chars and not filtered above → true
     *     (covers both camelCase relations like userProfile and plain ones like author)
     *  5. Otherwise → false (too short / ambiguous)
     *
     * The blacklist in step 2 is the primary guard against false positives on
     * scalar columns. Step 4 is deliberately broad because any word that
     * survived the blacklist + suffix filters and has ≥ 4 chars is more likely
     * a relation than a plain column.
     */
    private function looksLikeRelation(string $name): bool
    {
        // 1. Skip known non-relation query-builder names
        if (in_array($name, self::NON_RELATION_NAMES, true)) {
            return false;
        }

        // 2. Skip names that are common Eloquent column names
        if (in_array(strtolower($name), self::COLUMN_BLACKLIST, true)) {
            return false;
        }

        // 3. Skip column suffixes
        foreach (self::NON_RELATION_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return false;
            }
        }

        // 4. At least 4 chars → likely a relation (author, comments, userProfile, …)
        return strlen($name) >= 4;
    }
}
