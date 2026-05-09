<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Runtime\Laravel;

use PhpDoctor\Analysis\Runtime\Laravel\MigrationCollector;
use PhpDoctor\Analysis\Runtime\ProcessExecutorInterface;
use PhpDoctor\Analysis\Runtime\ProcessResult;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PHPUnit\Framework\TestCase;

final class MigrationCollectorTest extends TestCase
{
    private static string $fixtureRoot;

    public static function setUpBeforeClass(): void
    {
        self::$fixtureRoot = dirname(__DIR__, 3) . '/fixtures/projects/laravel-min';
    }

    private function makeLaravelCtx(): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Laravel,
            rootPath:      self::$fixtureRoot,
            consoleBinary: self::$fixtureRoot . '/artisan',
            composerData:  [],
            sourcePaths:   [],
        );
    }

    public function testParsesTableOutputIntoStructuredArray(): void
    {
        $tableOutput = file_get_contents(self::$fixtureRoot . '/fixture-migrate-status.txt');
        $this->assertNotFalse($tableOutput);

        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')->willReturn(new ProcessResult(
            exitCode: 0,
            stdout:   $tableOutput,
            stderr:   '',
        ));

        $bag       = new FindingBag();
        $collector = new MigrationCollector($exec, $bag);
        $result    = $collector->collect($this->makeLaravelCtx());

        $this->assertIsArray($result);
        $this->assertCount(3, $result);

        $this->assertSame('2014_10_12_000000_create_users_table', $result[0]['name']);
        $this->assertTrue($result[0]['ran']);

        $this->assertSame('2024_01_01_000000_create_jobs_table', $result[2]['name']);
        $this->assertFalse($result[2]['ran']);

        $this->assertCount(0, iterator_to_array($bag->all()));
    }

    public function testEmitsFindingOnCommandFailure(): void
    {
        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')->willReturn(new ProcessResult(
            exitCode: 1,
            stdout:   '',
            stderr:   'Could not connect to database',
        ));

        $bag       = new FindingBag();
        $collector = new MigrationCollector($exec, $bag);
        $result    = $collector->collect($this->makeLaravelCtx());

        $this->assertNull($result);

        $findings = iterator_to_array($bag->all());
        $this->assertCount(1, $findings);
        $this->assertSame('runtime.laravel.migrations-unavailable', $findings[0]->ruleId);
    }

    public function testEmitsFindingWhenOutputNotParseable(): void
    {
        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')->willReturn(new ProcessResult(
            exitCode: 0,
            stdout:   'Nothing to show.',
            stderr:   '',
        ));

        $bag       = new FindingBag();
        $collector = new MigrationCollector($exec, $bag);
        $result    = $collector->collect($this->makeLaravelCtx());

        $this->assertNull($result);

        $findings = iterator_to_array($bag->all());
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('not parseable', $findings[0]->message);
    }

    public function testReturnsNullForNonLaravelFramework(): void
    {
        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->expects($this->never())->method('run');

        $ctx = new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      self::$fixtureRoot,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );

        $bag       = new FindingBag();
        $collector = new MigrationCollector($exec, $bag);
        $this->assertNull($collector->collect($ctx));
    }
}
