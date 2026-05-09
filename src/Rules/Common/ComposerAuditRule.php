<?php

declare(strict_types=1);

namespace PhpDoctor\Rules\Common;

use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Rule;
use PhpDoctor\Core\Rule\Severity;

/**
 * Re-routes composer audit results as Findings.
 *
 * Reads the pre-collected $input->runtime->composerAudit snapshot.
 * Format: {"advisories": {"pkg/name": [{"advisoryId":..., "title":..., "cve":..., "severity": "..."}]}}
 *
 * Severity mapping:
 *   critical → Critical
 *   high     → Critical
 *   medium   → High
 *   low      → Medium
 *   other    → Low
 */
final class ComposerAuditRule implements Rule
{
    public function id(): string
    {
        return 'common.dependencies.composer-audit';
    }

    public function category(): Category
    {
        return Category::Dependencies;
    }

    public function severity(): Severity
    {
        // Default/nominal severity; individual findings may be higher or lower.
        return Severity::High;
    }

    public function appliesTo(FrameworkContext $ctx): bool
    {
        return true;
    }

    /**
     * @return iterable<Finding>
     */
    public function analyze(AnalysisInput $input): iterable
    {
        if ($input->runtime === null || $input->runtime->composerAudit === null) {
            return;
        }

        $audit       = $input->runtime->composerAudit;
        $advisories  = $audit['advisories'] ?? [];

        foreach ($advisories as $package => $packageAdvisories) {
            if (!is_array($packageAdvisories)) {
                continue;
            }
            foreach ($packageAdvisories as $advisory) {
                if (!is_array($advisory)) {
                    continue;
                }

                $title    = $advisory['title']    ?? 'Unknown vulnerability';
                $cve      = $advisory['cve']       ?? 'N/A';
                $severity = strtolower($advisory['severity'] ?? '');

                yield new Finding(
                    ruleId:   $this->id(),
                    severity: $this->mapSeverity($severity),
                    category: $this->category(),
                    message:  "Vulnerable dependency: {$package} — {$title} (CVE: {$cve})",
                    file:     null,
                    line:     null,
                    fixHint:  "Run `composer update {$package}` or check for a patched version.",
                );
            }
        }
    }

    private function mapSeverity(string $severity): Severity
    {
        return match ($severity) {
            'critical', 'high' => Severity::Critical,
            'medium'           => Severity::High,
            'low'              => Severity::Medium,
            default            => Severity::Low,
        };
    }
}
