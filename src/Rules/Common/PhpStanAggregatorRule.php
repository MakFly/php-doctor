<?php

declare(strict_types=1);

namespace PhpDoctor\Rules\Common;

use PhpDoctor\Analysis\Runtime\PhpStan\PhpStanRunnerInterface;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Rule;
use PhpDoctor\Core\Rule\Severity;

/**
 * Aggregates PHPStan findings into the TypeSafety category.
 *
 * This rule is active only when PHPStan is installed in the audited project
 * (vendor/bin/phpstan must be executable). If PHPStan is absent, appliesTo()
 * returns false and the rule is silently skipped.
 *
 * TODO Phase 8: when PhpStan absent, mark Category::TypeSafety as N/A
 *               (currently shows 100, which is misleading).
 */
final class PhpStanAggregatorRule implements Rule
{
    public function __construct(
        private readonly PhpStanRunnerInterface $runner,
    ) {}

    public function id(): string
    {
        return 'phpstan.aggregate';
    }

    public function category(): Category
    {
        return Category::TypeSafety;
    }

    public function severity(): Severity
    {
        // Nominal severity of the rule itself.
        // Individual findings override this via deriveSeverity().
        return Severity::Medium;
    }

    public function appliesTo(FrameworkContext $ctx): bool
    {
        return $this->runner->isAvailable($ctx);
    }

    /**
     * @return iterable<Finding>
     */
    public function analyze(AnalysisInput $input): iterable
    {
        $report = $this->runner->run($input->ctx);

        if ($report === null) {
            return;
        }

        foreach ($report->messages as $message) {
            $identifier = $message['identifier'];

            yield new Finding(
                ruleId:   'phpstan.' . ($identifier ?? 'unknown'),
                severity: $this->deriveSeverity($identifier),
                category: Category::TypeSafety,
                message:  $message['message'],
                file:     $message['file'],
                line:     $message['line'],
                fixHint:  null,
                docUrl:   $message['tip'],
            );
        }
    }

    private function deriveSeverity(?string $identifier): Severity
    {
        if ($identifier === null) {
            return Severity::Low;
        }

        if (str_contains($identifier, '.error')) {
            return Severity::High;
        }

        if (str_contains($identifier, 'missingType') || str_contains($identifier, 'phpDoc')) {
            return Severity::Medium;
        }

        return Severity::Low;
    }
}
