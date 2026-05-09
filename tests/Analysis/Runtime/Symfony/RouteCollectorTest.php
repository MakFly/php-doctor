<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Runtime\Symfony;

use PhpDoctor\Analysis\Runtime\ProcessExecutorInterface;
use PhpDoctor\Analysis\Runtime\ProcessResult;
use PhpDoctor\Analysis\Runtime\Symfony\RouteCollector;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PHPUnit\Framework\TestCase;

final class RouteCollectorTest extends TestCase
{
    private static string $fixtureRoot;

    public static function setUpBeforeClass(): void
    {
        self::$fixtureRoot = dirname(__DIR__, 3) . '/fixtures/projects/symfony-min';
    }

    private function makeSymfonyCtx(): FrameworkContext
    {
        return new FrameworkContext(
            framework:      Framework::Symfony,
            rootPath:       self::$fixtureRoot,
            consoleBinary:  self::$fixtureRoot . '/bin/console',
            composerData:   [],
            sourcePaths:    [],
        );
    }

    private function makeGenericCtx(): FrameworkContext
    {
        return new FrameworkContext(
            framework:     Framework::Generic,
            rootPath:      self::$fixtureRoot,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );
    }

    public function testCollectsRoutesFromFixtureJson(): void
    {
        $fixtureJson = file_get_contents(self::$fixtureRoot . '/fixture-router.json');
        $this->assertNotFalse($fixtureJson);

        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')->willReturn(new ProcessResult(
            exitCode: 0,
            stdout:   $fixtureJson,
            stderr:   '',
        ));

        $bag       = new FindingBag();
        $collector = new RouteCollector($exec, $bag);
        $result    = $collector->collect($this->makeSymfonyCtx());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('app_index', $result);
        $this->assertSame('/', $result['app_index']['path']);
        $this->assertCount(0, iterator_to_array($bag->all()));
    }

    public function testReturnsNullForGenericFramework(): void
    {
        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->expects($this->never())->method('run');

        $bag       = new FindingBag();
        $collector = new RouteCollector($exec, $bag);
        $result    = $collector->collect($this->makeGenericCtx());

        $this->assertNull($result);
        $this->assertCount(0, iterator_to_array($bag->all()));
    }

    public function testEmitsFindingOnCommandFailure(): void
    {
        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')->willReturn(new ProcessResult(
            exitCode: 1,
            stdout:   '',
            stderr:   'Some error from console',
        ));

        $bag       = new FindingBag();
        $collector = new RouteCollector($exec, $bag);
        $result    = $collector->collect($this->makeSymfonyCtx());

        $this->assertNull($result);

        $findings = iterator_to_array($bag->all());
        $this->assertCount(1, $findings);
        $this->assertSame('runtime.symfony.routes-unavailable', $findings[0]->ruleId);
        $this->assertStringContainsString('Some error from console', $findings[0]->fixHint ?? '');
    }

    public function testReturnsNullWhenConsoleIsNull(): void
    {
        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->expects($this->never())->method('run');

        $ctx = new FrameworkContext(
            framework:     Framework::Symfony,
            rootPath:      self::$fixtureRoot,
            consoleBinary: null,
            composerData:  [],
            sourcePaths:   [],
        );

        $bag       = new FindingBag();
        $collector = new RouteCollector($exec, $bag);
        $this->assertNull($collector->collect($ctx));
    }
}
