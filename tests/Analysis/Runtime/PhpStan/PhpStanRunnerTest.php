<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Runtime\PhpStan;

use PhpDoctor\Analysis\Runtime\PhpStan\PhpStanRunner;
use PhpDoctor\Analysis\Runtime\ProcessExecutor;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PHPUnit\Framework\TestCase;

final class PhpStanRunnerTest extends TestCase
{
    private static string $fixturesDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesDir = dirname(__DIR__, 3) . '/fixtures/projects';
    }

    private function makeCtx(string $projectName): FrameworkContext
    {
        $root = self::$fixturesDir . '/' . $projectName;
        return new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      $root,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [$root . '/src'],
        );
    }

    public function testIsAvailableTrueWhenBinaryExists(): void
    {
        $bag    = new FindingBag();
        $exec   = new ProcessExecutor();
        $runner = new PhpStanRunner($exec, $bag);

        $ctx = $this->makeCtx('phpstan-available');
        $this->assertTrue($runner->isAvailable($ctx));
    }

    public function testIsAvailableFalseWhenBinaryMissing(): void
    {
        $bag    = new FindingBag();
        $exec   = new ProcessExecutor();
        $runner = new PhpStanRunner($exec, $bag);

        $ctx = $this->makeCtx('phpstan-missing');
        $this->assertFalse($runner->isAvailable($ctx));
    }

    public function testRunDecodesJson(): void
    {
        $bag    = new FindingBag();
        $exec   = new ProcessExecutor();
        $runner = new PhpStanRunner($exec, $bag);

        $ctx    = $this->makeCtx('phpstan-available');
        $report = $runner->run($ctx);

        $this->assertNotNull($report);
        $this->assertSame(0, $report->totalErrors);
        $this->assertSame([], $report->messages);
    }

    public function testRunWithFatalExitEmitsHygieneFinding(): void
    {
        $bag    = new FindingBag();
        $exec   = new ProcessExecutor();
        $runner = new PhpStanRunner($exec, $bag);

        $ctx    = $this->makeCtx('phpstan-fatal');
        $report = $runner->run($ctx);

        $this->assertNull($report);

        $findings = iterator_to_array($bag->all());
        $this->assertCount(1, $findings);
        $this->assertSame('runtime.phpstan.failure', $findings[0]->ruleId);
        $this->assertSame('hygiene', $findings[0]->category->value);
    }

    public function testRunParsesMessagesCorrectly(): void
    {
        $bag    = new FindingBag();
        $exec   = new ProcessExecutor();
        $runner = new PhpStanRunner($exec, $bag);

        $ctx    = $this->makeCtx('phpstan-with-errors');
        $report = $runner->run($ctx);

        $this->assertNotNull($report);
        $this->assertSame(2, $report->totalErrors);
        $this->assertNotEmpty($report->messages);
        $this->assertSame('missingType.parameter', $report->messages[0]['identifier']);
        $this->assertSame('/abs/Foo.php', $report->messages[0]['file']);
        $this->assertSame(12, $report->messages[0]['line']);
    }
}
