<?php

declare(strict_types=1);

namespace PhpDoctor\Analysis\Runtime\Composer;

use PhpDoctor\Analysis\Runtime\ProcessExecutorInterface;
use PhpDoctor\Core\Finding\Finding;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Category;
use PhpDoctor\Core\Rule\Severity;

/**
 * Runs `composer audit --format=json --locked` against the project.
 *
 * Requires Composer ≥ 2.4.0 (audit command was added in that version).
 * If Composer is absent or too old, emits a Finding::Info and returns null.
 *
 * Exit-code behaviour:
 *   - composer audit exits 0 when no vulnerabilities are found.
 *   - It exits 1 (or higher) when advisories are present.
 *   - Both exit codes are "valid" for JSON parsing — we treat them the same.
 *   - Any other failure (missing composer.lock, network error, etc.) will
 *     produce a non-JSON stdout; we detect that and emit a Finding.
 */
final class ComposerAuditor
{
    public function __construct(
        private readonly ProcessExecutorInterface $exec,
        private readonly FindingBag      $bag,
    ) {}

    /**
     * @return array<mixed>|null  Decoded audit JSON, or null if unavailable.
     */
    public function audit(FrameworkContext $ctx): ?array
    {
        // Step 1: verify composer is present and >= 2.4.0.
        $versionResult = $this->exec->run(
            ['composer', '--version'],
            $ctx->rootPath,
            5,
        );

        if (!$versionResult->isSuccessful) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.composer.audit-unavailable',
                severity: Severity::Info,
                category: Category::Dependencies,
                message:  'Composer not found or not executable — audit skipped.',
                file:     null,
                line:     null,
                fixHint:  $versionResult->stderr ?: null,
            ));
            return null;
        }

        if (!$this->isVersionSufficient($versionResult->stdout)) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.composer.audit-unavailable',
                severity: Severity::Info,
                category: Category::Dependencies,
                message:  'Composer < 2.4 — audit indisponible. Mettez à jour Composer pour activer composer audit.',
                file:     null,
                line:     null,
                fixHint:  'Installed: ' . trim($versionResult->stdout),
            ));
            return null;
        }

        // Step 2: run the audit.
        $auditResult = $this->exec->run(
            ['composer', 'audit', '--format=json', '--locked'],
            $ctx->rootPath,
        );

        // Exit codes 0 (no vulns) and 1+ (vulns found) are both parseable.
        // Only consider it a failure if stdout is not valid JSON.
        $decoded = json_decode($auditResult->stdout, true);
        if (!is_array($decoded)) {
            $this->bag->add(new Finding(
                ruleId:   'runtime.composer.audit-unavailable',
                severity: Severity::Info,
                category: Category::Dependencies,
                message:  'composer audit returned non-JSON output — audit results unavailable.',
                file:     null,
                line:     null,
                fixHint:  $auditResult->stderr ?: substr($auditResult->stdout, 0, 200) ?: null,
            ));
            return null;
        }

        return $decoded;
    }

    /**
     * Check whether the version string from `composer --version` is >= 2.4.0.
     *
     * Accepts strings like:
     *   "Composer version 2.7.2 2024-03-11 17:12:18"
     *   "Composer version 2.4.0 ..."
     */
    private function isVersionSufficient(string $versionOutput): bool
    {
        if (!preg_match('/Composer version (\d+)\.(\d+)\.(\d+)/i', $versionOutput, $m)) {
            return false;
        }

        $major = (int) $m[1];
        $minor = (int) $m[2];

        if ($major > 2) {
            return true;
        }
        if ($major === 2 && $minor >= 4) {
            return true;
        }

        return false;
    }
}
