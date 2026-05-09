<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Rules\Common;

use PhpDoctor\Analysis\Runtime\PhpStan\PhpStanReport;
use PhpDoctor\Analysis\Runtime\PhpStan\PhpStanRunnerInterface;
use PhpDoctor\Core\AnalysisInput;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PhpDoctor\Core\Rule\Severity;
use PhpDoctor\Rules\Common\PhpStanAggregatorRule;
use PHPUnit\Framework\TestCase;

final class PhpStanAggregatorRuleTest extends TestCase
{
    private function makeCtx(string $rootPath = '/fake/project'): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      $rootPath,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );
    }

    private function makeInput(string $rootPath = '/fake/project'): AnalysisInput
    {
        return new AnalysisInput($this->makeCtx($rootPath));
    }

    public function testAppliesToDelegatesToRunnerAvailability(): void
    {
        $runner = $this->createMock(PhpStanRunnerInterface::class);
        $runner->method('isAvailable')->willReturn(true);

        $rule = new PhpStanAggregatorRule($runner);
        $this->assertTrue($rule->appliesTo($this->makeCtx()));
    }

    public function testAppliesToReturnsFalseWhenRunnerUnavailable(): void
    {
        $runner = $this->createMock(PhpStanRunnerInterface::class);
        $runner->method('isAvailable')->willReturn(false);

        $rule = new PhpStanAggregatorRule($runner);
        $this->assertFalse($rule->appliesTo($this->makeCtx()));
    }

    public function testAnalyzeYieldsFindingsFromMessages(): void
    {
        $messages = [
            [
                'file'       => '/some/File.php',
                'line'       => 10,
                'message'    => 'Type missing',
                'identifier' => 'missingType.parameter',
                'ignorable'  => true,
                'tip'        => null,
            ],
            [
                'file'       => '/some/Other.php',
                'line'       => 42,
                'message'    => 'Another problem',
                'identifier' => 'other.issue',
                'ignorable'  => false,
                'tip'        => 'https://phpstan.org/tip',
            ],
        ];
        $report = new PhpStanReport(2, $messages);

        $runner = $this->createMock(PhpStanRunnerInterface::class);
        $runner->method('isAvailable')->willReturn(true);
        $runner->method('run')->willReturn($report);

        $rule     = new PhpStanAggregatorRule($runner);
        $findings = iterator_to_array($rule->analyze($this->makeInput()));

        $this->assertCount(2, $findings);
        $this->assertSame('type-safety', $findings[0]->category->value);
        $this->assertSame('phpstan.missingType.parameter', $findings[0]->ruleId);
        $this->assertSame('type-safety', $findings[1]->category->value);
        $this->assertSame('phpstan.other.issue', $findings[1]->ruleId);
        $this->assertSame('https://phpstan.org/tip', $findings[1]->docUrl);
    }

    public function testAnalyzeYieldsNothingWhenRunnerReturnsNull(): void
    {
        $runner = $this->createMock(PhpStanRunnerInterface::class);
        $runner->method('isAvailable')->willReturn(true);
        $runner->method('run')->willReturn(null);

        $rule     = new PhpStanAggregatorRule($runner);
        $findings = iterator_to_array($rule->analyze($this->makeInput()));

        $this->assertCount(0, $findings);
    }

    public function testSeverityMappingErrorIdentifier(): void
    {
        $messages = [
            [
                'file'       => '/foo.php',
                'line'       => 1,
                'message'    => 'Error',
                'identifier' => 'syntax.error',
                'ignorable'  => false,
                'tip'        => null,
            ],
        ];
        $report = new PhpStanReport(1, $messages);

        $runner = $this->createMock(PhpStanRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $rule     = new PhpStanAggregatorRule($runner);
        $findings = iterator_to_array($rule->analyze($this->makeInput()));

        $this->assertSame(Severity::High, $findings[0]->severity);
    }

    public function testSeverityMappingMissingTypeIdentifier(): void
    {
        $messages = [
            [
                'file'       => '/foo.php',
                'line'       => 1,
                'message'    => 'Missing type',
                'identifier' => 'missingType.return',
                'ignorable'  => true,
                'tip'        => null,
            ],
        ];
        $report = new PhpStanReport(1, $messages);

        $runner = $this->createMock(PhpStanRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $rule     = new PhpStanAggregatorRule($runner);
        $findings = iterator_to_array($rule->analyze($this->makeInput()));

        $this->assertSame(Severity::Medium, $findings[0]->severity);
    }

    public function testSeverityMappingUnknownIdentifierIsLow(): void
    {
        $messages = [
            [
                'file'       => '/foo.php',
                'line'       => 1,
                'message'    => 'Some other issue',
                'identifier' => 'argument.type',
                'ignorable'  => true,
                'tip'        => null,
            ],
        ];
        $report = new PhpStanReport(1, $messages);

        $runner = $this->createMock(PhpStanRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $rule     = new PhpStanAggregatorRule($runner);
        $findings = iterator_to_array($rule->analyze($this->makeInput()));

        $this->assertSame(Severity::Low, $findings[0]->severity);
    }

    public function testSeverityMappingNullIdentifierIsLow(): void
    {
        $messages = [
            [
                'file'       => '/foo.php',
                'line'       => 1,
                'message'    => 'Unknown issue',
                'identifier' => null,
                'ignorable'  => true,
                'tip'        => null,
            ],
        ];
        $report = new PhpStanReport(1, $messages);

        $runner = $this->createMock(PhpStanRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $rule     = new PhpStanAggregatorRule($runner);
        $findings = iterator_to_array($rule->analyze($this->makeInput()));

        $this->assertSame(Severity::Low, $findings[0]->severity);
        $this->assertSame('phpstan.unknown', $findings[0]->ruleId);
    }
}
