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
 * NOTE: No type analysis — false positives are possible when the receiver is
 * not an Eloquent model. The fixHint explains the safe alternatives.
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

        foreach ($input->ast->entries() as $file => $stmts) {
            if ($stmts === null) {
                continue;
            }

            $bag     = new FindingBag();
            $visitor = new MassAssignmentVisitor($bag, $file);

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
final class MassAssignmentVisitor extends AbstractRuleVisitor
{
    private const DANGEROUS_METHODS = ['create', 'fill', 'update'];

    public function enterNode(Node $node): ?int
    {
        // Static call: Model::create($request->all())
        if ($node instanceof Node\Expr\StaticCall
            && $node->name instanceof Node\Identifier
            && in_array($node->name->name, self::DANGEROUS_METHODS, true)
            && $this->firstArgIsAllCall($node->args)
        ) {
            $method = $node->name->name;
            $this->emit(
                'laravel.security.mass-assignment',
                Severity::High,
                Category::Security,
                "Unsafe mass-assignment: ::{$method}(\$request->all()) — verify that the model has a \$fillable whitelist.",
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
            $method = $node->name->name;
            $this->emit(
                'laravel.security.mass-assignment',
                Severity::High,
                Category::Security,
                "Unsafe mass-assignment: ->{$method}(\$request->all()) — verify that the model has a \$fillable whitelist.",
                $node->getStartLine(),
                "Use \$request->only([...]) or define a \$fillable whitelist on the model",
            );
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
