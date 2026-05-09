<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Ast;

use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\NodeVisitor\NameResolver;

/**
 * Orchestrates AST parsing and traversal across all project source files.
 */
final class AstAnalyzer
{
    public function __construct(
        private readonly ParserPool  $pool,
        private readonly FileWalker  $walker,
    ) {}

    /**
     * Walk every PHP file, parse it, and traverse with NameResolver.
     * Rule visitors are wired in Phase 7.
     *
     * Returns the shared AstSnapshot so callers can inspect parsedFileCount().
     *
     * @param iterable<mixed> $rules Phase-7 rule visitors (unused until Phase 7)
     */
    public function analyze(FrameworkContext $ctx, FindingBag $bag, iterable $rules): AstSnapshot
    {
        // Shared snapshot so parsedFileCount() reflects the whole scan.
        $errorHandler = new Collecting();
        $snapshot     = new AstSnapshot($this->pool, $errorHandler);

        foreach ($this->walker->walk($ctx) as $file) {
            $realPath = $file->getRealPath();
            $stmts    = $snapshot->forFile($realPath);

            if ($stmts === null) {
                // Could not parse the file — emit an informational finding and continue.
                $bag->add(new Finding(
                    ruleId:   'php-doctor.parse-error',
                    severity: Severity::Info,
                    category: Category::Hygiene,
                    message:  'File could not be parsed',
                    file:     $realPath,
                    line:     null,
                ));
                continue;
            }

            // Traverse with NameResolver so downstream visitors have resolved names.
            // replaceNodes:false → adds resolvedName attributes without mutating nodes.
            $traverser = $this->pool->newTraverser();
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));

            // foreach ($rules as $rule) { ... Phase 7 }

            $traverser->traverse($stmts);
        }

        return $snapshot;
    }
}
