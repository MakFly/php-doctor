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
        // We use a shared snapshot for the memoised cache but create a *fresh*
        // Collecting error handler per file so we can detect partial-AST errors
        // independently (a shared handler would accumulate errors across files
        // and make it impossible to know which file triggered each error).
        $sharedErrorHandler = new Collecting();
        $snapshot           = new AstSnapshot($this->pool, $sharedErrorHandler);

        foreach ($this->walker->walk($ctx) as $file) {
            $realPath = $file->getRealPath();

            // Per-file error handler so we can check hasErrors() after each parse.
            $fileErrorHandler = new Collecting();
            $fileSnapshot     = new AstSnapshot($this->pool, $fileErrorHandler);
            $stmts            = $fileSnapshot->forFile($realPath);

            // Propagate to the shared snapshot cache so callers get a unified view.
            $snapshot->prime($realPath, $stmts);

            if ($stmts === null) {
                // Hard parse failure (file unreadable or completely unparseable).
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

            // Also emit a finding when the parser returned a partial AST but
            // still collected syntax errors (previously these were silently dropped).
            if ($fileErrorHandler->hasErrors()) {
                foreach ($fileErrorHandler->getErrors() as $parseError) {
                    $bag->add(new Finding(
                        ruleId:   'php-doctor.parse-error',
                        severity: Severity::Info,
                        category: Category::Hygiene,
                        message:  'File has syntax errors: ' . $parseError->getMessage(),
                        file:     $realPath,
                        line:     $parseError->getStartLine() > 0 ? $parseError->getStartLine() : null,
                    ));
                }
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
