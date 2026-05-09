<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Runtime\Composer;

use PhpDoctor\Analysis\Runtime\Composer\ComposerAuditor;
use PhpDoctor\Analysis\Runtime\ProcessExecutorInterface;
use PhpDoctor\Analysis\Runtime\ProcessResult;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PHPUnit\Framework\TestCase;

final class ComposerAuditorTest extends TestCase
{
    private static string $fixtureRoot;

    public static function setUpBeforeClass(): void
    {
        self::$fixtureRoot = dirname(__DIR__, 3) . '/fixtures/projects/laravel-min';
    }

    private function makeCtx(): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Laravel,
            rootPath:      self::$fixtureRoot,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );
    }

    public function testReturnsNullWhenComposerNotFound(): void
    {
        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')->willReturn(new ProcessResult(
            exitCode: 127,
            stdout:   '',
            stderr:   'composer: command not found',
        ));

        $bag     = new FindingBag();
        $auditor = new ComposerAuditor($exec, $bag);
        $result  = $auditor->audit($this->makeCtx());

        $this->assertNull($result);

        $findings = iterator_to_array($bag->all());
        $this->assertCount(1, $findings);
        $this->assertSame('runtime.composer.audit-unavailable', $findings[0]->ruleId);
    }

    public function testReturnsNullWhenComposerTooOld(): void
    {
        $exec = $this->createMock(ProcessExecutorInterface::class);
        // First call: composer --version → old version
        $exec->method('run')->willReturn(new ProcessResult(
            exitCode: 0,
            stdout:   'Composer version 2.2.18 2022-08-20 11:38:40',
            stderr:   '',
        ));

        $bag     = new FindingBag();
        $auditor = new ComposerAuditor($exec, $bag);
        $result  = $auditor->audit($this->makeCtx());

        $this->assertNull($result);

        $findings = iterator_to_array($bag->all());
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('2.4', $findings[0]->message);
    }

    public function testReturnsDecodedAuditJsonWhenNoVulnerabilities(): void
    {
        $auditJson = json_encode([
            'advisories' => [],
            'abandoned'  => [],
        ]);

        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->expects($this->exactly(2))
             ->method('run')
             ->willReturnOnConsecutiveCalls(
                 new ProcessResult(exitCode: 0, stdout: 'Composer version 2.7.2 2024-03-11', stderr: ''),
                 new ProcessResult(exitCode: 0, stdout: $auditJson, stderr: ''),
             );

        $bag     = new FindingBag();
        $auditor = new ComposerAuditor($exec, $bag);
        $result  = $auditor->audit($this->makeCtx());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('advisories', $result);
        $this->assertCount(0, iterator_to_array($bag->all()));
    }

    public function testHandlesAuditWithVulnerabilities(): void
    {
        // composer audit exits 1 when vulnerabilities are found — JSON is still valid.
        $auditJson = json_encode([
            'advisories' => [
                'vendor/package' => [
                    ['advisoryId' => 'CVE-2024-0001', 'title' => 'Critical vulnerability'],
                ],
            ],
            'abandoned' => [],
        ]);

        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->expects($this->exactly(2))
             ->method('run')
             ->willReturnOnConsecutiveCalls(
                 new ProcessResult(exitCode: 0, stdout: 'Composer version 2.4.0 2023-01-01', stderr: ''),
                 // exit code 1 = vulnerabilities found — still parseable
                 new ProcessResult(exitCode: 1, stdout: $auditJson, stderr: ''),
             );

        $bag     = new FindingBag();
        $auditor = new ComposerAuditor($exec, $bag);
        $result  = $auditor->audit($this->makeCtx());

        // Should still return the decoded JSON even though exit code was 1
        $this->assertIsArray($result);
        $this->assertArrayHasKey('advisories', $result);
        $this->assertCount(0, iterator_to_array($bag->all()));
    }

    public function testEmitsFindingWhenAuditOutputIsNotJson(): void
    {
        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->expects($this->exactly(2))
             ->method('run')
             ->willReturnOnConsecutiveCalls(
                 new ProcessResult(exitCode: 0, stdout: 'Composer version 2.7.2 2024-03-11', stderr: ''),
                 new ProcessResult(exitCode: 2, stdout: 'Fatal error: no composer.lock found', stderr: ''),
             );

        $bag     = new FindingBag();
        $auditor = new ComposerAuditor($exec, $bag);
        $result  = $auditor->audit($this->makeCtx());

        $this->assertNull($result);
        $findings = iterator_to_array($bag->all());
        $this->assertCount(1, $findings);
        $this->assertSame('runtime.composer.audit-unavailable', $findings[0]->ruleId);
    }
}
