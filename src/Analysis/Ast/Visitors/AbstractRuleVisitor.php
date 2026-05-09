<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Ast\Visitors;

use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;
use PhpParser\NodeVisitorAbstract;

/**
 * Base visitor for AST-based rules. Subclasses override enterNode()/leaveNode()
 * and call emit() to record findings.
 */
abstract class AbstractRuleVisitor extends NodeVisitorAbstract
{
    public function __construct(
        protected readonly FindingBag $bag,
        protected readonly string     $currentFile,
    ) {}

    /**
     * Convenience helper: build a Finding and add it to the bag.
     */
    protected function emit(
        string   $ruleId,
        Severity $severity,
        Category $category,
        string   $message,
        ?int     $line     = null,
        ?string  $fixHint  = null,
        ?string  $docUrl   = null,
    ): void {
        $this->bag->add(new Finding(
            ruleId:   $ruleId,
            severity: $severity,
            category: $category,
            message:  $message,
            file:     $this->currentFile,
            line:     $line,
            fixHint:  $fixHint,
            docUrl:   $docUrl,
        ));
    }
}
