<?php

declare(strict_types=1);

namespace PhpDoctor\Core\Rule;

use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Project\FrameworkContext;

interface Rule
{
    /** Unique rule identifier, e.g. 'laravel.eloquent.n-plus-one'. */
    public function id(): string;

    public function category(): Category;

    public function severity(): Severity;

    /** Whether this rule applies to the given project context. */
    public function appliesTo(FrameworkContext $ctx): bool;

    /**
     * Run the rule and yield zero or more Findings.
     *
     * @return iterable<Finding>
     */
    public function analyze(AnalysisInput $input): iterable;
}
