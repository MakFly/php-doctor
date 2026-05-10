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
use PhpParser\NodeVisitor\NameResolver;
use PhpDoctor\Analysis\Ast\Visitors\AbstractRuleVisitor;

/**
 * Detects unsafe mass-assignment patterns in Laravel controllers/services.
 *
 * Triggered by:
 *   Model::create($request->all())
 *   Model::create($req->all())
 *   $model->fill($request->all())
 *   $model->update($request->all())
 *
 * i.e., any call to create|fill|update whose first argument is a ->all() call
 * on any variable.
 *
 * Severity is adjusted based on the model's mass-assignment protection:
 *  - Critical : $guarded = [] found (explicit open door)
 *  - High     : no $fillable / $guarded defined (unknown, default behaviour)
 *  - Low      : $fillable with values found (model appears protected; may still
 *               be wrong if $fillable doesn't cover all fields from $request)
 *
 * When the model class cannot be resolved (called via variable, class not in
 * the AST cache), the finding is kept at High (original behaviour).
 */
final class MassAssignmentRule implements Rule
{
    public function __construct(
        private readonly ParserPool $parserPool,
    ) {}

    public function id(): string
    {
        return 'laravel.security.mass-assignment';
    }

    public function category(): Category
    {
        return Category::Security;
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

        // Build a map of short-class-name → FillableInfo so we can look up
        // $fillable / $guarded status for each model referenced in the code.
        $modelIndex = $this->buildModelIndex($input);

        foreach ($input->ast->entries() as $file => $stmts) {
            if ($stmts === null) {
                continue;
            }

            $bag     = new FindingBag();
            $visitor = new MassAssignmentVisitor($bag, $file, $modelIndex);

            $traverser = $this->parserPool->newTraverser();
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            foreach ($bag->all() as $finding) {
                yield $finding;
            }
        }
    }

    /**
     * Walk ALL files in the AST cache and extract fillable / guarded status
     * for every class that looks like an Eloquent model (extends Model or
     * has $fillable / $guarded properties).
     *
     * Returns: array<string, ModelFillableInfo>  (keyed by short class name)
     *
     * @return array<string, ModelFillableInfo>
     */
    private function buildModelIndex(AnalysisInput $input): array
    {
        assert($input->ast !== null);
        $index = [];

        foreach ($input->ast->entries() as $_file => $stmts) {
            if ($stmts === null) {
                continue;
            }
            $this->extractModelInfoFromStmts($stmts, $index);
        }

        return $index;
    }

    /**
     * @param Node[]                            $stmts
     * @param array<string, ModelFillableInfo>  $index
     */
    private function extractModelInfoFromStmts(array $stmts, array &$index): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $this->extractModelInfoFromStmts($stmt->stmts, $index);
                continue;
            }

            if (!($stmt instanceof Node\Stmt\Class_)) {
                continue;
            }

            $className = $stmt->name instanceof Node\Identifier ? $stmt->name->name : null;
            if ($className === null) {
                continue;
            }

            $info = $this->inspectClassForFillable($stmt);
            if ($info !== null) {
                $index[$className] = $info;
            }
        }
    }

    private function inspectClassForFillable(Node\Stmt\Class_ $class): ?ModelFillableInfo
    {
        $hasFillableNonEmpty = false;
        $hasGuardedEmpty     = false;

        foreach ($class->stmts as $member) {
            if (!($member instanceof Node\Stmt\Property)) {
                continue;
            }

            foreach ($member->props as $prop) {
                // $prop->name is Node\VarLikeIdentifier (extends Identifier, always non-null)
                $propName = $prop->name->name;

                if ($propName === 'fillable' && $prop->default !== null) {
                    // $fillable = ['name', 'email', ...] — non-empty array
                    if ($prop->default instanceof Node\Expr\Array_
                        && count($prop->default->items) > 0
                    ) {
                        $hasFillableNonEmpty = true;
                    }
                }

                if ($propName === 'guarded' && $prop->default !== null) {
                    // $guarded = [] — empty array (most permissive)
                    if ($prop->default instanceof Node\Expr\Array_
                        && count($prop->default->items) === 0
                    ) {
                        $hasGuardedEmpty = true;
                    }
                }
            }
        }

        // Only return info for classes that have at least one relevant property
        if (!$hasFillableNonEmpty && !$hasGuardedEmpty) {
            return null;
        }

        return new ModelFillableInfo(
            hasFillable:    $hasFillableNonEmpty,
            hasGuardedOpen: $hasGuardedEmpty,
        );
    }
}

/**
 * Value object holding the mass-assignment protection status of a model.
 *
 * @internal
 */
final class ModelFillableInfo
{
    public function __construct(
        /** True when $fillable is defined with at least one entry. */
        public readonly bool $hasFillable,
        /** True when $guarded = [] (no protection at all). */
        public readonly bool $hasGuardedOpen,
    ) {}
}

/**
 * @internal
 */
final class MassAssignmentVisitor extends AbstractRuleVisitor
{
    private const DANGEROUS_METHODS = ['create', 'fill', 'update'];

    /**
     * @param array<string, ModelFillableInfo> $modelIndex
     */
    public function __construct(
        FindingBag $bag,
        string $currentFile,
        private readonly array $modelIndex,
    ) {
        parent::__construct($bag, $currentFile);
    }

    public function enterNode(Node $node): ?int
    {
        // Static call: Model::create($request->all())
        if ($node instanceof Node\Expr\StaticCall
            && $node->name instanceof Node\Identifier
            && in_array($node->name->name, self::DANGEROUS_METHODS, true)
            && $this->firstArgIsAllCall($node->args)
        ) {
            $method    = $node->name->name;
            $modelName = $this->resolveStaticClass($node->class);
            $severity  = $this->resolveSeverity($modelName);

            $this->emit(
                'laravel.security.mass-assignment',
                $severity,
                Category::Security,
                $this->buildMessage($severity, $method, '::', $modelName),
                $node->getStartLine(),
                "Use \$request->only([...]) or define a \$fillable whitelist on the model",
            );
        }

        // Instance call: $model->fill($request->all()) / ->update($request->all())
        if ($node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && in_array($node->name->name, self::DANGEROUS_METHODS, true)
            && $this->firstArgIsAllCall($node->args)
        ) {
            $method   = $node->name->name;
            $severity = Severity::High; // instance calls: we can't easily resolve the class

            $this->emit(
                'laravel.security.mass-assignment',
                $severity,
                Category::Security,
                "Unsafe mass-assignment: ->{$method}(\$request->all()) — verify that the model has a \$fillable whitelist.",
                $node->getStartLine(),
                "Use \$request->only([...]) or define a \$fillable whitelist on the model",
            );
        }

        return null;
    }

    /**
     * Resolve severity based on the model's fillable/guarded status.
     */
    private function resolveSeverity(?string $modelName): Severity
    {
        if ($modelName === null || !isset($this->modelIndex[$modelName])) {
            // Model not found in index → keep High (unknown protection status)
            return Severity::High;
        }

        $info = $this->modelIndex[$modelName];

        if ($info->hasGuardedOpen) {
            // $guarded = [] → Critical: completely unprotected by design
            return Severity::Critical;
        }

        if ($info->hasFillable) {
            // $fillable defined → Low: model seems protected, but verify coverage
            return Severity::Low;
        }

        return Severity::High;
    }

    private function buildMessage(Severity $severity, string $method, string $op, ?string $modelName): string
    {
        $target = $modelName !== null ? "{$modelName}{$op}{$method}" : "{$op}{$method}";

        return match ($severity) {
            Severity::Critical => "CRITICAL mass-assignment: {$target}(\$request->all()) with \$guarded = [] — model is completely unprotected.",
            Severity::Low      => "Mass-assignment: {$target}(\$request->all()) — \$fillable is defined; verify it covers all expected fields.",
            default            => "Unsafe mass-assignment: {$target}(\$request->all()) — verify that the model has a \$fillable whitelist.",
        };
    }

    /**
     * Try to extract the short class name from a static-call class node.
     */
    private function resolveStaticClass(Node $classNode): ?string
    {
        if ($classNode instanceof Node\Name) {
            $parts = $classNode->getParts();
            return end($parts) ?: null;
        }
        return null;
    }

    /**
     * @param Node\Arg[]|Node\VariadicPlaceholder[] $args
     */
    private function firstArgIsAllCall(array $args): bool
    {
        if (count($args) === 0) {
            return false;
        }

        $first = $args[0];
        if (!($first instanceof Node\Arg)) {
            return false;
        }

        $expr = $first->value;

        // Check: $something->all()
        if ($expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && $expr->name->name === 'all'
        ) {
            return true;
        }

        return false;
    }
}
