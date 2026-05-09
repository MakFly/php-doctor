<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Rules\Common;

use PhpDoctor\Analysis\Runtime\RuntimeSnapshot;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Rules\Common\ComposerAuditRule;
use PHPUnit\Framework\TestCase;

final class ComposerAuditRuleTest extends TestCase
{
    private ComposerAuditRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ComposerAuditRule();
    }

    private function makeInput(?array $composerAudit): AnalysisInput
    {
        $ctx = new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      '/fake',
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );

        $runtime = new RuntimeSnapshot(composerAudit: $composerAudit);
        return new AnalysisInput($ctx, null, $runtime);
    }

    // -------------------------------------------------------------------------
    // Positive tests
    // -------------------------------------------------------------------------

    public function testEmitsFindingForCriticalAdvisory(): void
    {
        $audit = [
            'advisories' => [
                'vendor/package' => [
                    [
                        'advisoryId' => 'PKSA-2024-001',
                        'title'      => 'Remote code execution',
                        'cve'        => 'CVE-2024-1234',
                        'severity'   => 'critical',
                    ],
                ],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($audit)));

        $this->assertCount(1, $findings);
        $this->assertSame('common.dependencies.composer-audit', $findings[0]->ruleId);
        $this->assertSame(Severity::Critical, $findings[0]->severity);
        $this->assertStringContainsString('vendor/package', $findings[0]->message);
        $this->assertStringContainsString('CVE-2024-1234', $findings[0]->message);
        $this->assertStringContainsString('Remote code execution', $findings[0]->message);
    }

    public function testEmitsFindingForHighAdvisory(): void
    {
        $audit = [
            'advisories' => [
                'some/lib' => [
                    [
                        'advisoryId' => 'PKSA-2024-002',
                        'title'      => 'SQL injection',
                        'cve'        => 'CVE-2024-5678',
                        'severity'   => 'high',
                    ],
                ],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($audit)));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Critical, $findings[0]->severity); // high → Critical
    }

    public function testSeverityMappingMediumToHigh(): void
    {
        $audit = [
            'advisories' => [
                'some/lib' => [
                    [
                        'advisoryId' => 'PKSA-2024-003',
                        'title'      => 'XSS',
                        'cve'        => 'N/A',
                        'severity'   => 'medium',
                    ],
                ],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($audit)));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::High, $findings[0]->severity);
    }

    public function testSeverityMappingLowToMedium(): void
    {
        $audit = [
            'advisories' => [
                'some/lib' => [
                    [
                        'advisoryId' => 'PKSA-2024-004',
                        'title'      => 'Info disclosure',
                        'cve'        => 'N/A',
                        'severity'   => 'low',
                    ],
                ],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($audit)));

        $this->assertSame(Severity::Medium, $findings[0]->severity);
    }

    public function testMultiplePackagesMultipleFindings(): void
    {
        $audit = [
            'advisories' => [
                'pkg/one' => [
                    ['advisoryId' => 'A1', 'title' => 'Bug one', 'cve' => 'CVE-1', 'severity' => 'high'],
                ],
                'pkg/two' => [
                    ['advisoryId' => 'A2', 'title' => 'Bug two', 'cve' => 'CVE-2', 'severity' => 'medium'],
                    ['advisoryId' => 'A3', 'title' => 'Bug three', 'cve' => 'CVE-3', 'severity' => 'low'],
                ],
            ],
        ];

        $findings = iterator_to_array($this->rule->analyze($this->makeInput($audit)));

        $this->assertCount(3, $findings);
    }

    // -------------------------------------------------------------------------
    // Negative tests
    // -------------------------------------------------------------------------

    public function testNoFindingsWhenAuditIsEmpty(): void
    {
        $audit    = ['advisories' => []];
        $findings = iterator_to_array($this->rule->analyze($this->makeInput($audit)));

        $this->assertEmpty($findings);
    }

    public function testNoFindingsWhenRuntimeIsNull(): void
    {
        $ctx = new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      '/fake',
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );
        $findings = iterator_to_array($this->rule->analyze(new AnalysisInput($ctx)));

        $this->assertEmpty($findings);
    }

    public function testNoFindingsWhenComposerAuditIsNull(): void
    {
        $findings = iterator_to_array($this->rule->analyze($this->makeInput(null)));

        $this->assertEmpty($findings);
    }
}
