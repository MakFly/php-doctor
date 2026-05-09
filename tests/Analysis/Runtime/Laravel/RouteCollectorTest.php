<?php

declare(strict_types=1);

namespace PhpDoctor\Tests\Analysis\Runtime\Laravel;

use PhpDoctor\Analysis\Runtime\Laravel\RouteCollector;
use PhpDoctor\Analysis\Runtime\ProcessExecutorInterface;
use PhpDoctor\Analysis\Runtime\ProcessResult;
use PhpDoctor\Core\Finding\FindingBag;
use PhpDoctor\Core\Project\Framework;
use PhpDoctor\Core\Project\FrameworkContext;
use PHPUnit\Framework\TestCase;

final class RouteCollectorTest extends TestCase
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

    public function testCollectsRoutesFromFixtureJson(): void
    {
        $fixtureJson = file_get_contents(self::$fixtureRoot . '/fixture-routes.json');
        $this->assertNotFalse($fixtureJson);

        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')->willReturn(new ProcessResult(
            exitCode: 0,
            stdout:   $fixtureJson,
            stderr:   '',
        ));

        $bag       = new FindingBag();
        $collector = new RouteCollector($exec, $bag);
        $result    = $collector->collect($this->makeLaravelCtx());

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('home', $result[0]['name']);
        $this->assertCount(0, iterator_to_array($bag->all()));
    }

    public function testReturnsNullForSymfonyFramework(): void
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

    public function testEmitsFindingOnCommandFailure(): void
    {
        $exec = $this->createMock(ProcessExecutorInterface::class);
        $exec->method('run')->willReturn(new ProcessResult(
            exitCode: 1,
            stdout:   '',
            stderr:   'No routes defined',
        ));

        $bag       = new FindingBag();
        $collector = new RouteCollector($exec, $bag);
        $result    = $collector->collect($this->makeLaravelCtx());

        $this->assertNull($result);

        $findings = iterator_to_array($bag->all());
        $this->assertCount(1, $findings);
        $this->assertSame('runtime.laravel.routes-unavailable', $findings[0]->ruleId);
    }
}
